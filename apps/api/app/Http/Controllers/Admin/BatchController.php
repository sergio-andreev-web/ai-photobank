<?php

namespace App\Http\Controllers\Admin;

use App\Contracts\Storage\PhotoStorageInterface;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\Batch;
use App\Models\Image;
use App\Services\Processing\BatchStatusUpdater;
use App\Services\Processing\ProcessingPipeline;
use App\Support\Api;
use App\Support\Presenters\BatchPresenter;
use App\Support\Presenters\ImagePresenter;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Throwable;

/**
 * contracts/public-api.openapi.yaml — batches/upload (ТЗ §26, §27, §49; ADR-0005).
 *
 * Upload only saves the original, creates the image (status=uploaded) and
 * queues its first processing task in the same transaction; metadata,
 * variants and AI analysis run in the background (§28, §81) — see
 * App\Services\Processing\ProcessingPipeline.
 */
class BatchController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'page' => ['integer', 'min:1'],
            'per_page' => ['integer', 'min:1', 'max:100'],
            'status' => ['string', 'in:created,uploading,processing,completed,completed_with_errors,failed'],
            'sort' => ['string', 'in:-created_at,created_at'],
        ]);

        $page = (int) ($validated['page'] ?? 1);
        $perPage = (int) ($validated['per_page'] ?? 50);
        $sort = $validated['sort'] ?? '-created_at';

        $query = Batch::query();

        if (isset($validated['status'])) {
            $query->where('status', $validated['status']);
        }

        $direction = Str::startsWith($sort, '-') ? 'desc' : 'asc';
        $query->orderBy('created_at', $direction)->orderBy('id', $direction);

        $total = (clone $query)->count();
        $batches = $query->forPage($page, $perPage)->get();

        return response()->json([
            'data' => $batches->map(fn (Batch $batch) => BatchPresenter::toArray($batch))->all(),
            'meta' => Api::paginationMeta($page, $perPage, $total),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['nullable', 'string', 'max:255'],
        ]);

        $batch = Batch::create([
            'name' => $validated['name'] ?? null,
            'status' => 'created',
            'total_images' => 0,
            'processed_images' => 0,
            'failed_images' => 0,
            'created_by' => $request->user()->id,
        ]);

        return response()->json(BatchPresenter::toArray($batch), 201, [
            'Location' => "/api/admin/batches/{$batch->id}",
        ]);
    }

    public function show(Request $request, int $batch): JsonResponse
    {
        return response()->json(BatchPresenter::toArray($this->findBatch($batch)));
    }

    public function uploadImage(Request $request, int $batch): JsonResponse
    {
        $batchModel = $this->findBatch($batch);

        if (! in_array($batchModel->status, ['created', 'uploading'], true)) {
            throw new ApiException('BATCH_NOT_ACCEPTING_UPLOADS', 409, 'Batch is not accepting uploads', [
                'status' => $batchModel->status,
            ]);
        }

        $file = $request->file('image');

        // PHP marks an upload over upload_max_filesize as failed before Laravel sees it, and the
        // `file` rule below would report that as a generic 422 — map it to 413 first (ADR-0005).
        // Any other failed upload stays a 422 VALIDATION_ERROR.
        if ($file instanceof UploadedFile && in_array($file->getError(), [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)) {
            throw new ApiException('FILE_TOO_LARGE', 413, 'Uploaded file exceeds the maximum allowed size');
        }

        $request->validate(['image' => ['required', 'file']]);

        $maxBytes = (int) config('photobank.upload.max_bytes');
        if ($file->getSize() > $maxBytes) {
            throw new ApiException('FILE_TOO_LARGE', 413, 'Uploaded file exceeds the maximum allowed size');
        }

        $allowedMimeTypes = config('photobank.upload.allowed_mime_types');
        $detectedMime = $file->getMimeType();
        if (! isset($allowedMimeTypes[$detectedMime])) {
            throw new ApiException('UNSUPPORTED_MEDIA_TYPE', 415, 'Unsupported image type', [
                'detected_mime_type' => $detectedMime,
            ]);
        }
        $extension = $allowedMimeTypes[$detectedMime];

        $sha256 = hash_file('sha256', $file->getRealPath());

        $existing = Image::withTrashed()->where('sha256', $sha256)->first();
        if ($existing !== null) {
            throw new ApiException('IMAGE_ALREADY_EXISTS', 409, 'Image already exists', [
                'existing_image_id' => $existing->id,
                // In the trash: the admin can restore it instead (ADR-0013).
                'existing_image_deleted' => $existing->trashed(),
            ]);
        }

        $uploadedAt = now();
        $storageKey = sprintf(
            'originals/%s/%s/%d/%s.%s',
            $uploadedAt->format('Y'),
            $uploadedAt->format('m'),
            $batchModel->id,
            (string) Str::uuid(),
            $extension,
        );

        $storage = app(PhotoStorageInterface::class);
        $stream = fopen($file->getRealPath(), 'rb');
        try {
            $storage->put($storageKey, $stream);
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }

        // The INSERT (together with the image's first processing task, whose queue message is
        // only sent after commit) runs in its own transaction (a savepoint when the caller already holds one),
        // so a unique violation is rolled back before the winner is looked up below: under
        // Postgres a transaction that failed one statement refuses every further statement,
        // even a plain SELECT, until it is rolled back. The batch's created->uploading bump stays
        // outside it — the two writes don't need to be atomic (a crash between them self-heals on
        // the next request/complete() call, since the state machine treats both statuses the same).
        try {
            $image = DB::transaction(function () use ($batchModel, $file, $storageKey, $detectedMime, $extension, $sha256, $request): Image {
                $image = Image::create([
                    'batch_id' => $batchModel->id,
                    'original_name' => $this->sanitizeFilename($file->getClientOriginalName()),
                    'storage_key' => $storageKey,
                    'preview_key' => null,
                    'thumbnail_key' => null,
                    'mime_type' => $detectedMime,
                    'extension' => $extension,
                    'width' => null,
                    'height' => null,
                    'file_size' => $file->getSize(),
                    'sha256' => $sha256,
                    'status' => 'uploaded',
                    'created_by' => $request->user()->id,
                ]);

                app(ProcessingPipeline::class)->start($image);

                return $image;
            });
        } catch (UniqueConstraintViolationException) {
            // Lost a race: another request inserted the same sha256 between our duplicate
            // check above and this INSERT. The unique index on images.sha256 is the actual
            // source of truth here (ADR-0005: "unique index guarantees one successful
            // upload, the second gets 409").
            $storage->delete($storageKey);

            $winner = Image::withTrashed()->where('sha256', $sha256)->first();

            throw new ApiException('IMAGE_ALREADY_EXISTS', 409, 'Image already exists', [
                'existing_image_id' => $winner?->id,
                'existing_image_deleted' => (bool) $winner?->trashed(),
            ]);
        } catch (Throwable $e) {
            $storage->delete($storageKey);
            throw $e;
        }

        if ($batchModel->status === 'created') {
            $batchModel->status = 'uploading';
            $batchModel->save();
        }

        return response()->json(ImagePresenter::summary($image), 201, [
            'Location' => "/api/admin/images/{$image->id}",
        ]);
    }

    public function complete(Request $request, int $batch, BatchStatusUpdater $batches): JsonResponse
    {
        $batchModel = $batches->complete($this->findBatch($batch)->id);

        return response()->json(BatchPresenter::toArray($batchModel));
    }

    private function findBatch(int $id): Batch
    {
        $batch = Batch::find($id);

        if ($batch === null) {
            throw new ApiException('BATCH_NOT_FOUND', 404, 'Batch not found');
        }

        return $batch;
    }

    private function sanitizeFilename(?string $name): string
    {
        $name = basename($name ?? '');
        $name = preg_replace('/[\x00-\x1F\x7F]/u', '', $name) ?? '';
        $name = trim($name);

        if ($name === '') {
            $name = 'file';
        }

        return mb_substr($name, 0, 1024);
    }
}

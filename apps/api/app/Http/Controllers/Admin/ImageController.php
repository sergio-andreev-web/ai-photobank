<?php

namespace App\Http\Controllers\Admin;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\Image;
use App\Services\Processing\BatchStatusUpdater;
use App\Services\Processing\ProcessingPipeline;
use App\Support\Api;
use App\Support\Presenters\ImagePresenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * contracts/public-api.openapi.yaml — images CRUD (ТЗ §50, §53, §54; ADR-0002, ADR-0006)
 * and the processing actions reprocess (§76) / retry (§47, ADR-0003), whose state
 * transitions live in App\Services\Processing\ProcessingPipeline.
 */
class ImageController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'page' => ['integer', 'min:1'],
            'per_page' => ['integer', 'min:1', 'max:100'],
            'status' => ['string', 'in:uploaded,queued,processing,ready,failed'],
            'batch_id' => ['integer', 'min:1'],
            'tag_ids' => ['array', 'max:20'],
            'tag_ids.*' => ['integer', 'min:1'],
            'created_from' => ['date'],
            'created_to' => ['date'],
            'sort' => ['string', 'in:-created_at,created_at'],
        ]);

        $page = (int) ($validated['page'] ?? 1);
        $perPage = (int) ($validated['per_page'] ?? 50);
        $sort = $validated['sort'] ?? '-created_at';

        $query = Image::query()->with('tags');

        if (isset($validated['status'])) {
            $query->where('status', $validated['status']);
        }

        if (isset($validated['batch_id'])) {
            $query->where('batch_id', $validated['batch_id']);
        }

        foreach (array_unique($validated['tag_ids'] ?? []) as $tagId) {
            $query->whereHas('tags', fn ($q) => $q->where('tags.id', $tagId));
        }

        if (isset($validated['created_from'])) {
            $query->where('created_at', '>=', $validated['created_from']);
        }

        if (isset($validated['created_to'])) {
            $query->where('created_at', '<', $validated['created_to']);
        }

        $query->orderBy('created_at', Str::startsWith($sort, '-') ? 'desc' : 'asc')
            ->orderBy('id', Str::startsWith($sort, '-') ? 'desc' : 'asc');

        $total = (clone $query)->count();
        $images = $query->forPage($page, $perPage)->get();

        return response()->json([
            'data' => $images->map(fn (Image $image) => ImagePresenter::summary($image))->all(),
            'meta' => Api::paginationMeta($page, $perPage, $total),
        ]);
    }

    public function show(Request $request, int $image): JsonResponse
    {
        return response()->json(ImagePresenter::detail($this->findImage($image)));
    }

    public function update(Request $request, int $image): JsonResponse
    {
        $imageModel = $this->findImage($image);

        $unknown = array_diff(array_keys($request->all()), ['original_name']);
        if ($unknown !== []) {
            throw ValidationException::withMessages([
                '_unexpected' => __('validation.unexpected_fields', ['fields' => implode(', ', $unknown)]),
            ]);
        }

        $validated = $request->validate([
            'original_name' => ['required', 'string', 'min:1', 'max:1024'],
        ]);

        $imageModel->original_name = $validated['original_name'];
        $imageModel->save();

        return response()->json(ImagePresenter::detail($imageModel->fresh([
            'tags', 'imageTags.tag', 'analyses', 'processingTasks',
        ])));
    }

    public function destroy(Request $request, int $image, BatchStatusUpdater $batches): Response
    {
        $imageModel = $this->findImage($image);
        $imageModel->delete();

        // Deleted images leave the batch counters (state-machines.md), which may finish the batch.
        $batches->refresh($imageModel->batch_id);

        return response()->noContent();
    }

    public function reprocess(Request $request, int $image, ProcessingPipeline $pipeline): JsonResponse
    {
        $pipeline->reprocess($this->findImage($image)->id);

        return response()->json(ImagePresenter::detail($this->findImage($image)), 202);
    }

    public function retry(Request $request, int $image, ProcessingPipeline $pipeline): JsonResponse
    {
        $pipeline->retry($this->findImage($image)->id);

        return response()->json(ImagePresenter::detail($this->findImage($image)), 202);
    }

    private function findImage(int $id): Image
    {
        $image = Image::with(['tags', 'imageTags.tag', 'analyses', 'processingTasks'])->find($id);

        if ($image === null) {
            throw new ApiException('IMAGE_NOT_FOUND', 404, 'Image not found');
        }

        return $image;
    }
}

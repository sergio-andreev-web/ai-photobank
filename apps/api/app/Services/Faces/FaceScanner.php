<?php

namespace App\Services\Faces;

use App\Contracts\Ai\AiClientInterface;
use App\Contracts\Ai\Exceptions\AiException;
use App\Contracts\Storage\PhotoStorageInterface;
use App\Models\Face;
use App\Models\Image;
use App\Services\Processing\EmbeddingVersion;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Finds the faces of an image once per model revision and labels them (ADR-0015). Runs as
 * its own queued job after the image became ready: a failure here never fails the image.
 */
final readonly class FaceScanner
{
    public function __construct(
        private AiClientInterface $ai,
        private PhotoStorageInterface $storage,
        private FaceMatcher $matcher,
    ) {}

    /**
     * @return bool false when faces are unavailable on the AI side (disabled or no weights).
     *
     * @throws AiException Retryable AI failures, for the job to retry.
     */
    public function scan(Image $image): bool
    {
        if ($image->trashed()) {
            return true;
        }
        if ($image->faces_revision !== null) {
            // Same image, same model: the faces are known; only who they are may have changed.
            $this->matcher->rematch([$image->id]);

            return true;
        }

        $key = $image->preview_key ?? $image->storage_key;
        if (! $this->storage->exists($key)) {
            Log::warning('faces.scan.no_image_copy', ['image_id' => $image->id]);

            return true;
        }

        try {
            $result = $this->ai->detectFaces($this->storage->get($key), (string) Str::uuid());
        } catch (AiException $e) {
            if ($e->errorCode === 'MODEL_NOT_READY' && ($e->details['stage'] ?? null) === 'faces_unavailable') {
                Log::info('faces.scan.unavailable', ['image_id' => $image->id]);

                return false;
            }
            throw $e;
        }

        DB::transaction(function () use ($image, $result): void {
            $locked = Image::withTrashed()->lockForUpdate()->find($image->id);
            if ($locked === null || $locked->faces_revision !== null) {
                return; // a concurrent job was first
            }
            // A rescan (another model revision, or after its person was deleted): the old faces go,
            // vectors of different revisions are not comparable (§77).
            Face::where('image_id', $image->id)->delete();
            foreach ($result->faces as $detected) {
                [$status, $personId, $similarity] = $this->matcher->classify($detected->embedding, $result->modelRevision);
                Face::create([
                    'image_id' => $image->id,
                    'person_id' => $personId,
                    'box_x' => $detected->x,
                    'box_y' => $detected->y,
                    'box_width' => $detected->width,
                    'box_height' => $detected->height,
                    'size_px' => (int) round(min($detected->width * $result->imageWidth, $detected->height * $result->imageHeight)),
                    'score' => $detected->score,
                    'model_revision' => $result->modelRevision,
                    'status' => $status,
                    'similarity' => $similarity,
                    'rejected_person_ids' => [],
                    'embedding' => EmbeddingVersion::toVectorLiteral($detected->embedding),
                ]);
            }
            $locked->faces_revision = $result->modelRevision;
            $locked->save();
        });

        Log::info('faces.scan.done', ['image_id' => $image->id, 'faces' => count($result->faces)]);

        return true;
    }
}

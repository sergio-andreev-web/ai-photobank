<?php

namespace App\Support\Presenters;

use App\Models\Image;
use App\Models\ImageAnalysis;
use App\Models\ImageTag;
use App\Models\ProcessingTask;
use App\Support\Api;

/**
 * contracts/public-api.openapi.yaml#/components/schemas/ImageSummary and #/ImageDetail.
 *
 * storage_key/preview_key/thumbnail_key never leave this presenter (ТЗ §11, §57) — only the
 * `/api/media/...` URLs derived from them are exposed.
 */
final class ImagePresenter
{
    /**
     * @return array<string, mixed>
     */
    public static function summary(Image $image): array
    {
        return [
            'id' => $image->id,
            'batch_id' => $image->batch_id,
            'original_name' => $image->original_name,
            'status' => $image->status,
            'tags' => $image->tags->map(fn ($tag) => TagPresenter::ref($tag))->all(),
            'urls' => self::urls($image),
            'created_at' => Api::dateTime($image->created_at),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function detail(Image $image): array
    {
        /** @var ImageAnalysis|null $latestAnalysis */
        // History rows can share a timestamp (reprocess within the same second); the higher id wins.
        $latestAnalysis = $image->analyses->sortBy([['created_at', 'desc'], ['id', 'desc']])->first();

        return [
            'id' => $image->id,
            'batch_id' => $image->batch_id,
            'original_name' => $image->original_name,
            'status' => $image->status,
            'urls' => self::urls($image),
            'created_at' => Api::dateTime($image->created_at),
            'updated_at' => Api::dateTime($image->updated_at),
            'mime_type' => $image->mime_type,
            'extension' => $image->extension,
            'width' => $image->width,
            'height' => $image->height,
            'file_size' => $image->file_size,
            'sha256' => $image->sha256,
            'caption' => $image->caption,
            'caption_edited' => (bool) $image->caption_edited,
            'ocr_text' => $image->ocr_text,
            'error_code' => $image->error_code,
            'error_message' => $image->error_message,
            'image_tags' => $image->imageTags->map(fn (ImageTag $imageTag) => self::imageTag($imageTag))->all(),
            'latest_analysis' => $latestAnalysis ? self::analysis($latestAnalysis) : null,
            'processing_tasks' => self::latestTaskPerType($image),
        ];
    }

    /**
     * @return array{thumbnail: string|null, preview: string|null, original: string}
     */
    public static function urls(Image $image): array
    {
        return [
            'thumbnail' => $image->thumbnail_key ? self::mediaUrl($image->id, 'thumbnail') : null,
            'preview' => $image->preview_key ? self::mediaUrl($image->id, 'preview') : null,
            'original' => self::mediaUrl($image->id, 'original'),
        ];
    }

    private static function mediaUrl(int $imageId, string $variant): string
    {
        return "/api/media/images/{$imageId}/{$variant}";
    }

    /**
     * @return array<string, mixed>
     */
    public static function imageTag(ImageTag $imageTag): array
    {
        return [
            'tag' => TagPresenter::ref($imageTag->tag),
            'source' => $imageTag->source,
            'confidence' => $imageTag->confidence !== null ? (float) $imageTag->confidence : null,
            'locked' => $imageTag->locked,
            'created_at' => Api::dateTime($imageTag->created_at),
            'updated_at' => Api::dateTime($imageTag->updated_at),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function analysis(ImageAnalysis $analysis): array
    {
        return [
            'id' => $analysis->id,
            'vision_model' => $analysis->vision_model,
            'vision_model_version' => $analysis->vision_model_version,
            'embedding_model' => $analysis->embedding_model,
            'embedding_model_version' => $analysis->embedding_model_version,
            'prompt_version' => $analysis->prompt_version,
            'caption' => $analysis->caption,
            'concepts' => $analysis->concepts ?? [],
            'ocr' => $analysis->ocr ?? [],
            'processing_ms' => $analysis->processing_ms,
            'created_at' => Api::dateTime($analysis->created_at),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function latestTaskPerType(Image $image): array
    {
        return $image->processingTasks
            ->sortByDesc(fn (ProcessingTask $task) => $task->id)
            ->unique('type')
            ->values()
            ->map(fn (ProcessingTask $task) => self::processingTask($task))
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private static function processingTask(ProcessingTask $task): array
    {
        return [
            'type' => $task->type,
            'status' => $task->status,
            'attempts' => $task->attempts,
            'max_attempts' => $task->max_attempts,
            'error_code' => $task->error_code,
            'error_message' => $task->error_message,
            'started_at' => Api::dateTime($task->started_at),
            'finished_at' => Api::dateTime($task->finished_at),
        ];
    }
}

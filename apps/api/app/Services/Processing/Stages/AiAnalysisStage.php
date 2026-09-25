<?php

namespace App\Services\Processing\Stages;

use App\Contracts\Ai\AiClientInterface;
use App\Contracts\Ai\Data\ImageAnalysisResult;
use App\Contracts\Ai\Data\OcrItem;
use App\Contracts\Storage\PhotoStorageInterface;
use App\Models\Image;
use App\Models\ImageAnalysis;
use App\Models\ImageEmbedding;
use App\Services\Processing\EmbeddingVersion;
use App\Services\Processing\ProcessingStage;
use App\Services\Processing\ProcessingState;
use App\Services\Processing\StageFailure;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * `ai_analysis` (§29, §31): sends the preview (the reduced AI copy, ADR-0005)
 * to POST /v1/images/analyze and persists one new image_analyses row per run
 * with the raw response (§22, §76), caption/OCR on the image and the image
 * embedding (§20, ADR-0009). Earlier analyses are never touched.
 */
final readonly class AiAnalysisStage implements ProcessingStage
{
    public function __construct(
        private AiClientInterface $ai,
        private PhotoStorageInterface $storage,
    ) {}

    public function execute(Image $image): ImageAnalysisResult
    {
        $key = $image->preview_key ?? $image->storage_key;
        if (! $this->storage->exists($key)) {
            throw new StageFailure(
                ProcessingState::ERROR_INTERNAL,
                'Image copy for AI analysis is missing',
                retryable: false,
            );
        }

        $requestId = (string) Str::uuid();
        $startedAt = microtime(true);

        Log::info('processing.ai.analyze.request', ['image_id' => $image->id, 'request_id' => $requestId]);

        try {
            $result = $this->ai->analyzeImage($this->storage->get($key), $requestId);
        } finally {
            Log::info('processing.ai.analyze.response', [
                'image_id' => $image->id,
                'request_id' => $requestId,
                'latency_ms' => (int) round((microtime(true) - $startedAt) * 1000),
                'ok' => isset($result),
            ]);
        }

        return $result;
    }

    /**
     * @param  ImageAnalysisResult  $outcome
     */
    public function persist(Image $image, mixed $outcome): void
    {
        $raw = $outcome->toArray();

        ImageAnalysis::create([
            'image_id' => $image->id,
            'vision_model' => $outcome->models->vision,
            'vision_model_version' => $outcome->models->visionRevision,
            'embedding_model' => $outcome->models->embedding,
            'embedding_model_version' => $outcome->models->embeddingRevision,
            'prompt_version' => $outcome->promptVersion,
            'caption' => $outcome->caption,
            'concepts' => $raw['concepts'],
            'ocr' => $raw['ocr'],
            'raw_response' => $raw,
            'processing_ms' => $outcome->processingMs,
        ]);

        $ocrText = implode("\n", array_map(static fn (OcrItem $item): string => $item->text, $outcome->ocr));
        $image->caption = $outcome->caption;
        $image->ocr_text = $ocrText === '' ? null : $ocrText;
        $image->save();

        $this->storeEmbedding($image, $outcome);
    }

    private function storeEmbedding(Image $image, ImageAnalysisResult $result): void
    {
        $modelKey = EmbeddingVersion::modelKey($result->models->embedding);
        $modelVersion = (string) $result->models->embeddingRevision;
        $active = EmbeddingVersion::isActive($modelKey, $modelVersion);

        if ($active) {
            // One active row per image (partial unique index); older rows stay for history (ADR-0009).
            ImageEmbedding::where('image_id', $image->id)->where('active', true)->update(['active' => false]);
        } else {
            Log::warning('processing.embedding.inactive_version', [
                'image_id' => $image->id,
                'model_key' => $modelKey,
                'model_version' => $modelVersion,
            ]);
        }

        ImageEmbedding::create([
            'image_id' => $image->id,
            'model_key' => $modelKey,
            'model_version' => $modelVersion,
            'embedding' => EmbeddingVersion::toVectorLiteral($result->embedding),
            'active' => $active,
        ]);
    }
}

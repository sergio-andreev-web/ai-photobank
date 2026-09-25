<?php

namespace App\Services\Ai;

use App\Contracts\Ai\AiClientInterface;
use App\Contracts\Ai\Data\AiHealthResult;
use App\Contracts\Ai\Data\AiModels;
use App\Contracts\Ai\Data\Concept;
use App\Contracts\Ai\Data\ConceptType;
use App\Contracts\Ai\Data\ImageAnalysisResult;
use App\Contracts\Ai\Data\OcrItem;
use App\Contracts\Ai\Data\TextEmbeddingsResult;

/**
 * Deterministic AiClientInterface implementation for tests and GPU-less local
 * dev (ТЗ §7; AGENT A Definition of Done: "Backend должен полностью работать
 * с FakeAiClient без реальной модели"). Bound when config('ai.driver') === 'fake'.
 *
 * Every output is a pure function of its input so assertions can rely on
 * exact values instead of "roughly matches".
 */
final class FakeAiClient implements AiClientInterface
{
    private const string VISION_MODEL = 'fake/qwen3-vl-stub';

    private const string VISION_REVISION = 'fake-vision-rev-1';

    private const string EMBEDDING_MODEL = 'fake/siglip2-stub';

    private const string EMBEDDING_REVISION = 'fake-embedding-rev-1';

    private const string PROMPT_VERSION = 'fake-analysis-v1';

    public function analyzeImage(string $imageContents, string $requestId): ImageAnalysisResult
    {
        return new ImageAnalysisResult(
            requestId: $requestId,
            caption: 'Fake caption for deterministic test fixture.',
            ocr: [
                new OcrItem(text: 'FAKE OCR TEXT', confidence: 0.9),
            ],
            concepts: [
                new Concept(type: ConceptType::Object, label: 'fake object', confidence: 0.95),
                new Concept(type: ConceptType::Scene, label: 'fake scene', confidence: 0.85),
            ],
            embedding: $this->deterministicEmbedding('image:'.hash('sha256', $imageContents)),
            models: new AiModels(
                vision: self::VISION_MODEL,
                embedding: self::EMBEDDING_MODEL,
                visionRevision: self::VISION_REVISION,
                embeddingRevision: self::EMBEDDING_REVISION,
            ),
            promptVersion: self::PROMPT_VERSION,
            processingMs: 1,
        );
    }

    public function createTextEmbeddings(array $texts, string $requestId): TextEmbeddingsResult
    {
        return new TextEmbeddingsResult(
            model: self::EMBEDDING_MODEL,
            revision: self::EMBEDDING_REVISION,
            embeddings: array_map(
                fn (string $text): array => $this->deterministicEmbedding('text:'.$text),
                $texts,
            ),
        );
    }

    public function health(): AiHealthResult
    {
        return new AiHealthResult(
            status: 'ok',
            device: 'cpu',
            models: new AiModels(
                vision: self::VISION_MODEL,
                embedding: self::EMBEDDING_MODEL,
                visionRevision: self::VISION_REVISION,
                embeddingRevision: self::EMBEDDING_REVISION,
            ),
        );
    }

    /**
     * L2-normalized 1152-dim vector seeded from $seed. Same seed -> same
     * vector, always; different seeds -> (almost certainly) different vectors.
     *
     * @return list<float>
     */
    private function deterministicEmbedding(string $seed): array
    {
        $dimensions = ImageAnalysisResult::EMBEDDING_DIMENSIONS;
        $raw = [];
        $state = $seed;

        for ($i = 0; $i < $dimensions; $i++) {
            $state = hash('sha256', $state.':'.$i);
            // Map the first 8 hex chars to a signed value in [-1, 1).
            $chunk = (int) hexdec(substr($state, 0, 8));
            $raw[] = ($chunk / 0x7FFFFFFF) - 1.0;
        }

        $norm = sqrt(array_sum(array_map(static fn (float $v): float => $v * $v, $raw)));
        if ($norm === 0.0) {
            $norm = 1.0;
        }

        return array_map(static fn (float $v): float => $v / $norm, $raw);
    }
}

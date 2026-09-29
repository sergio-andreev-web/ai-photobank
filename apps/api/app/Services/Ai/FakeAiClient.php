<?php

namespace App\Services\Ai;

use App\Contracts\Ai\AiClientInterface;
use App\Contracts\Ai\Data\AiHealthResult;
use App\Contracts\Ai\Data\AiModels;
use App\Contracts\Ai\Data\Concept;
use App\Contracts\Ai\Data\ConceptType;
use App\Contracts\Ai\Data\DetectedFace;
use App\Contracts\Ai\Data\FaceDetectionResult;
use App\Contracts\Ai\Data\ImageAnalysisResult;
use App\Contracts\Ai\Data\OcrItem;
use App\Contracts\Ai\Data\TextEmbeddingsResult;
use Throwable;

/**
 * Deterministic AiClientInterface implementation for tests and GPU-less local
 * dev (ТЗ §7, §72; AGENT A Definition of Done: "Backend должен полностью работать
 * с FakeAiClient без реальной модели"). Bound when config('ai.driver') === 'fake'.
 *
 * Every output is a pure function of its input so assertions can rely on
 * exact values instead of "roughly matches". Tests can script failures
 * (AiException for 5xx/429/4xx/unavailable, InvalidAiResponseException for a
 * 200 that breaks the schema) and override concepts; scripted failures are
 * consumed one per call, in order, after which calls succeed again.
 */
final class FakeAiClient implements AiClientInterface
{
    public const string VISION_MODEL = 'fake/qwen3-vl-stub';

    public const string VISION_REVISION = 'fake-vision-rev-1';

    public const string EMBEDDING_MODEL = 'fake/siglip2-stub';

    public const string EMBEDDING_REVISION = 'fake-embedding-rev-1';

    public const string PROMPT_VERSION = 'fake-analysis-v1';

    /** @var list<Throwable> */
    private array $analyzeFailures = [];

    /** @var list<Throwable> */
    private array $textEmbeddingFailures = [];

    /** @var list<Concept>|null */
    private ?array $concepts = null;

    /** @var list<string> Request IDs of every analyzeImage() call, failed ones included. */
    private array $analyzeRequestIds = [];

    public function analyzeImage(string $imageContents, string $requestId): ImageAnalysisResult
    {
        $this->analyzeRequestIds[] = $requestId;

        if ($this->analyzeFailures !== []) {
            throw array_shift($this->analyzeFailures);
        }

        return new ImageAnalysisResult(
            requestId: $requestId,
            caption: 'Fake caption for deterministic test fixture.',
            ocr: [
                new OcrItem(text: 'FAKE OCR TEXT', confidence: 0.9),
            ],
            concepts: $this->concepts ?? [
                new Concept(type: ConceptType::Object, label: 'fake object', confidence: 0.95),
                new Concept(type: ConceptType::Scene, label: 'fake scene', confidence: 0.85),
            ],
            embedding: $this->deterministicEmbedding('image:'.hash('sha256', $imageContents)),
            models: $this->models(),
            promptVersion: self::PROMPT_VERSION,
            processingMs: 1,
        );
    }

    public function createTextEmbeddings(array $texts, string $requestId): TextEmbeddingsResult
    {
        if ($this->textEmbeddingFailures !== []) {
            throw array_shift($this->textEmbeddingFailures);
        }

        return new TextEmbeddingsResult(
            model: self::EMBEDDING_MODEL,
            revision: self::EMBEDDING_REVISION,
            embeddings: array_map(
                fn (string $text): array => $this->deterministicEmbedding('text:'.$text),
                $texts,
            ),
        );
    }

    public const string FACE_MODEL_REVISION = 'fake-faces-rev-1';

    /** @var array<string, list<DetectedFace>> faces per image content hash; default: no faces */
    private array $faces = [];

    /** @var list<string> */
    public array $faceRequests = [];

    private ?Throwable $faceFailure = null;

    public function failFacesWith(Throwable $failure): self
    {
        $this->faceFailure = $failure;

        return $this;
    }

    public function detectFaces(string $imageContents, string $requestId): FaceDetectionResult
    {
        $this->faceRequests[] = $requestId;
        if ($this->faceFailure !== null) {
            throw $this->faceFailure;
        }

        return new FaceDetectionResult(
            faces: $this->faces[hash('sha256', $imageContents)] ?? [],
            modelName: 'fake-faces',
            modelRevision: self::FACE_MODEL_REVISION,
            imageWidth: 1000,
            imageHeight: 1000,
        );
    }

    /**
     * The faces returned for an image with these bytes (ADR-0015 tests).
     *
     * @param  list<DetectedFace>  $faces
     */
    public function withFacesFor(string $imageContents, array $faces): self
    {
        $this->faces[hash('sha256', $imageContents)] = $faces;

        return $this;
    }

    public function health(): AiHealthResult
    {
        return new AiHealthResult(
            status: 'ok',
            device: 'cpu',
            models: $this->models(),
        );
    }

    /**
     * The next count($errors) analyzeImage() calls throw these, in order.
     */
    public function failAnalyzeWith(Throwable ...$errors): self
    {
        array_push($this->analyzeFailures, ...$errors);

        return $this;
    }

    /**
     * The next count($errors) createTextEmbeddings() calls throw these, in order.
     */
    public function failTextEmbeddingsWith(Throwable ...$errors): self
    {
        array_push($this->textEmbeddingFailures, ...$errors);

        return $this;
    }

    /**
     * @param  list<Concept>  $concepts  Returned by every later successful analyzeImage().
     */
    public function withConcepts(array $concepts): self
    {
        $this->concepts = $concepts;

        return $this;
    }

    public function analyzeCallCount(): int
    {
        return count($this->analyzeRequestIds);
    }

    /**
     * @return list<string>
     */
    public function analyzeRequestIds(): array
    {
        return $this->analyzeRequestIds;
    }

    private function models(): AiModels
    {
        return new AiModels(
            vision: self::VISION_MODEL,
            embedding: self::EMBEDDING_MODEL,
            visionRevision: self::VISION_REVISION,
            embeddingRevision: self::EMBEDDING_REVISION,
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

<?php

namespace App\Contracts\Ai\Data;

use InvalidArgumentException;

/**
 * schemas/image-analysis.json — the full response of POST /v1/images/analyze (§31).
 * Treated as untrusted model output until validated (§63, §71); the shape here
 * mirrors the schema field-for-field and enforces the embedding length invariant.
 */
final readonly class ImageAnalysisResult
{
    public const int EMBEDDING_DIMENSIONS = 1152;

    /**
     * @param  list<OcrItem>  $ocr
     * @param  list<Concept>  $concepts
     * @param  list<float>  $embedding  Exactly EMBEDDING_DIMENSIONS finite numbers.
     */
    public function __construct(
        public string $requestId,
        public string $caption,
        public array $ocr,
        public array $concepts,
        public array $embedding,
        public AiModels $models,
        public string $promptVersion,
        public int $processingMs,
    ) {
        if (count($this->embedding) !== self::EMBEDDING_DIMENSIONS) {
            throw new InvalidArgumentException(sprintf(
                'ImageAnalysisResult embedding must have exactly %d dimensions, got %d.',
                self::EMBEDDING_DIMENSIONS,
                count($this->embedding),
            ));
        }
    }

    public static function fromArray(array $data): self
    {
        return new self(
            requestId: $data['request_id'],
            caption: $data['caption'],
            ocr: array_map(OcrItem::fromArray(...), $data['ocr']),
            concepts: array_map(Concept::fromArray(...), $data['concepts']),
            embedding: array_map(floatval(...), $data['embedding']),
            models: AiModels::fromArray($data['models']),
            promptVersion: $data['prompt_version'],
            processingMs: (int) $data['processing_ms'],
        );
    }

    public function toArray(): array
    {
        return [
            'request_id' => $this->requestId,
            'caption' => $this->caption,
            'ocr' => array_map(static fn (OcrItem $item): array => $item->toArray(), $this->ocr),
            'concepts' => array_map(static fn (Concept $concept): array => $concept->toArray(), $this->concepts),
            'embedding' => $this->embedding,
            'embedding_dimensions' => self::EMBEDDING_DIMENSIONS,
            'models' => $this->models->toArray(),
            'prompt_version' => $this->promptVersion,
            'processing_ms' => $this->processingMs,
        ];
    }
}

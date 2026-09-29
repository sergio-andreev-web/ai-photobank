<?php

namespace App\Contracts\Ai\Data;

/**
 * ai-api.openapi.yaml #/components/schemas/Models and the equivalent
 * image-analysis.json #/$defs/Models (§30, §31, ADR-0009). `*_revision` pin the
 * exact weights used, so backend never mixes embeddings across model versions.
 */
final readonly class AiModels
{
    public function __construct(
        public string $vision,
        public string $embedding,
        public ?string $visionRevision = null,
        public ?string $embeddingRevision = null,
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            vision: $data['vision'],
            embedding: $data['embedding'],
            visionRevision: $data['vision_revision'] ?? null,
            embeddingRevision: $data['embedding_revision'] ?? null,
        );
    }

    public function toArray(): array
    {
        return array_filter([
            'vision' => $this->vision,
            'embedding' => $this->embedding,
            'vision_revision' => $this->visionRevision,
            'embedding_revision' => $this->embeddingRevision,
        ], static fn (mixed $value): bool => $value !== null);
    }
}

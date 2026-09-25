<?php

namespace App\Contracts\Ai\Data;

use InvalidArgumentException;

/**
 * schemas/text-embeddings.json #/$defs/TextEmbeddingsResponse (POST /v1/embeddings/text, §32).
 * embeddings[i] corresponds to the i-th requested text; the schema does not
 * express that invariant, so it is enforced here.
 */
final readonly class TextEmbeddingsResult
{
    public const int EMBEDDING_DIMENSIONS = 1152;

    /** @param  list<list<float>>  $embeddings */
    public function __construct(
        public string $model,
        public string $revision,
        public array $embeddings,
    ) {
        foreach ($this->embeddings as $embedding) {
            if (count($embedding) !== self::EMBEDDING_DIMENSIONS) {
                throw new InvalidArgumentException(sprintf(
                    'TextEmbeddingsResult embeddings must have exactly %d dimensions, got %d.',
                    self::EMBEDDING_DIMENSIONS,
                    count($embedding),
                ));
            }
        }
    }

    public static function fromArray(array $data): self
    {
        return new self(
            model: $data['model'],
            revision: $data['revision'],
            embeddings: array_map(
                static fn (array $embedding): array => array_map(floatval(...), $embedding),
                $data['embeddings'],
            ),
        );
    }

    public function toArray(): array
    {
        return [
            'model' => $this->model,
            'revision' => $this->revision,
            'dimensions' => self::EMBEDDING_DIMENSIONS,
            'embeddings' => $this->embeddings,
        ];
    }
}

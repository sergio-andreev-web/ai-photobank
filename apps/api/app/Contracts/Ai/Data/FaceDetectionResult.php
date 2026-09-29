<?php

namespace App\Contracts\Ai\Data;

/**
 * POST /v1/faces/detect (contracts/schemas/face-detection.json, ADR-0015).
 */
final readonly class FaceDetectionResult
{
    /**
     * @param  list<DetectedFace>  $faces
     */
    public function __construct(
        public array $faces,
        public string $modelName,
        public string $modelRevision,
        public int $imageWidth,
        public int $imageHeight,
    ) {}

    /**
     * @param  array<string, mixed>  $data  Already validated against the schema.
     */
    public static function fromArray(array $data): self
    {
        return new self(
            faces: array_map(static fn (array $face): DetectedFace => new DetectedFace(
                x: (float) $face['box']['x'],
                y: (float) $face['box']['y'],
                width: (float) $face['box']['width'],
                height: (float) $face['box']['height'],
                score: (float) $face['score'],
                embedding: array_map(floatval(...), $face['embedding']),
            ), $data['faces']),
            modelName: $data['model']['name'],
            modelRevision: $data['model']['revision'],
            imageWidth: (int) $data['image']['width'],
            imageHeight: (int) $data['image']['height'],
        );
    }
}

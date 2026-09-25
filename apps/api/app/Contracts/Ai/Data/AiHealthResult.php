<?php

namespace App\Contracts\Ai\Data;

/**
 * ai-api.openapi.yaml #/components/schemas/HealthResponse (GET /health, §30).
 * Liveness only — a true response does not imply models are loaded (§61, ADR-0010).
 */
final readonly class AiHealthResult
{
    public function __construct(
        public string $status,
        public string $device,
        public AiModels $models,
    ) {
    }

    public static function fromArray(array $data): self
    {
        return new self(
            status: $data['status'],
            device: $data['device'],
            models: AiModels::fromArray($data['models']),
        );
    }

    public function toArray(): array
    {
        return [
            'status' => $this->status,
            'device' => $this->device,
            'models' => $this->models->toArray(),
        ];
    }
}

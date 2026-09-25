<?php

namespace App\Contracts\Ai\Data;

/**
 * schemas/concepts.json #/$defs/Concept. Untrusted AI output (§31, §34, §63):
 * `label` is the model's raw text, not yet normalized or matched to a tag.
 */
final readonly class Concept
{
    public function __construct(
        public ConceptType $type,
        public string $label,
        public float $confidence,
    ) {
    }

    public static function fromArray(array $data): self
    {
        return new self(
            type: ConceptType::from($data['type']),
            label: $data['label'],
            confidence: (float) $data['confidence'],
        );
    }

    public function toArray(): array
    {
        return [
            'type' => $this->type->value,
            'label' => $this->label,
            'confidence' => $this->confidence,
        ];
    }
}

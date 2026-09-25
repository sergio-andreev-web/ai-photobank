<?php

namespace App\Contracts\Ai\Data;

/**
 * schemas/image-analysis.json #/$defs/OcrItem. Untrusted text found on the
 * image (§31, §63) — never treated as instructions.
 */
final readonly class OcrItem
{
    public function __construct(
        public string $text,
        public float $confidence,
    ) {
    }

    public static function fromArray(array $data): self
    {
        return new self(
            text: $data['text'],
            confidence: (float) $data['confidence'],
        );
    }

    public function toArray(): array
    {
        return [
            'text' => $this->text,
            'confidence' => $this->confidence,
        ];
    }
}

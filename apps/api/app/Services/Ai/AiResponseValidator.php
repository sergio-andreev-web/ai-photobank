<?php

namespace App\Services\Ai;

use App\Contracts\Ai\Data\ConceptType;
use App\Contracts\Ai\Exceptions\InvalidAiResponseException;

/**
 * Strict validation of untrusted AI output (§63, §71) against
 * contracts/schemas/image-analysis.json (+ concepts.json) and
 * contracts/schemas/text-embeddings.json#/$defs/TextEmbeddingsResponse.
 *
 * Mirrors the schema rule-for-rule (required fields, additionalProperties:
 * false, lengths, enum, const) plus the two cross-field rules the schema
 * cannot express: analyze `request_id` equals the one sent (contracts/README.md
 * rule 3) and embeddings[i] corresponds to texts[i].
 * tests/Unit/Services/Ai/AiResponseValidatorTest runs the contract examples
 * through it so the mirror cannot drift silently.
 */
final class AiResponseValidator
{
    private const int EMBEDDING_DIMENSIONS = 1152;

    private const int MAX_CONCEPTS = 50;

    private const int MAX_OCR_ITEMS = 100;

    private const int MAX_TEXTS = 64;

    /** @var list<string> */
    private array $violations = [];

    /**
     * @throws InvalidAiResponseException
     */
    public function assertValidImageAnalysis(mixed $data, string $expectedRequestId): void
    {
        $this->violations = [];

        if ($this->object($data, '', [
            'request_id', 'caption', 'ocr', 'concepts', 'embedding', 'embedding_dimensions',
            'models', 'prompt_version', 'processing_ms',
        ])) {
            if ($this->string($data['request_id'], '/request_id', 1, 128) && $data['request_id'] !== $expectedRequestId) {
                $this->violations[] = '/request_id: does not match the request_id sent';
            }

            $this->string($data['caption'], '/caption', 0, 2000);

            if ($this->list($data['ocr'], '/ocr', 0, self::MAX_OCR_ITEMS)) {
                foreach ($data['ocr'] as $i => $item) {
                    if ($this->object($item, "/ocr/{$i}", ['text', 'confidence'])) {
                        $this->string($item['text'], "/ocr/{$i}/text", 1, 2000);
                        $this->unitInterval($item['confidence'], "/ocr/{$i}/confidence");
                    }
                }
            }

            if ($this->list($data['concepts'], '/concepts', 0, self::MAX_CONCEPTS)) {
                foreach ($data['concepts'] as $i => $concept) {
                    $this->concept($concept, "/concepts/{$i}");
                }
            }

            $this->embedding($data['embedding'], '/embedding');

            if ($data['embedding_dimensions'] !== self::EMBEDDING_DIMENSIONS) {
                $this->violations[] = '/embedding_dimensions: must be 1152';
            }

            if ($this->object($data['models'], '/models', ['vision', 'embedding', 'vision_revision', 'embedding_revision'])) {
                foreach (['vision', 'embedding', 'vision_revision', 'embedding_revision'] as $field) {
                    $this->string($data['models'][$field], "/models/{$field}", 1, 255);
                }
            }

            if ($this->string($data['prompt_version'], '/prompt_version', 1, 64)
                && preg_match('/^[a-z0-9][a-z0-9._-]*$/', $data['prompt_version']) !== 1) {
                $this->violations[] = '/prompt_version: does not match pattern';
            }

            if (! is_int($data['processing_ms']) || $data['processing_ms'] < 0) {
                $this->violations[] = '/processing_ms: must be a non-negative integer';
            }
        }

        $this->throwIfInvalid($expectedRequestId);
    }

    /**
     * @throws InvalidAiResponseException
     */
    public function assertValidTextEmbeddings(mixed $data, int $expectedCount, ?string $requestId = null): void
    {
        $this->violations = [];

        if ($this->object($data, '', ['model', 'revision', 'dimensions', 'embeddings'])) {
            $this->string($data['model'], '/model', 1, 255);
            $this->string($data['revision'], '/revision', 1, 255);

            if ($data['dimensions'] !== self::EMBEDDING_DIMENSIONS) {
                $this->violations[] = '/dimensions: must be 1152';
            }

            if ($this->list($data['embeddings'], '/embeddings', 1, self::MAX_TEXTS)) {
                if (count($data['embeddings']) !== $expectedCount) {
                    $this->violations[] = sprintf('/embeddings: expected %d vectors, got %d', $expectedCount, count($data['embeddings']));
                }

                foreach ($data['embeddings'] as $i => $embedding) {
                    $this->embedding($embedding, "/embeddings/{$i}");
                }
            }
        }

        $this->throwIfInvalid($requestId);
    }

    private function concept(mixed $concept, string $path): void
    {
        if (! $this->object($concept, $path, ['type', 'label', 'confidence'])) {
            return;
        }

        if (! is_string($concept['type']) || ConceptType::tryFrom($concept['type']) === null) {
            $this->violations[] = "{$path}/type: not a known tag type";
        }

        if ($this->string($concept['label'], "{$path}/label", 1, 255) && preg_match('/\S/u', $concept['label']) !== 1) {
            $this->violations[] = "{$path}/label: must contain a non-whitespace character";
        }

        $this->unitInterval($concept['confidence'], "{$path}/confidence");
    }

    private function embedding(mixed $embedding, string $path): void
    {
        if (! $this->list($embedding, $path, self::EMBEDDING_DIMENSIONS, self::EMBEDDING_DIMENSIONS)) {
            return;
        }

        foreach ($embedding as $value) {
            if (! $this->isNumber($value) || ! is_finite((float) $value)) {
                $this->violations[] = "{$path}: every component must be a finite number";

                return;
            }
        }
    }

    /**
     * Checks type, exact required key set and additionalProperties: false.
     *
     * @param  list<string>  $keys
     */
    private function object(mixed $value, string $path, array $keys): bool
    {
        if (! is_array($value) || ($value !== [] && array_is_list($value))) {
            $this->violations[] = ($path ?: '/').': must be an object';

            return false;
        }

        $missing = array_diff($keys, array_keys($value));
        $extra = array_diff(array_keys($value), $keys);

        foreach ($missing as $key) {
            $this->violations[] = "{$path}/{$key}: is required";
        }

        foreach ($extra as $key) {
            $this->violations[] = "{$path}/{$key}: additional property is not allowed";
        }

        return $missing === [];
    }

    private function list(mixed $value, string $path, int $min, int $max): bool
    {
        if (! is_array($value) || ! array_is_list($value)) {
            $this->violations[] = "{$path}: must be an array";

            return false;
        }

        if (count($value) < $min || count($value) > $max) {
            $this->violations[] = sprintf('%s: must have %d..%d items, got %d', $path, $min, $max, count($value));

            return false;
        }

        return true;
    }

    private function string(mixed $value, string $path, int $minLength, int $maxLength): bool
    {
        if (! is_string($value)) {
            $this->violations[] = "{$path}: must be a string";

            return false;
        }

        $length = mb_strlen($value);
        if ($length < $minLength || $length > $maxLength) {
            $this->violations[] = sprintf('%s: length must be %d..%d', $path, $minLength, $maxLength);

            return false;
        }

        return true;
    }

    private function unitInterval(mixed $value, string $path): void
    {
        if (! $this->isNumber($value) || $value < 0 || $value > 1) {
            $this->violations[] = "{$path}: must be a number in [0, 1]";
        }
    }

    private function isNumber(mixed $value): bool
    {
        return is_int($value) || is_float($value);
    }

    private function throwIfInvalid(?string $requestId): void
    {
        if ($this->violations !== []) {
            throw new InvalidAiResponseException($this->violations, $requestId);
        }
    }
}

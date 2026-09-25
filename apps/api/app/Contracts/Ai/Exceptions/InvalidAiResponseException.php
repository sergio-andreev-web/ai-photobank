<?php

namespace App\Contracts\Ai\Exceptions;

use RuntimeException;

/**
 * The AI service answered 2xx, but the body does not match the contract
 * (schemas/image-analysis.json, schemas/text-embeddings.json). Per ADR-0010
 * this is a contract violation by AI, not a transient failure: the processing
 * task fails with AI_RESPONSE_INVALID without further retries.
 */
class InvalidAiResponseException extends RuntimeException
{
    /**
     * @param  list<string>  $violations  JSON-pointer-ish paths with the broken rule.
     */
    public function __construct(
        public readonly array $violations,
        public readonly ?string $requestId = null,
    ) {
        parent::__construct('AI response does not match the contract: '.implode('; ', array_slice($violations, 0, 5)));
    }
}

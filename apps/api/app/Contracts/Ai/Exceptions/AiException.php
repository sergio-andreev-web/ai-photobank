<?php

namespace App\Contracts\Ai\Exceptions;

use RuntimeException;

/**
 * Any non-2xx response from the AI service (schemas/ai-error.json). Carries the
 * error code and the retryable flag from the contract's x-known-codes catalog
 * (ADR-0010) so callers can decide retry behaviour without parsing `message`.
 *
 * An unknown code is treated as a generic error keyed off the HTTP status,
 * per contracts/README.md rule 1.
 */
class AiException extends RuntimeException
{
    /**
     * Mirrors ai-error.json #/$defs/AiErrorCode x-known-codes. 5xx and 429 are
     * retryable, other 4xx are not.
     *
     * @var array<string, bool>
     */
    private const array RETRYABLE_CODES = [
        'INVALID_REQUEST' => false,
        'UNAUTHORIZED' => false,
        'IMAGE_TOO_LARGE' => false,
        'UNSUPPORTED_MEDIA_TYPE' => false,
        'IMAGE_DECODE_FAILED' => false,
        'SERVICE_BUSY' => true,
        'INFERENCE_FAILED' => true,
        'INTERNAL_ERROR' => true,
        'MODEL_OUTPUT_INVALID' => true,
        'MODEL_NOT_READY' => true,
    ];

    /**
     * @param  array<string, mixed>  $details
     */
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly int $httpStatus,
        public readonly array $details = [],
        public readonly ?string $requestId = null,
        public readonly bool $retryable = false,
    ) {
        parent::__construct($message);
    }

    /**
     * @param  array<string, mixed>  $details
     */
    public static function fromErrorCode(
        string $code,
        string $message,
        int $httpStatus,
        array $details = [],
        ?string $requestId = null,
    ): self {
        return new self(
            errorCode: $code,
            message: $message,
            httpStatus: $httpStatus,
            details: $details,
            requestId: $requestId,
            retryable: self::RETRYABLE_CODES[$code] ?? ($httpStatus >= 500 || $httpStatus === 429),
        );
    }

    /**
     * Transport-level failure (connection refused, timeout, DNS): no HTTP
     * response body was ever produced, so there is no error code (§46).
     */
    public static function unavailable(string $message, ?string $requestId = null): self
    {
        return new self(
            errorCode: 'AI_SERVICE_UNAVAILABLE',
            message: $message,
            httpStatus: 503,
            requestId: $requestId,
            retryable: true,
        );
    }
}

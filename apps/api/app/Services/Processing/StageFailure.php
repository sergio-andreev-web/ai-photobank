<?php

namespace App\Services\Processing;

use RuntimeException;
use Throwable;

/**
 * A stage failure already classified with a processing error code
 * (contracts/README.md catalog) and whether another attempt can help (§47).
 * The message is shown to admins in ImageDetail.error_message, so it must
 * not contain storage keys or filesystem paths (§11).
 */
final class StageFailure extends RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly bool $retryable,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}

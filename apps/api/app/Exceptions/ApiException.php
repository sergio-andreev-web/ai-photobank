<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * A public API error carrying the stable machine-readable `code` the contract
 * requires (contracts/public-api.openapi.yaml, ТЗ §51). Caught globally in
 * bootstrap/app.php and rendered through App\Http\Responses\ErrorResponse.
 */
class ApiException extends RuntimeException
{
    /**
     * @param  array<string, mixed>  $details
     * @param  array<string, string>  $headers  Extra response headers, e.g. Retry-After on a 503.
     */
    public function __construct(
        public readonly string $errorCode,
        public readonly int $status,
        string $message,
        public readonly array $details = [],
        public readonly array $headers = [],
    ) {
        parent::__construct($message);
    }
}

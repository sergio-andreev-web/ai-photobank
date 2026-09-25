<?php

namespace App\Http\Responses;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Builds the standard `{"error": {code, message, details, trace_id}}` envelope
 * (ТЗ §51) with the matching X-Request-ID header (ТЗ §64, ADR-0002).
 */
final class ErrorResponse
{
    /**
     * @param  array<string, mixed>  $details
     * @param  array<string, string>  $headers
     */
    public static function make(
        Request $request,
        string $code,
        string $message,
        array $details = [],
        int $status = 500,
        array $headers = [],
    ): JsonResponse {
        $traceId = self::traceId($request);

        return response()->json([
            'error' => [
                'code' => $code,
                'message' => $message,
                'details' => (object) $details,
                'trace_id' => $traceId,
            ],
        ], $status, array_merge($headers, ['X-Request-ID' => $traceId]));
    }

    public static function traceId(Request $request): string
    {
        $traceId = $request->attributes->get('request_id');

        if (! is_string($traceId) || $traceId === '') {
            $traceId = (string) Str::uuid();
            $request->attributes->set('request_id', $traceId);
        }

        return $traceId;
    }
}

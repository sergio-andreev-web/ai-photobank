<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Generates the per-request trace id (ТЗ §64) before the route/controller runs
 * and stores it on the request so App\Http\Responses\ErrorResponse can reuse
 * the same value even when an exception short-circuits the middleware stack.
 */
class AssignRequestId
{
    public function handle(Request $request, Closure $next): Response
    {
        $requestId = (string) Str::uuid();
        $request->attributes->set('request_id', $requestId);

        $response = $next($request);
        $response->headers->set('X-Request-ID', $requestId);

        return $response;
    }
}

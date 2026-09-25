<?php

namespace App\Http\Middleware;

use App\Exceptions\ApiException;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Every public API resource requires the admin role (ТЗ §13, ADR-0001):
 * authenticated but non-admin gets 403 FORBIDDEN. Runs after the `auth`
 * middleware, which already turns "no session" into 401 UNAUTHENTICATED.
 */
class EnsureUserIsAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user()?->role !== 'admin') {
            throw new ApiException('FORBIDDEN', 403, 'This action requires an administrator account.');
        }

        return $next($request);
    }
}

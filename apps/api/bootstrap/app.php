<?php

use App\Exceptions\ApiException;
use App\Http\Middleware\AssignRequestId;
use App\Http\Middleware\EnsureUserIsAdmin;
use App\Http\Responses\ErrorResponse;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Request;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->web(append: [
            AssignRequestId::class,
        ]);

        $middleware->alias([
            'admin.role' => EnsureUserIsAdmin::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Every public API response (success or error) carries X-Request-ID
        // equal to error.trace_id (ТЗ §51, §64; ADR-0002).
        $isApiRequest = fn (Request $request): bool => $request->is('api/*') || $request->is('sanctum/*');

        $exceptions->render(function (ApiException $e, Request $request) {
            return ErrorResponse::make($request, $e->errorCode, $e->getMessage(), $e->details, $e->status);
        });

        $exceptions->render(function (AuthenticationException $e, Request $request) {
            return ErrorResponse::make($request, 'UNAUTHENTICATED', 'Unauthenticated', [], 401);
        });

        // Handler::prepareException() wraps TokenMismatchException in an HttpException(419)
        // before render callbacks run, so a callback typed on TokenMismatchException never fires.
        $exceptions->render(function (HttpException $e, Request $request) {
            if (! $e->getPrevious() instanceof TokenMismatchException) {
                return null;
            }

            return ErrorResponse::make($request, 'CSRF_TOKEN_MISMATCH', 'CSRF token mismatch', [], 419);
        });

        $exceptions->render(function (ValidationException $e, Request $request) {
            return ErrorResponse::make(
                $request,
                'VALIDATION_ERROR',
                'The given data was invalid',
                ['fields' => $e->errors()],
                422,
            );
        });

        $exceptions->render(function (ThrottleRequestsException $e, Request $request) {
            $headers = array_filter([
                'Retry-After' => $e->getHeaders()['Retry-After'] ?? null,
            ]);

            return ErrorResponse::make($request, 'TOO_MANY_REQUESTS', 'Too many requests', [], 429, $headers);
        });

        $exceptions->render(function (Throwable $e, Request $request) use ($isApiRequest) {
            if (! $isApiRequest($request)) {
                return null;
            }

            if ($e instanceof HttpExceptionInterface) {
                if ($e->getStatusCode() === 413) {
                    // PHP-level post_max_size/upload_max_filesize rejection (Symfony/Laravel's
                    // PostTooLargeException, thrown by ValidatePostSize before our own file-size
                    // check even runs) is the same failure mode as our app-level check — ADR-0005.
                    return ErrorResponse::make(
                        $request,
                        'FILE_TOO_LARGE',
                        'Uploaded file exceeds the maximum allowed size',
                        [],
                        413,
                    );
                }

                // contracts/README.md's error catalog has no generic code for other
                // framework-level HTTP errors (unmatched route, wrong verb, ...), and these
                // never occur through routes/web.php's own contract-defined operations.
                // Do not fabricate a code — defer to Laravel's default rendering and raise
                // the gap with the contracts owner instead (see task report).
                return null;
            }

            report($e);

            return ErrorResponse::make($request, 'INTERNAL_ERROR', 'Internal server error', [], 500);
        });
    })->create();

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
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // The API is only reached through nginx in the Compose stacks; trust its
        // X-Forwarded-* headers so the client IP (login throttling) and scheme are real.
        $middleware->trustProxies(at: '*');

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

                // Requests outside the described operations (ADR-0002, contracts 1.1.0): an
                // unknown path is NOT_FOUND, a known path with an unsupported verb is
                // METHOD_NOT_ALLOWED with Symfony's Allow header. A missing resource on an
                // existing route keeps its own code — controllers throw ApiException for that.
                if ($e instanceof MethodNotAllowedHttpException) {
                    return ErrorResponse::make($request, 'METHOD_NOT_ALLOWED', 'Method not allowed', [], 405, $e->getHeaders());
                }

                if ($e instanceof NotFoundHttpException) {
                    return ErrorResponse::make($request, 'NOT_FOUND', 'Not found', [], 404);
                }

                // The catalog has no code for the remaining framework-level HTTP errors; defer to
                // Laravel's default rendering rather than fabricate one.
                return null;
            }

            report($e);

            return ErrorResponse::make($request, 'INTERNAL_ERROR', 'Internal server error', [], 500);
        });
    })->create();

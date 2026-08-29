<?php

use App\Http\Middleware\ApiSecurityHeaders;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__ . '/../routes/web.php',
        api: __DIR__ . '/../routes/api.php',
        commands: __DIR__ . '/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->api(prepend: [ApiSecurityHeaders::class]);

        $middleware->alias([
            'idempotency' => \App\Http\Middleware\HandleIdempotency::class,
        ]);

        // API requests should never redirect to a login page.
        $middleware->redirectUsersTo(fn () => null);
        $middleware->redirectGuestsTo(fn () => null);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // API clients always receive JSON, even when they omitted Accept.
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request, Throwable $exception): bool => $request->is('api/*')
        );

        $exceptions->render(function (\App\Exceptions\InsufficientFundsException $e, Request $request) {
            return response()->json([
                'error' => ['code' => 'insufficient_funds', 'message' => $e->getMessage()],
            ], 422);
        });

        $exceptions->render(function (\App\Exceptions\CurrencyMismatchException $e, Request $request) {
            return response()->json([
                'error' => ['code' => 'currency_mismatch', 'message' => $e->getMessage()],
            ], 422);
        });

        $exceptions->render(function (\App\Exceptions\AccountFrozenException $e, Request $request) {
            return response()->json([
                'error' => ['code' => 'account_frozen', 'message' => $e->getMessage()],
            ], 403);
        });

        $exceptions->render(function (\App\Exceptions\NotAccountOwnerException $e, Request $request) {
            return response()->json([
                'error' => ['code' => 'forbidden', 'message' => $e->getMessage()],
            ], 403);
        });

        $exceptions->render(function (\Illuminate\Auth\AuthenticationException $e, Request $request) {
            if ($request->is('api/*')) {
                return response()->json([
                    'error' => ['code' => 'unauthenticated', 'message' => 'Authentication required. Please provide a valid token.'],
                ], 401);
            }
        });

        $exceptions->render(function (\Illuminate\Validation\ValidationException $e, Request $request) {
            if ($request->is('api/*')) {
                return response()->json([
                    'error' => [
                        'code' => 'validation_failed',
                        'message' => 'The given data was invalid.',
                        'details' => $e->errors(),
                    ],
                ], 422);
            }
        });

        $exceptions->render(function (\InvalidArgumentException $e, Request $request) {
            if ($request->is('api/*')) {
                return response()->json([
                    'error' => ['code' => 'invalid_request', 'message' => $e->getMessage()],
                ], 422);
            }
        });

        $exceptions->render(function (\Illuminate\Http\Exceptions\ThrottleRequestsException $e, Request $request) {
            if ($request->is('api/*')) {
                return response()->json([
                    'error' => ['code' => 'rate_limited', 'message' => 'Too many requests. Please try again later.'],
                ], 429, $e->getHeaders());
            }
        });

        // Preserve deliberately generated responses such as a named limiter's
        // custom 429 response before the generic error handler sees them.
        $exceptions->render(function (\Illuminate\Http\Exceptions\HttpResponseException $e, Request $request) {
            return $e->getResponse();
        });

        $exceptions->render(function (MethodNotAllowedHttpException $e, Request $request) {
            if ($request->is('api/*')) {
                return response()->json([
                    'error' => ['code' => 'method_not_allowed', 'message' => 'The requested method is not allowed for this resource.'],
                ], 405, $e->getHeaders());
            }
        });

        // Laravel converts Eloquent ModelNotFoundException into this HTTP
        // exception before custom render callbacks run.
        $exceptions->render(function (NotFoundHttpException $e, Request $request) {
            if ($request->is('api/*')) {
                return response()->json([
                    'error' => ['code' => 'not_found', 'message' => 'Resource not found.'],
                ], 404);
            }
        });

        $exceptions->render(function (HttpExceptionInterface $e, Request $request) {
            if ($request->is('api/*')) {
                $status = $e->getStatusCode();

                return response()->json([
                    'error' => [
                        'code' => $status >= 500 ? 'server_error' : 'request_failed',
                        'message' => $status >= 500 ? 'An unexpected error occurred.' : 'The request could not be processed.',
                    ],
                ], $status, $e->getHeaders());
            }
        });

        $exceptions->render(function (Throwable $e, Request $request) {
            if ($request->is('api/*')) {
                return response()->json([
                    'error' => ['code' => 'server_error', 'message' => 'An unexpected error occurred.'],
                ], 500);
            }
        });

        // Error responses do not pass back through route middleware, so apply
        // the same headers after Laravel has rendered an exception response.
        $exceptions->respond(function (Response $response, Throwable $e, Request $request): Response {
            return $request->is('api/*')
                ? ApiSecurityHeaders::apply($response, $request->isSecure())
                : $response;
        });
    })->create();

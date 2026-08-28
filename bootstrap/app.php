<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__ . '/../routes/web.php',
        api: __DIR__ . '/../routes/api.php',
        commands: __DIR__ . '/../routes/console.php',
        health: '/up',
    )
        ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'idempotency' => \App\Http\Middleware\HandleIdempotency::class,
        ]);

        // API requests should never redirect to a login page — return JSON 401 instead
        $middleware->redirectUsersTo(fn () => null);
        $middleware->redirectGuestsTo(fn () => null);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->render(function (\App\Exceptions\InsufficientFundsException $e, $request) {
            return response()->json([
                'error' => ['code' => 'insufficient_funds', 'message' => $e->getMessage()],
            ], 422);
        });

        $exceptions->render(function (\App\Exceptions\CurrencyMismatchException $e, $request) {
            return response()->json([
                'error' => ['code' => 'currency_mismatch', 'message' => $e->getMessage()],
            ], 422);
        });

        $exceptions->render(function (\App\Exceptions\AccountFrozenException $e, $request) {
            return response()->json([
                'error' => ['code' => 'account_frozen', 'message' => $e->getMessage()],
            ], 422);
        });

        $exceptions->render(function (\App\Exceptions\NotAccountOwnerException $e, $request) {
            return response()->json([
                'error' => ['code' => 'forbidden', 'message' => $e->getMessage()],
            ], 403);
        });

        $exceptions->render(function (\Illuminate\Auth\AuthenticationException $e, $request) {
            if ($request->is('api/*')) {
                return response()->json([
                    'error' => ['code' => 'unauthenticated', 'message' => 'Authentication required. Please provide a valid token.'],
                ], 401);
            }
        });

        $exceptions->render(function (\Illuminate\Database\Eloquent\ModelNotFoundException $e, $request) {
            if ($request->is('api/*')) {
                return response()->json([
                    'error' => ['code' => 'not_found', 'message' => 'Resource not found.'],
                ], 404);
            }
        });

        $exceptions->render(function (\Illuminate\Validation\ValidationException $e, $request) {
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

        $exceptions->render(function (\InvalidArgumentException $e, $request) {
            if ($request->is('api/*')) {
                return response()->json([
                    'error' => ['code' => 'invalid_request', 'message' => $e->getMessage()],
                ], 422);
            }
        });
    })->create();

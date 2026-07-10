<?php

use App\Http\Middleware\SecurityHeadersMiddleware;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Apply security headers to every HTTP response globally
        $middleware->append(SecurityHeadersMiddleware::class);

        // Register named alias so routes can reference 'security.headers'
        $middleware->alias([
            'security.headers' => SecurityHeadersMiddleware::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {

        // Return JSON for all requests (not just api/* — all routes are web routes in this app)
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->expectsJson() || $request->is('files*') || $request->is('health'),
        );

        // ValidationException → 422 VALIDATION_ERROR with details
        $exceptions->render(function (ValidationException $e, Request $request): JsonResponse {
            return response()->json([
                'error' => [
                    'code'    => 'VALIDATION_ERROR',
                    'message' => $e->getMessage(),
                    'details' => $e->errors(),
                ],
            ], 422);
        });

        // ModelNotFoundException / NotFoundHttpException → 404 NOT_FOUND
        $exceptions->render(function (ModelNotFoundException $e, Request $request): JsonResponse {
            return response()->json([
                'error' => [
                    'code'    => 'NOT_FOUND',
                    'message' => 'The requested resource was not found.',
                ],
            ], 404);
        });

        $exceptions->render(function (NotFoundHttpException $e, Request $request): JsonResponse {
            // Unwrap model-not-found exceptions thrown by route model binding
            if ($e->getPrevious() instanceof ModelNotFoundException) {
                return response()->json([
                    'error' => [
                        'code'    => 'NOT_FOUND',
                        'message' => 'The requested resource was not found.',
                    ],
                ], 404);
            }

            return response()->json([
                'error' => [
                    'code'    => 'NOT_FOUND',
                    'message' => 'The requested resource was not found.',
                ],
            ], 404);
        });

        // TooManyRequestsHttpException → 429 RATE_LIMIT_EXCEEDED
        $exceptions->render(function (TooManyRequestsHttpException $e, Request $request): JsonResponse {
            return response()->json([
                'error' => [
                    'code'    => 'RATE_LIMIT_EXCEEDED',
                    'message' => 'Too many requests. Please slow down.',
                ],
            ], 429);
        });

        // All other Throwable on JSON/API routes → 500 INTERNAL_SERVER_ERROR
        // Stack traces, class names, and raw DB messages are never exposed to the client.
        $exceptions->render(function (\Throwable $e, Request $request): ?JsonResponse {
            if (! ($request->expectsJson() || $request->is('files*') || $request->is('health'))) {
                return null; // Let Laravel render Blade error pages for browser requests
            }

            return response()->json([
                'error' => [
                    'code'    => 'INTERNAL_SERVER_ERROR',
                    'message' => 'An unexpected error occurred.',
                ],
            ], 500);
        });

    })->create();

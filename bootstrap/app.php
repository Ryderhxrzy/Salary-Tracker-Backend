<?php

use App\Exceptions\ApiException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        //
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $isApi = fn (Request $request) => $request->is('api/*') || $request->expectsJson();

        $exceptions->shouldRenderJsonWhen($isApi);

        $fail = fn (string $message, int $status, array $errors = [], ?string $code = null) => response()->json(array_filter([
            'success' => false,
            'message' => $message,
            'errors' => (object) $errors,
            'code' => $code,
        ], fn ($v) => $v !== null), $status);

        $exceptions->render(function (ApiException $e, Request $request) use ($isApi, $fail) {
            if ($isApi($request)) {
                return $fail($e->getMessage(), $e->status(), $e->errors(), $e->errorCode());
            }
        });

        $exceptions->render(function (ValidationException $e, Request $request) use ($isApi, $fail) {
            if ($isApi($request)) {
                $first = collect($e->errors())->flatten()->first() ?? 'The given data was invalid.';

                return $fail($first, 422, $e->errors(), 'VALIDATION_ERROR');
            }
        });

        $exceptions->render(function (AuthenticationException $e, Request $request) use ($isApi, $fail) {
            if ($isApi($request)) {
                return $fail('Unauthenticated.', 401, [], 'UNAUTHENTICATED');
            }
        });

        $exceptions->render(function (AuthorizationException $e, Request $request) use ($isApi, $fail) {
            if ($isApi($request)) {
                return $fail('You are not allowed to access this resource.', 403, [], 'FORBIDDEN');
            }
        });

        $exceptions->render(function (ModelNotFoundException|NotFoundHttpException $e, Request $request) use ($isApi, $fail) {
            if ($isApi($request)) {
                return $fail('Resource not found.', 404, [], 'NOT_FOUND');
            }
        });

        $exceptions->render(function (HttpExceptionInterface $e, Request $request) use ($isApi, $fail) {
            if ($isApi($request)) {
                $status = $e->getStatusCode();
                [$message, $code] = match ($status) {
                    401 => ['Unauthenticated.', 'UNAUTHENTICATED'],
                    403 => ['You are not allowed to access this resource.', 'FORBIDDEN'],
                    404 => ['Resource not found.', 'NOT_FOUND'],
                    429 => ['Too many requests. Please try again later.', 'RATE_LIMITED'],
                    default => [$e->getMessage() ?: 'Request failed.', null],
                };

                return $fail($message, $status, [], $code);
            }
        });
    })->create();

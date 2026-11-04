<?php

use App\Exceptions\AiException;
use App\Exceptions\ApiProblem;
use App\Support\ApiResponse;
use App\Support\Locale;
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
        $middleware->prepend(\App\Http\Middleware\AssignRequestId::class);
        $middleware->alias([
            'admin' => \App\Http\Middleware\EnsureAdmin::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson()
        );

        $exceptions->render(function (Throwable $e, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }
            $lang = Locale::fromRequest($request);

            if ($e instanceof ValidationException) {
                return ApiResponse::error(
                    'VALIDATION_ERROR',
                    ApiResponse::message('VALIDATION_ERROR', $lang),
                    422,
                    false,
                    null,
                    $e->errors()
                );
            }
            if ($e instanceof ApiProblem) {
                return ApiResponse::error($e->errorCode, $e->getMessage(), $e->status, $e->retryable, $e->fallback, $e->details);
            }
            if ($e instanceof AiException) {
                return ApiResponse::error(
                    $e->errorCode,
                    ApiResponse::message($e->errorCode, $lang),
                    $e->status,
                    $e->retryable,
                    \App\Services\Scan\ScanService::manualFallback()
                );
            }
            if ($e instanceof AuthenticationException) {
                return ApiResponse::error('UNAUTHENTICATED', ApiResponse::message('UNAUTHENTICATED', $lang), 401);
            }
            if ($e instanceof ModelNotFoundException || $e instanceof NotFoundHttpException) {
                return ApiResponse::error('NOT_FOUND', ApiResponse::message('NOT_FOUND', $lang), 404);
            }
            if ($e instanceof HttpExceptionInterface && $e->getStatusCode() === 429) {
                return ApiResponse::error('RATE_LIMITED', ApiResponse::message('RATE_LIMITED', $lang), 429, true);
            }
            if ($e instanceof HttpExceptionInterface) {
                $status = $e->getStatusCode();
                $code = match ($status) {
                    401 => 'UNAUTHENTICATED',
                    403 => 'FORBIDDEN',
                    404 => 'NOT_FOUND',
                    413 => 'FILE_TOO_LARGE',
                    415 => 'UNSUPPORTED_MEDIA_TYPE',
                    default => 'INTERNAL_ERROR',
                };

                return ApiResponse::error($code, ApiResponse::message($code, $lang), $status);
            }

            report($e);

            return ApiResponse::error('INTERNAL_ERROR', ApiResponse::message('INTERNAL_ERROR', $lang), 500);
        });
    })->create();

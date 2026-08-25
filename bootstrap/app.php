<?php

use App\Application\Exceptions\ConcurrencyConflict;
use App\Application\Exceptions\IntegrityConstraintViolation;
use App\Http\Responses\ApiResponse;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
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
        $exceptions->render(
            function (
                IntegrityConstraintViolation $exception,
                Request $request,
            ) {
                if (! $request->is('api/*')) {
                    return null;
                }

                return ApiResponse::error(
                    'integrity_conflict',
                    'The requested operation violates the current resource state.',
                    409,
                );
            }
        );

        $exceptions->render(
            function (
                ConcurrencyConflict $exception,
                Request $request,
            ) {
                if (! $request->is('api/*')) {
                    return null;
                }

                return ApiResponse::error(
                    'concurrency_conflict',
                    'The resource changed during the operation. Please retry.',
                    409,
                );
            }
        );

        $exceptions->render(
            function (
                ValidationException $exception,
                Request $request,
            ) {
                if (! $request->is('api/*')) {
                    return null;
                }

                return ApiResponse::error(
                    'validation_failed',
                    'The submitted data is invalid.',
                    422,
                    $exception->errors(),
                );
            }
        );

        $exceptions->render(
            function (
                ModelNotFoundException|NotFoundHttpException $exception,
                Request $request,
            ) {
                if (! $request->is('api/*')) {
                    return null;
                }

                return ApiResponse::error(
                    'not_found',
                    'The requested resource was not found.',
                    404,
                );
            }
        );
    })
    ->create();

<?php

declare(strict_types=1);

use App\Payroll\Application\ConcurrencyConflict;
use App\Payroll\Application\PayrollLineNotFound;
use App\Payroll\Domain\InvalidAdjustment;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Preserve audit comments verbatim and let Money reject whitespace in amounts.
        $middleware->trimStrings(except: [
            fn (Request $request): bool => $request->is('api/v1/payroll-lines', 'api/v1/payroll-lines/*'),
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request): bool => $request->is('api/*') || $request->expectsJson(),
        );

        $exceptions->render(function (PayrollLineNotFound $exception, Request $request): ?JsonResponse {
            return $request->is('api/v1/payroll-lines/*')
                ? response()->json(['message' => $exception->getMessage()], 404)
                : null;
        });
        $exceptions->render(function (ConcurrencyConflict $exception, Request $request): ?JsonResponse {
            return $request->is('api/v1/payroll-lines', 'api/v1/payroll-lines/*')
                ? response()->json(['message' => $exception->getMessage()], 409)
                : null;
        });
        $exceptions->render(function (InvalidAdjustment|OverflowException $exception, Request $request): ?JsonResponse {
            return $request->is('api/v1/payroll-lines', 'api/v1/payroll-lines/*')
                ? response()->json(['message' => $exception->getMessage()], 422)
                : null;
        });
    })->create();

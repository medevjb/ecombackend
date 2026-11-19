<?php

use App\Exceptions\ApiProblem;
use App\Http\Middleware\ApiResponseEnvelope;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\ValidateApiJson;
use App\Http\Responses\ApiResponseFactory;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->prepend(ApiResponseEnvelope::class);
        $middleware->api(prepend: [ValidateApiJson::class]);
        $middleware->redirectGuestsTo(fn (Request $request): ?string => $request->is('api', 'api/*') ? null : route('home'));

        $middleware->web(append: [
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->render(fn (ApiProblem $exception, Request $request): ?JsonResponse => $request->is('api', 'api/*')
                ? app(ApiResponseFactory::class)->failure($request, $exception->errorCode, $exception->getMessage(), $exception->getStatusCode())
                : null);

        $exceptions->report(function (Throwable $exception): ?bool {
            if (! request()->is('api', 'api/*')) {
                return null;
            }

            Log::error('Unhandled API exception.', [
                'exception_type' => $exception::class,
                'file' => $exception->getFile(),
                'line' => $exception->getLine(),
            ]);

            return false;
        });

        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api', 'api/*') || $request->expectsJson(),
        );
    })->create();

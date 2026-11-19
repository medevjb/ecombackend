<?php

namespace App\Traits;

use App\Http\Responses\ApiResponseFactory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

trait ApiResponse
{
    /** @param array<string, mixed>|null $meta */
    protected function apiSuccess(Request $request, mixed $data, string $message, int $status = 200, ?array $meta = null): JsonResponse
    {
        return app(ApiResponseFactory::class)->success($request, $data, $message, $status, $meta);
    }

    protected function apiFailure(Request $request, string $code, string $message, int $status): JsonResponse
    {
        return app(ApiResponseFactory::class)->failure($request, $code, $message, $status);
    }
}

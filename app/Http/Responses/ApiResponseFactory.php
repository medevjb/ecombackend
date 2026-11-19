<?php

namespace App\Http\Responses;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ApiResponseFactory
{
    /** @param array<string, mixed>|null $meta */
    public function success(Request $request, mixed $data, string $message, int $status = 200, ?array $meta = null): JsonResponse
    {
        return response()->json([
            'success' => true,
            'message' => $message,
            'data' => $data,
            'meta' => $meta,
            'errors' => null,
            'request_id' => $request->attributes->get('api_request_id'),
        ], $status);
    }

    /** @param array<string, list<string>>|null $fields */
    public function failure(Request $request, string $code, string $message, int $status, ?array $fields = null): JsonResponse
    {
        $errors = ['code' => $code];

        if ($fields !== null) {
            $errors['fields'] = $fields;
        }

        return response()->json([
            'success' => false,
            'message' => $message,
            'data' => null,
            'meta' => null,
            'errors' => $errors,
            'request_id' => $request->attributes->get('api_request_id'),
        ], $status);
    }

    /** @param array<array-key, mixed> $payload */
    public function fromResponse(Request $request, Response $response, array $payload): JsonResponse
    {
        $status = $response->getStatusCode();

        if ($response->isSuccessful()) {
            if (isset($payload['data'], $payload['current_page'], $payload['per_page'], $payload['total'], $payload['last_page'])) {
                return $this->success($request, $payload['data'], 'Request completed.', $status, [
                    'pagination' => array_intersect_key($payload, array_flip(['current_page', 'per_page', 'total', 'last_page'])),
                ]);
            }

            return $this->success($request, $payload, 'Request completed.', $status);
        }

        [$code, $message] = match ($status) {
            400 => ['BAD_REQUEST', 'The request is malformed.'],
            401 => ['UNAUTHENTICATED', 'Authentication is required.'],
            403 => ['FORBIDDEN', 'You are not authorized to perform this action.'],
            404 => ['NOT_FOUND', 'The requested resource was not found.'],
            405 => ['METHOD_NOT_ALLOWED', 'This HTTP method is not allowed.'],
            409 => ['CONFLICT', $payload['message'] ?? 'The request conflicts with the current resource state.'],
            422 => ['VALIDATION_FAILED', 'The supplied data is invalid.'],
            423 => ['ACCOUNT_SUSPENDED', 'This account is suspended.'],
            429 => ['TOO_MANY_REQUESTS', 'Too many requests. Try again later.'],
            503 => ['SERVICE_UNAVAILABLE', 'The service is temporarily unavailable.'],
            default => $status >= 500
                ? ['INTERNAL_ERROR', 'An unexpected error occurred.']
                : ['REQUEST_FAILED', 'The request could not be completed.'],
        };

        return $this->failure(
            $request,
            $code,
            is_string($message) ? $message : 'The request could not be completed.',
            $status,
            $status === 422 && is_array($payload['errors'] ?? null) ? $payload['errors'] : null,
        );
    }
}

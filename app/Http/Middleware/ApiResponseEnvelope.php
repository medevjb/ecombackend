<?php

namespace App\Http\Middleware;

use App\Http\Responses\ApiResponseFactory;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class ApiResponseEnvelope
{
    public function __construct(private readonly ApiResponseFactory $responses) {}

    /** @param Closure(Request): Response $next */
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->is('api', 'api/*')) {
            return $next($request);
        }

        $requestId = 'req_'.Str::ulid()->toBase32();
        $request->attributes->set('api_request_id', $requestId);
        Context::add('request_id', $requestId);

        try {
            $response = $next($request);
            $response->headers->set('X-Request-Id', $requestId);

            $content = $response->getContent();

            if ($response->isEmpty() || $response->isRedirection() || $content === false) {
                return $response;
            }

            $payload = json_decode($content, true);

            if (is_array($payload) && ($payload['request_id'] ?? null) === $requestId) {
                return $response;
            }

            if (! is_array($payload) && $response->isSuccessful()) {
                return $response;
            }

            $wrapped = $this->responses->fromResponse($request, $response, is_array($payload) ? $payload : []);
            $headers = $response->headers->all();
            unset($headers['content-type'], $headers['content-length'], $headers['etag'], $headers['content-md5']);
            $wrapped->headers->add($headers);

            return $wrapped;
        } finally {
            Context::forget('request_id');
        }
    }
}

<?php

use Illuminate\Support\Facades\Route;

it('assigns a request ID to the response and ignores user-supplied IDs', function () {
    $response = $this->getJson('/api/v1/status', ['X-Request-Id' => 'external-id'])
        ->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('meta', null)
        ->assertJsonPath('errors', null);

    $requestId = $response->json('request_id');

    expect($requestId)->toMatch('/^req_[0-9A-HJKMNP-TV-Z]{26}$/');
    $response->assertHeader('X-Request-Id', $requestId);
});

it('returns stable JSON errors without an accept header', function (string $method, string $uri, int $status, string $code) {
    $response = $this->call($method, $uri)
        ->assertStatus($status)
        ->assertJsonPath('success', false)
        ->assertJsonPath('data', null)
        ->assertJsonPath('errors.code', $code);

    $response->assertHeader('X-Request-Id', $response->json('request_id'));

    if ($status === 405) {
        $response->assertHeader('Allow', 'POST');
    }
})->with([
    'unmatched route' => ['GET', '/api/missing', 404, 'NOT_FOUND'],
    'wrong method' => ['GET', '/api/v1/auth/login', 405, 'METHOD_NOT_ALLOWED'],
    'missing token' => ['GET', '/api/v1/auth/me', 401, 'UNAUTHENTICATED'],
]);

it('rejects malformed JSON with 400', function () {
    $this->call('POST', '/api/v1/auth/login', server: ['CONTENT_TYPE' => 'application/json'], content: '{"email":')
        ->assertBadRequest()
        ->assertJsonPath('errors.code', 'BAD_REQUEST');
});

it('redacts unexpected error details even when debug mode is enabled', function () {
    config(['app.debug' => true]);
    Route::get('/api/v1/test-error', function (): never {
        throw new RuntimeException('Sensitive test detail');
    });

    $this->get('/api/v1/test-error')->assertInternalServerError()
        ->assertJsonPath('errors.code', 'INTERNAL_ERROR')
        ->assertJsonMissingPath('trace')
        ->assertDontSee('Sensitive test detail');
});

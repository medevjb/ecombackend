<?php

use Symfony\Component\HttpFoundation\BinaryFileResponse;

it('renders a searchable reference and serves the canonical OpenAPI contract', function () {
    $this->get('/docs')->assertOk()
        ->assertSee('Find an endpoint')
        ->assertSee('HTTP method')
        ->assertSee('/api/v1/products')
        ->assertSee(route('api-reference.specification', absolute: false));

    $response = $this->get('/docs/openapi.yaml')->assertOk()
        ->assertHeader('Content-Type', 'application/yaml; charset=UTF-8');

    expect($response->baseResponse)->toBeInstanceOf(BinaryFileResponse::class);
    expect($response->baseResponse->getFile()->getPathname())->toBe(base_path('docs/api/openapi.yaml'));
});

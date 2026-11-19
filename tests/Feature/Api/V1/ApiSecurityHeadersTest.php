<?php

it('applies security headers to API errors and prevents authentication responses from being cached', function () {
    $this->getJson('/api/v1/me')
        ->assertUnauthorized()
        ->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeader('X-Frame-Options', 'DENY')
        ->assertHeader('Referrer-Policy', 'no-referrer')
        ->assertHeader('Cache-Control', 'no-store, private');
});

it('permits configured cross-origin bearer requests without enabling credentialed CORS', function () {
    config(['cors.allowed_origins' => ['https://store.example.test', 'https://staff.example.test']]);

    $this->options('/api/v1/me', [], [
        'Origin' => 'https://store.example.test',
        'Access-Control-Request-Method' => 'GET',
        'Access-Control-Request-Headers' => 'Authorization',
    ])->assertNoContent()->assertHeader('Access-Control-Allow-Origin', 'https://store.example.test')
        ->assertHeaderMissing('Access-Control-Allow-Credentials');

    $this->options('/api/v1/me', [], [
        'Origin' => 'https://unlisted.example.test',
        'Access-Control-Request-Method' => 'GET',
    ])->assertNoContent()->assertHeaderMissing('Access-Control-Allow-Origin');
});

<?php

it('permits bearer preflight only from configured frontend origins', function (string $origin, ?string $allowedOrigin) {
    config(['cors.allowed_origins' => ['https://shop.example.test', 'https://admin.example.test']]);

    $response = $this->options('/api/v1/auth/login', [], [
        'Origin' => $origin,
        'Access-Control-Request-Method' => 'POST',
        'Access-Control-Request-Headers' => 'authorization, content-type',
    ])->assertNoContent()->assertHeaderMissing('Access-Control-Allow-Credentials');

    if ($allowedOrigin === null) {
        $response->assertHeaderMissing('Access-Control-Allow-Origin');
    } else {
        $response->assertHeader('Access-Control-Allow-Origin', $allowedOrigin);
    }
})->with([
    'storefront' => ['https://shop.example.test', 'https://shop.example.test'],
    'admin' => ['https://admin.example.test', 'https://admin.example.test'],
    'unknown' => ['https://unknown.example.test', null],
]);

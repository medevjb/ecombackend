<?php

return [
    'paths' => ['api/*'],
    'allowed_methods' => ['*'],
    'allowed_origins' => array_values(array_filter([
        env('STOREFRONT_ORIGIN'),
        env('ADMIN_ORIGIN'),
    ])),
    'allowed_origins_patterns' => [],
    'allowed_headers' => ['Accept', 'Authorization', 'Content-Type', 'Idempotency-Key', 'X-Cart-Token'],
    'exposed_headers' => ['Retry-After', 'X-Request-Id', 'X-Cart-Token'],
    'max_age' => 0,
    'supports_credentials' => false,
];

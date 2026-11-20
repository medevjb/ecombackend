<?php

return [
    'proxies' => array_values(array_filter(array_map('trim', explode(',', (string) env('TRUSTED_PROXY_ADDRESSES', ''))),
        fn (string $address): bool => filter_var(explode('/', $address, 2)[0], FILTER_VALIDATE_IP) !== false)),
];

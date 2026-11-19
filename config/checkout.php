<?php

return [
    'flat_shipping_amount' => env('CHECKOUT_FLAT_SHIPPING_AMOUNT'),
    'tax_basis_points' => (int) env('CHECKOUT_TAX_BASIS_POINTS', 0),
    'reservation_minutes' => 15,
    'operations_email' => env('CHECKOUT_OPERATIONS_EMAIL'),
];

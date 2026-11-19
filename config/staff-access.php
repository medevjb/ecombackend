<?php

$catalog = ['categories', 'brands', 'attributes', 'products'];
$catalogView = array_map(fn (string $resource): string => $resource.'.view', $catalog);
$catalogWrite = array_merge(...array_map(
    fn (string $resource): array => array_map(fn (string $action): string => $resource.'.'.$action, ['create', 'update', 'delete']),
    $catalog,
));

$management = [
    'staff.view', 'staff.manage', 'roles.view', 'roles.manage', 'audit.view',
    'inventory.view', 'inventory.adjust', 'orders.view', 'orders.update_status', 'orders.refund', 'orders.note',
    'coupons.view', 'coupons.manage', 'customers.view', 'customers.manage', 'content.view', 'content.manage',
    'reports.view', 'reviews.manage',
];

return [
    'permissions' => [...$catalogView, ...$catalogWrite, ...$management],
    'super_admin_only' => ['staff.manage', 'roles.manage'],
    'roles' => [
        'super-admin' => [],
        'admin' => [...$catalogView, ...$catalogWrite, ...array_diff($management, ['staff.manage', 'roles.manage'])],
        'product-manager' => [...$catalogView, ...$catalogWrite, 'inventory.view', 'inventory.adjust', 'orders.view', 'content.view', 'reports.view'],
        'order-manager' => [...$catalogView, 'orders.view', 'orders.update_status', 'orders.refund', 'orders.note', 'coupons.view', 'customers.view', 'reports.view', 'audit.view'],
        'support' => [...$catalogView, 'orders.view', 'orders.note', 'customers.view'],
    ],
];

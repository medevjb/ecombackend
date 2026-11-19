<?php

return [
    'low_stock_threshold' => max(0, (int) env('INVENTORY_LOW_STOCK_THRESHOLD', 5)),
];

<?php

namespace Database\Factories;

use App\Enums\RefundStatus;
use App\Models\Order;
use App\Models\OrderRefund;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<OrderRefund> */
class OrderRefundFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'order_id' => Order::factory(), 'purpose' => 'manual', 'status' => RefundStatus::Pending,
            'currency' => 'USD', 'amount' => 100, 'reason' => 'Customer request',
            'idempotency_hash' => hash('sha256', fake()->uuid()),
        ];
    }
}

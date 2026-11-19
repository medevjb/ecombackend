<?php

namespace Database\Factories;

use App\Enums\OrderStatus;
use App\Models\CheckoutAttempt;
use App\Models\Order;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Order> */
class OrderFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'checkout_attempt_id' => CheckoutAttempt::factory(),
            'user_id' => fn (array $attributes) => CheckoutAttempt::query()->whereKey($attributes['checkout_attempt_id'])->value('user_id'),
            'number' => fake()->unique()->numerify('ORD-2026-######'),
            'status' => OrderStatus::Pending,
            'currency' => 'USD',
            'subtotal_amount' => 2900, 'discount_amount' => 0, 'shipping_amount' => 0, 'tax_amount' => 0, 'total_amount' => 2900,
        ];
    }
}

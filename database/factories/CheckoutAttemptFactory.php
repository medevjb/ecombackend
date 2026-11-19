<?php

namespace Database\Factories;

use App\Models\Cart;
use App\Models\CheckoutAttempt;
use App\Models\StockReservation;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<CheckoutAttempt> */
class CheckoutAttemptFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'cart_id' => fn (array $attributes) => Cart::factory()->create(['user_id' => $attributes['user_id']])->id,
            'stock_reservation_id' => StockReservation::factory(),
            'idempotency_hash' => hash('sha256', fake()->uuid()),
            'request_fingerprint' => hash('sha256', fake()->uuid()),
            'cart_fingerprint' => hash('sha256', fake()->uuid()),
            'expires_at' => now()->addMinutes(15),
        ];
    }
}

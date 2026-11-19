<?php

namespace Database\Factories;

use App\Enums\ReservationStatus;
use App\Models\StockReservation;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<StockReservation> */
class StockReservationFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'fingerprint' => hash('sha256', fake()->uuid()),
            'status' => ReservationStatus::Active,
            'expires_at' => now()->addMinutes(15),
        ];
    }
}

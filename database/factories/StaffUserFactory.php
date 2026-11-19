<?php

namespace Database\Factories;

use App\Models\StaffUser;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<StaffUser> */
class StaffUserFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return ['id' => User::factory()];
    }
}

<?php

namespace Database\Factories;

use App\Models\Inventory;
use App\Models\ProductVariant;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Inventory> */
class InventoryFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return ['product_variant_id' => ProductVariant::factory(), 'on_hand' => 0, 'reserved' => 0];
    }
}

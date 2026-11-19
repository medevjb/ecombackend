<?php

namespace Database\Factories;

use App\Models\Inventory;
use App\Models\InventoryMovement;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<InventoryMovement> */
class InventoryMovementFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'inventory_id' => Inventory::factory(),
            'type' => 'adjustment',
            'delta' => 1,
            'on_hand_after' => 1,
            'reason' => 'Count correction',
            'event_key' => hash('sha256', fake()->uuid()),
        ];
    }
}

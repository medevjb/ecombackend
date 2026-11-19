<?php

use App\Enums\ProductStatus;
use App\Models\Inventory;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\StaffUser;
use Illuminate\Support\Facades\Concurrency;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\InventoryRaceWorker;

it('prevents overselling and lost updates when separate MySQL processes contend for the same row', function () {
    if (DB::getDriverName() !== 'mysql') {
        $this->markTestSkipped('Requires the disposable MySQL CI database.');
    }

    $variant = ProductVariant::factory()->for(Product::factory()->state(['status' => ProductStatus::Active]))->create();
    $inventory = Inventory::factory()->for($variant, 'variant')->create(['on_hand' => 1]);
    $variantId = $variant->id;
    $publicId = $variant->public_id;
    $actorId = StaffUser::factory()->create()->id;
    $path = sys_get_temp_dir().'/inventory-race-'.Str::random(20);
    mkdir($path, 0700);

    $race = fn (string $operation): array => Concurrency::driver('process')->run([
        InventoryRaceWorker::make($operation, 'one', $path, $variantId, $publicId, $actorId),
        InventoryRaceWorker::make($operation, 'two', $path, $variantId, $publicId, $actorId),
    ]);

    try {
        $reservations = $race('reserve');
        sort($reservations);
        expect($reservations)->toBe(['INSUFFICIENT_STOCK', 'reserved']);
        expect($inventory->refresh())->on_hand->toBe(1)->reserved->toBe(1);

        expect($race('adjust'))->toBe(['adjusted', 'adjusted']);
        expect($inventory->refresh())->on_hand->toBe(3)->reserved->toBe(1);
        expect($inventory->movements()->count())->toBe(2);
    } finally {
        foreach (glob($path.'/*') ?: [] as $marker) {
            unlink($marker);
        }
        rmdir($path);
    }
});

<?php

use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Support\Facades\Storage;

it('seeds an illustrated catalog and repeatable customer order snapshots', function () {
    Storage::fake('public');
    $this->seed(DatabaseSeeder::class);
    $this->seed(DatabaseSeeder::class);

    expect(Product::query()->whereHas('images')->count())->toBeGreaterThanOrEqual(20);
    $customer = User::query()->where('email', 'customer@demo.test')->firstOrFail();
    $orders = Order::query()->where('user_id', $customer->id)->with('payment')->orderBy('number')->get();
    expect($orders->pluck('status')->map->value->all())->toBe(['delivered', 'cancelled'])
        ->and($orders->pluck('payment.provider_intent_id')->all())->toBe([null, null]);
    $this->assertDatabaseCount('order_addresses', 2);
    $this->assertDatabaseCount('order_items', 2);
    $this->assertDatabaseCount('stock_reservations', 2);
    $this->assertDatabaseCount('inventory_movements', 1);
});

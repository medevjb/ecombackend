<?php

use App\Enums\ProductStatus;
use App\Enums\ReservationStatus;
use App\Exceptions\ApiProblem;
use App\Models\Inventory;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Services\Inventory\InventoryService;
use Database\Seeders\StaffAccessSeeder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

it('records idempotent adjustments and refuses to reduce stock below holds', function () {
    $this->seed(StaffAccessSeeder::class);
    $this->withToken(catalogStaffToken('product-manager'));
    $stock = Inventory::factory()->create(['on_hand' => 2, 'reserved' => 1]);
    $variant = $stock->variant;
    $data = ['variant_id' => $variant->public_id, 'delta' => 3, 'reason' => 'Delivery received'];

    $created = $this->postJson('/api/v1/admin/inventory/adjustments', $data, ['Idempotency-Key' => 'delivery-1'])
        ->assertCreated()->assertJsonPath('data.on_hand_after', 5)->json('data.id');
    Auth::forgetGuards();
    $this->postJson('/api/v1/admin/inventory/adjustments', $data, ['Idempotency-Key' => 'delivery-1'])
        ->assertOk()->assertJsonPath('data.id', $created);
    Auth::forgetGuards();
    $this->postJson('/api/v1/admin/inventory/adjustments', array_replace($data, ['delta' => 2]), ['Idempotency-Key' => 'delivery-1'])
        ->assertConflict()->assertJsonPath('errors.code', 'IDEMPOTENCY_CONFLICT');
    Auth::forgetGuards();
    $this->postJson('/api/v1/admin/inventory/adjustments', array_replace($data, ['delta' => -5]), ['Idempotency-Key' => 'delivery-2'])
        ->assertConflict()->assertJsonPath('errors.code', 'STOCK_LIMIT');

    expect($stock->refresh()->available())->toBe(4);
    $this->assertDatabaseCount('inventory_movements', 1);
    Auth::forgetGuards();
    $this->getJson('/api/v1/admin/inventory?low_stock=1')
        ->assertOk()->assertJsonPath('data.0.available', 4)->assertJsonPath('meta.low_stock_count', 1);
    Auth::forgetGuards();
    $this->getJson('/api/v1/admin/inventory/movements?variant_id='.$variant->public_id)
        ->assertOk()->assertJsonPath('data.0.id', $created);
});

it('requires a staff inventory grant and validates adjustment keys', function () {
    $this->seed(StaffAccessSeeder::class);
    $stock = Inventory::factory()->create();
    $data = ['variant_id' => $stock->variant->public_id, 'delta' => 1, 'reason' => 'Correction'];
    $this->withToken(catalogStaffToken('support'))->postJson('/api/v1/admin/inventory/adjustments', $data)->assertForbidden();

    Auth::forgetGuards();
    $this->withToken(catalogStaffToken('product-manager'))->postJson('/api/v1/admin/inventory/adjustments', $data)
        ->assertUnprocessable()->assertJsonValidationErrors('idempotency_key', 'errors.fields');
});

it('reserves without deducting and commits and restores exactly once', function () {
    $product = Product::factory()->create(['status' => ProductStatus::Active]);
    $variant = ProductVariant::factory()->for($product)->create();
    $stock = Inventory::factory()->for($variant, 'variant')->create(['on_hand' => 3]);
    $inventory = app(InventoryService::class);
    $key = (string) Str::ulid();
    $expiry = now()->addMinutes(15);

    $reservation = $inventory->reserve($key, [$variant->id => 2], $expiry);
    expect($inventory->reserve($key, [$variant->id => 2], $expiry)->id)->toBe($reservation->id);
    expect($stock->refresh())->on_hand->toBe(3)->reserved->toBe(2);

    $inventory->commit($key, 'test-payment-1');
    expect($inventory->commit($key, 'test-payment-1')->status)->toBe(ReservationStatus::Committed);
    expect($stock->refresh())->on_hand->toBe(1)->reserved->toBe(0);

    $inventory->restoreCommitted($key, 'test-refund-1');
    $inventory->restoreCommitted($key, 'test-refund-1');
    expect($stock->refresh())->on_hand->toBe(3)->reserved->toBe(0);
    $this->assertDatabaseCount('inventory_movements', 2);
});

it('expires once and rejects a late payment if another reservation holds the stock', function () {
    $this->freezeTime();
    $product = Product::factory()->create(['status' => ProductStatus::Active]);
    $variant = ProductVariant::factory()->for($product)->create();
    $stock = Inventory::factory()->for($variant, 'variant')->create(['on_hand' => 1]);
    $inventory = app(InventoryService::class);
    $id = (string) Str::ulid();
    $reservation = $inventory->reserve($id, [$variant->id => 1], now()->addMinute());

    $this->travel(1)->minute();
    expect($inventory->expireDue())->toBe(1)->and($inventory->expireDue())->toBe(0);
    expect($reservation->refresh()->status)->toBe(ReservationStatus::Expired);
    expect($stock->refresh())->on_hand->toBe(1)->reserved->toBe(0);

    $other = (string) Str::ulid();
    $inventory->reserve($other, [$variant->id => 1], now()->addMinute());
    try {
        $inventory->commit($id, 'test-late-payment');
        test()->fail('An expired hold cannot take stock from a competing reservation.');
    } catch (ApiProblem $problem) {
        expect($problem->errorCode)->toBe('INSUFFICIENT_STOCK');
    }
    expect($stock->refresh())->on_hand->toBe(1)->reserved->toBe(1);
    $this->assertDatabaseCount('inventory_movements', 0);

    $inventory->release($other);
    $inventory->release($other);
    expect($inventory->commit($id, 'test-late-payment')->status)->toBe(ReservationStatus::Committed);
    expect($stock->refresh())->on_hand->toBe(0)->reserved->toBe(0);
});

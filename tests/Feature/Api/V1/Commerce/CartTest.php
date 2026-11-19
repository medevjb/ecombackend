<?php

use App\Enums\ProductStatus;
use App\Models\Category;
use App\Models\Inventory;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use Illuminate\Support\Facades\Auth;

function cartSellableVariant(int $onHand = 4): ProductVariant
{
    $product = Product::factory()->create(['status' => ProductStatus::Active, 'regular_amount' => 2800, 'sale_amount' => 2500]);
    $product->categories()->attach(Category::factory()->create());
    $variant = ProductVariant::factory()->for($product)->create();
    Inventory::factory()->for($variant, 'variant')->create(['on_hand' => $onHand]);

    return $variant;
}

it('persists guest carts and recomputes prices and stock without reserving inventory', function () {
    $variant = cartSellableVariant();
    $guest = $this->getJson('/api/v1/cart')->assertOk()->headers->get('X-Cart-Token');

    $data = ['variant_id' => $variant->public_id, 'quantity' => 2, 'unit_amount' => 1];
    $this->postJson('/api/v1/cart/items', $data, ['X-Cart-Token' => $guest])
        ->assertOk()->assertJsonPath('data.subtotal_amount', 5000);
    $this->postJson('/api/v1/cart/items', $data, ['X-Cart-Token' => $guest])
        ->assertOk()->assertJsonCount(1, 'data.items')->assertJsonPath('data.items.0.quantity', 4);
    $this->postJson('/api/v1/cart/items', $data, ['X-Cart-Token' => $guest])
        ->assertConflict()->assertJsonPath('errors.code', 'INSUFFICIENT_STOCK');

    expect($variant->inventory->refresh()->reserved)->toBe(0);
    $variant->product->forceFill(['sale_amount' => 2100])->save();
    $this->getJson('/api/v1/cart', ['X-Cart-Token' => $guest])->assertJsonPath('data.subtotal_amount', 8400);
    $variant->inventory->forceFill(['on_hand' => 3])->save();
    $this->getJson('/api/v1/cart', ['X-Cart-Token' => $guest])->assertJsonPath('data.conflicts.0.code', 'INSUFFICIENT_STOCK');
});

it('cannot use a guest token or customer bearer to change another cart line', function () {
    $variant = cartSellableVariant();
    $first = $this->postJson('/api/v1/cart/items', ['variant_id' => $variant->public_id, 'quantity' => 1])->assertOk();
    $item = $first->json('data.items.0.id');
    $second = $this->getJson('/api/v1/cart')->assertOk()->headers->get('X-Cart-Token');
    $this->patchJson('/api/v1/cart/items/'.$item, ['quantity' => 2], ['X-Cart-Token' => $second])->assertNotFound();
    $user = User::factory()->create();
    $this->withToken($user->createToken('customer')->plainTextToken)->deleteJson('/api/v1/cart/items/'.$item)->assertNotFound();
});

it('merges a guest cart on shared customer login and consumes the guest token', function () {
    $variant = cartSellableVariant();
    $guest = $this->postJson('/api/v1/cart/items', ['variant_id' => $variant->public_id, 'quantity' => 3])
        ->assertOk()->headers->get('X-Cart-Token');
    $customer = User::factory()->create();
    $existing = $customer->createToken('existing')->plainTextToken;

    $this->withToken($existing)->postJson('/api/v1/cart/items', ['variant_id' => $variant->public_id, 'quantity' => 2])->assertOk();
    Auth::forgetGuards();
    $login = $this->postJson('/api/v1/auth/login', ['email' => $customer->email, 'password' => 'password'],
        ['X-Cart-Token' => $guest])->assertOk()
        ->assertJsonPath('data.cart_merge.status', 'merged')
        ->assertJsonPath('data.cart_merge.conflicts.0.requested_quantity', 5)
        ->assertJsonPath('data.cart_merge.conflicts.0.quantity', 4);

    Auth::forgetGuards();
    $this->withToken($login->json('data.access_token'))->getJson('/api/v1/cart')->assertJsonPath('data.items.0.quantity', 4);
    Auth::forgetGuards();
    $this->flushHeaders()->getJson('/api/v1/cart', ['X-Cart-Token' => $guest])
        ->assertNotFound()->assertJsonPath('errors.code', 'CART_NOT_FOUND');
});

it('merges explicitly and rejects a missing guest token', function () {
    $customer = User::factory()->create();
    $this->withToken($customer->createToken('customer')->plainTextToken)->postJson('/api/v1/cart/merge')
        ->assertUnprocessable()->assertJsonValidationErrors('guest_token', 'errors.fields');

    $guest = $this->flushHeaders()->getJson('/api/v1/cart')->assertOk()->headers->get('X-Cart-Token');
    Auth::forgetGuards();
    $this->withToken($customer->createToken('second')->plainTextToken)
        ->postJson('/api/v1/cart/merge', [], ['X-Cart-Token' => $guest])
        ->assertOk()->assertJsonCount(0, 'data.merge_conflicts');
});

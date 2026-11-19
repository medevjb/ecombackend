<?php

use App\Enums\ProductStatus;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Category;
use App\Models\Coupon;
use App\Models\CustomerAddress;
use App\Models\Inventory;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use Illuminate\Support\Facades\Auth;

it('calculates checkout from current prices, discounts, and shipping rules', function () {
    config(['checkout.flat_shipping_amount' => 400, 'checkout.tax_basis_points' => 500]);
    $user = User::factory()->create();
    $address = CustomerAddress::factory()->create(['user_id' => $user->id, 'city' => 'Portland']);
    $coupon = Coupon::factory()->create(['value' => 500]);
    $cart = Cart::factory()->create(['user_id' => $user->id, 'coupon_id' => $coupon->id]);
    $product = Product::factory()->create(['regular_amount' => 3500, 'sale_amount' => 3000, 'status' => ProductStatus::Active]);
    $product->categories()->attach(Category::factory()->create());
    $variant = ProductVariant::factory()->for($product)->create(['price_override_amount' => 2900]);
    $stock = Inventory::factory()->for($variant, 'variant')->create(['on_hand' => 3]);
    CartItem::factory()->create(['cart_id' => $cart->id, 'product_variant_id' => $variant->id, 'quantity' => 2]);

    $this->withToken($user->createToken('customer')->plainTextToken)
        ->postJson('/api/v1/checkout/preview', ['address_id' => $address->public_id])
        ->assertOk()->assertJsonPath('data.can_checkout', true)
        ->assertJsonPath('data.subtotal_amount', 5800)
        ->assertJsonPath('data.discount_amount', 500)
        ->assertJsonPath('data.shipping_amount', 400)
        ->assertJsonPath('data.tax_amount', 265)
        ->assertJsonPath('data.total_amount', 5965)
        ->assertJsonPath('data.address.city', 'Portland');
    expect($stock->refresh()->reserved)->toBe(0);
});

it('rejects supplied totals and hides other customers shipping addresses', function () {
    config(['checkout.flat_shipping_amount' => 400]);
    $user = User::factory()->create();
    $address = CustomerAddress::factory()->create();
    $this->withToken($user->createToken('customer')->plainTextToken)
        ->postJson('/api/v1/checkout/preview', ['address_id' => $address->public_id, 'total_amount' => 1, 'payment_status' => 'paid'])
        ->assertUnprocessable()->assertJsonValidationErrors(['total_amount', 'payment_status'], 'errors.fields');

    Auth::forgetGuards();
    $this->postJson('/api/v1/checkout/preview', ['address_id' => $address->public_id])->assertNotFound();
});

<?php

use App\Enums\CouponType;
use App\Enums\CouponUsageStatus;
use App\Enums\ProductStatus;
use App\Exceptions\ApiProblem;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Category;
use App\Models\Coupon;
use App\Models\CouponRedemption;
use App\Models\Inventory;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Services\Cart\CartPricing;
use App\Services\Promotions\CouponService;
use Database\Seeders\StaffAccessSeeder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

function couponVariant(int $amount): ProductVariant
{
    $product = Product::factory()->create(['status' => ProductStatus::Active, 'regular_amount' => $amount]);
    $product->categories()->attach(Category::factory()->create());
    $variant = ProductVariant::factory()->for($product)->create();
    Inventory::factory()->for($variant, 'variant')->create(['on_hand' => 20]);

    return $variant;
}

it('creates normalized coupon codes and validates monetary values and dates', function () {
    $this->seed(StaffAccessSeeder::class);
    $this->withToken(catalogStaffToken('admin'));
    $base = '/api/v1/admin/coupons';

    $id = $this->postJson($base, ['code' => 'ten-off', 'type' => 'percentage', 'value' => 10.25])
        ->assertCreated()->assertJsonPath('data.code', 'TEN-OFF')->assertJsonPath('data.value', 10.25)
        ->assertJsonPath('data.status', 'inactive')->json('data.id');
    Auth::forgetGuards();
    $this->postJson($base, ['code' => 'TEN-off', 'type' => 'fixed', 'value' => 12])
        ->assertUnprocessable()->assertJsonValidationErrors('code', 'errors.fields');
    Auth::forgetGuards();
    $this->patchJson($base.'/'.$id, ['type' => 'fixed', 'value' => 20.5])
        ->assertUnprocessable()->assertJsonValidationErrors('value', 'errors.fields');
    Auth::forgetGuards();
    $this->patchJson($base.'/'.$id, ['starts_at' => '2027-01-05', 'ends_at' => '2027-01-01'])
        ->assertUnprocessable()->assertJsonValidationErrors('ends_at', 'errors.fields');
    Auth::forgetGuards();
    $this->patchJson($base.'/'.$id.'/status', ['status' => 'active'])->assertOk()
        ->assertJsonPath('data.status', 'active');
});

it('uses server pricing and applies percentages only to eligible products', function () {
    $eligible = couponVariant(2499);
    $other = couponVariant(500);
    $coupon = Coupon::factory()->create(['type' => CouponType::Percentage, 'value' => 1250, 'currency' => null, 'minimum_amount' => 4000]);
    $coupon->products()->attach($eligible->product->id);
    $cart = $this->postJson('/api/v1/cart/items', ['variant_id' => $eligible->public_id, 'quantity' => 2])->assertOk();
    $token = ['X-Cart-Token' => $cart->headers->get('X-Cart-Token')];
    $this->postJson('/api/v1/cart/items', ['variant_id' => $other->public_id, 'quantity' => 1], $token)->assertOk();

    $this->postJson('/api/v1/cart/coupon', ['code' => strtolower($coupon->code), 'discount_amount' => 5498], $token)
        ->assertOk()->assertJsonPath('data.subtotal_amount', 5498)
        ->assertJsonPath('data.discount_amount', 624)->assertJsonPath('data.total_amount', 4874);
    $this->deleteJson('/api/v1/cart/coupon', [], $token)->assertOk()->assertJsonPath('data.total_amount', 5498);
    $this->assertDatabaseCount('coupon_redemptions', 0);
});

it('returns stable errors for expired, minimum, exhausted, and ineligible coupons', function () {
    $variant = couponVariant(700);
    $created = $this->postJson('/api/v1/cart/items', ['variant_id' => $variant->public_id, 'quantity' => 1])->assertOk();
    $token = ['X-Cart-Token' => $created->headers->get('X-Cart-Token')];
    $expired = Coupon::factory()->create(['ends_at' => now()->subMinute()]);
    $minimum = Coupon::factory()->create(['minimum_amount' => 1000]);
    $exhausted = Coupon::factory()->create(['global_limit' => 1]);
    CouponRedemption::factory()->create(['coupon_id' => $exhausted->id, 'status' => CouponUsageStatus::Redeemed]);
    $restricted = Coupon::factory()->create();
    $restricted->products()->attach(Product::factory()->create());
    foreach ([
        [$expired, 'COUPON_EXPIRED'], [$minimum, 'COUPON_MINIMUM_SPEND'],
        [$exhausted, 'COUPON_EXHAUSTED'], [$restricted, 'COUPON_INELIGIBLE'],
    ] as [$coupon, $code]) {
        $this->postJson('/api/v1/cart/coupon', ['code' => $coupon->code], $token)
            ->assertUnprocessable()->assertJsonPath('errors.code', $code);
    }
});

it('caps the fixed discount and holds and redeems a customer coupon exactly once', function () {
    $variant = couponVariant(800);
    $cart = Cart::factory()->create();
    CartItem::factory()->create(['cart_id' => $cart->id, 'product_variant_id' => $variant->id]);
    $coupon = Coupon::factory()->create(['per_customer_limit' => 1, 'value' => 1200]);
    $quote = app(CartPricing::class)->quote($cart);
    $service = app(CouponService::class);
    $reference = (string) Str::ulid();
    $expiry = now()->addMinutes(15);
    $claim = $service->reserve($coupon, $cart, $quote, $reference, $expiry);
    expect($claim->discount_amount)->toBe(800);
    expect($service->reserve($coupon, $cart, $quote, $reference, $expiry)->id)->toBe($claim->id);
    expect($service->redeem($reference)->status)->toBe(CouponUsageStatus::Redeemed);
    expect($service->redeem($reference)->id)->toBe($claim->id);

    try {
        $service->reserve($coupon, $cart, $quote, (string) Str::ulid(), $expiry);
        test()->fail('A redeemed per-customer limit was ignored.');
    } catch (ApiProblem $problem) {
        expect($problem->errorCode)->toBe('COUPON_CUSTOMER_LIMIT');
    }
    $this->assertDatabaseCount('coupon_redemptions', 1);
});

<?php

use App\Enums\ProductStatus;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Category;
use App\Models\Coupon;
use App\Models\CouponRedemption;
use App\Models\Inventory;
use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Support\Facades\Concurrency;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\CouponRaceWorker;

it('holds the last coupon redemption only once under MySQL contention', function () {
    if (DB::getDriverName() !== 'mysql') {
        $this->markTestSkipped('Requires the disposable MySQL CI database.');
    }

    $product = Product::factory()->create(['status' => ProductStatus::Active]);
    $product->categories()->attach(Category::factory()->create());
    $variant = ProductVariant::factory()->for($product)->create();
    Inventory::factory()->for($variant, 'variant')->create(['on_hand' => 4]);
    [$one, $two] = Cart::factory()->count(2)->create()->all();
    foreach ([$one, $two] as $cart) {
        CartItem::factory()->create(['cart_id' => $cart->id, 'product_variant_id' => $variant->id]);
    }
    $coupon = Coupon::factory()->create(['global_limit' => 1]);
    $path = sys_get_temp_dir().'/coupon-race-'.Str::random(20);
    mkdir($path, 0700);

    try {
        $results = Concurrency::driver('process')->run([
            CouponRaceWorker::make($path, 'one', $coupon->id, $one->id),
            CouponRaceWorker::make($path, 'two', $coupon->id, $two->id),
        ]);
        sort($results);
        expect($results)->toBe(['COUPON_EXHAUSTED', 'held']);
        expect(CouponRedemption::query()->where('coupon_id', $coupon->id)->count())->toBe(1);
    } finally {
        foreach (glob($path.'/*') ?: [] as $marker) {
            unlink($marker);
        }
        rmdir($path);
    }
});

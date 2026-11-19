<?php

use App\Enums\ProductStatus;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Category;
use App\Models\CustomerAddress;
use App\Models\Inventory;
use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Support\Facades\Concurrency;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\CheckoutRaceWorker;

it('creates only one payable checkout when two MySQL customers compete for the last unit', function () {
    if (DB::getDriverName() !== 'mysql') {
        $this->markTestSkipped('Requires the disposable MySQL CI database.');
    }

    $product = Product::factory()->create(['status' => ProductStatus::Active]);
    $product->categories()->attach(Category::factory()->create());
    $variant = ProductVariant::factory()->for($product)->create();
    $stock = Inventory::factory()->for($variant, 'variant')->create(['on_hand' => 1]);
    $path = sys_get_temp_dir().'/checkout-race-'.Str::random(20);
    mkdir($path, 0700);
    $workers = [];

    foreach (['one', 'two'] as $slot) {
        $cart = Cart::factory()->create();
        CartItem::factory()->create(['cart_id' => $cart->id, 'product_variant_id' => $variant->id]);
        $address = CustomerAddress::factory()->create(['user_id' => $cart->user_id]);
        $workers[] = CheckoutRaceWorker::make($path, $slot, $cart->user_id, $address->public_id);
    }

    try {
        $results = Concurrency::driver('process')->run($workers);
        sort($results);
        expect($results)->toBe(['INSUFFICIENT_STOCK', 'pending_order']);
        expect($stock->refresh())->on_hand->toBe(1)->reserved->toBe(1);
        expect(DB::table('order_items')->where('product_variant_id', $variant->id)->count())->toBe(1);
    } finally {
        foreach (glob($path.'/*') ?: [] as $marker) {
            unlink($marker);
        }
        rmdir($path);
    }
});

<?php

namespace Database\Seeders;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\CheckoutAttempt;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Services\Cart\CartService;
use App\Services\Checkout\ShippingSettings;
use App\Services\Inventory\InventoryService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class DemoOrderSeeder extends Seeder
{
    public function run(CartService $carts, InventoryService $inventory, ShippingSettings $shipping): void
    {
        if (! app()->environment('local', 'testing')) {
            return;
        }

        $customer = User::query()->customers()->where('email', 'customer@demo.test')->firstOrFail();
        foreach ([
            ['weekend-poplin-shirt', OrderStatus::Delivered, 900001],
            ['studio-graphic-tee', OrderStatus::Cancelled, 900002],
        ] as [$slug, $status, $sequence]) {
            DB::transaction(function () use ($customer, $slug, $status, $sequence, $carts, $inventory, $shipping): void {
                User::query()->whereKey($customer->id)->lockForUpdate()->firstOrFail();
                $key = hash('sha256', 'demo-order:'.$slug);
                if (CheckoutAttempt::query()->where('user_id', $customer->id)->where('idempotency_hash', $key)->exists()) {
                    return;
                }

                $product = Product::query()->where('slug', $slug)->firstOrFail();
                $variant = $product->variants()->where('is_default', true)->firstOrFail();
                $expires = now()->addMinutes(15);
                $reservation = $inventory->reserve((string) Str::ulid(), [$variant->id => 1], $expires);
                $captured = $status === OrderStatus::Delivered;
                if ($captured) {
                    $inventory->commit($reservation->public_id, 'demo-settlement:'.$key);
                    DB::table('product_metrics')->insertOrIgnore(['product_id' => $product->id]);
                    DB::table('product_metrics')->where('product_id', $product->id)->increment('paid_quantity');
                } else {
                    $inventory->release($reservation->public_id);
                }

                $attempt = CheckoutAttempt::factory()->create([
                    'user_id' => $customer->id, 'cart_id' => $carts->forCustomer($customer)->id,
                    'stock_reservation_id' => $reservation->id, 'idempotency_hash' => $key, 'expires_at' => $expires,
                ]);
                $subtotal = $product->sale_amount ?? $product->regular_amount;
                $shippingAmount = $shipping->amount();
                $total = $subtotal + $shippingAmount;
                $order = Order::factory()->for($attempt, 'checkoutAttempt')->create([
                    'user_id' => $customer->id, 'number' => sprintf('ORD-%04d-%06d', now()->year, $sequence),
                    'status' => $status, 'subtotal_amount' => $subtotal, 'shipping_amount' => $shippingAmount, 'total_amount' => $total,
                    'paid_at' => $captured ? now() : null, 'shipping_carrier' => $captured ? 'Demo carrier' : null,
                    'tracking_number' => $captured ? 'DEMO-900001' : null,
                ]);
                $order->payment()->forceCreate([
                    'currency' => 'USD', 'expected_amount' => $total, 'captured_amount' => $captured ? $total : 0,
                    'status' => $captured ? PaymentStatus::Succeeded : PaymentStatus::Failed,
                    'captured_at' => $captured ? now() : null,
                ]);

                DB::table('order_addresses')->insert([
                    'order_id' => $order->id, 'recipient' => 'Demo Customer', 'line1' => '100 Demo Street',
                    'city' => 'Austin', 'region' => 'TX', 'postal_code' => '78701', 'country' => 'US',
                ]);
                DB::table('order_items')->insert([
                    'order_id' => $order->id, 'product_id' => $product->id, 'product_variant_id' => $variant->id,
                    'product_public_id' => $product->public_id, 'variant_public_id' => $variant->public_id,
                    'product_name' => $product->name, 'sku' => $variant->sku, 'options' => '[]', 'quantity' => 1,
                    'unit_amount' => $subtotal, 'line_amount' => $subtotal,
                ]);

                $previous = 'pending';
                $history = $captured ? ['paid', 'processing', 'shipped', 'delivered'] : ['cancelled'];
                foreach ($history as $state) {
                    DB::table('order_status_histories')->insert([
                        'order_id' => $order->id, 'actor_type' => 'system', 'from_status' => $previous,
                        'to_status' => $state, 'reason' => 'Local demo fixture',
                        'created_at' => now(), 'notified_at' => now(),
                    ]);
                    $previous = $state;
                }
            }, 5);
        }
    }
}

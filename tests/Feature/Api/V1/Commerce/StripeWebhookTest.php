<?php

use App\Enums\CouponUsageStatus;
use App\Jobs\ProcessStripeWebhook;
use App\Jobs\ReconcileOrderPayment;
use App\Jobs\SendOrderStatusNotice;
use App\Models\Coupon;
use App\Models\Order;
use App\Models\WebhookEvent;
use App\Notifications\OrderStatusNotice;
use App\Services\Inventory\InventoryService;
use App\Services\Payments\StripePayments;
use App\Services\Payments\StripeSettlement;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Stripe\PaymentIntent;

function stripeEventBody(string $eventId, string $type): string
{
    return json_encode([
        'id' => $eventId,
        'object' => 'event',
        'type' => $type,
        'livemode' => false,
        'data' => ['object' => ['id' => 'pi_test_fixture', 'object' => 'payment_intent']],
    ], JSON_THROW_ON_ERROR);
}

function stripeTestSignature(string $body, string $secret): string
{
    $timestamp = time();

    return 't='.$timestamp.',v1='.hash_hmac('sha256', $timestamp.'.'.$body, $secret);
}

it('verifies raw webhook signatures and settles duplicate and out-of-order events only once', function () {
    $secret = 'whsec_test_fixture_only';
    config(['services.stripe.webhook_secret' => $secret, 'checkout.flat_shipping_amount' => 300]);
    Bus::fake([ProcessStripeWebhook::class, SendOrderStatusNotice::class]);
    Notification::fake();
    [$customer, $address, $product, $stock, $cart] = checkoutFixture();
    $coupon = Coupon::factory()->create(['global_limit' => 1]);
    $cart->forceFill(['coupon_id' => $coupon->id])->save();

    $gateway = $this->mock(StripePayments::class);
    $gateway->shouldReceive('ensureConfigured')->once();
    $gateway->shouldReceive('createIntent')->once()->andReturnUsing(fn (string $id, int $amount) => checkoutIntent($id, $amount));
    $gateway->shouldReceive('getIntent')->twice()->andReturnUsing(function (): PaymentIntent {
        $order = Order::query()->firstOrFail();

        return checkoutIntent($order->checkoutAttempt->public_id, $order->total_amount, 'succeeded');
    });
    $this->withToken($customer->createToken('customer')->plainTextToken)
        ->postJson('/api/v1/checkout', ['address_id' => $address->public_id], ['Idempotency-Key' => 'settle-once'])->assertOk();
    $this->flushHeaders();
    $body = stripeEventBody('evt_succeeded', 'payment_intent.succeeded');
    $this->call('POST', '/api/v1/webhooks/stripe', server: ['CONTENT_TYPE' => 'application/json', 'HTTP_STRIPE_SIGNATURE' => 'invalid'], content: $body)
        ->assertBadRequest()->assertJsonPath('errors.code', 'STRIPE_SIGNATURE_INVALID');
    $this->assertDatabaseCount('webhook_events', 0);

    foreach ([$body, $body, stripeEventBody('evt_late_failure', 'payment_intent.payment_failed')] as $payload) {
        $this->call('POST', '/api/v1/webhooks/stripe', server: [
            'CONTENT_TYPE' => 'application/json', 'HTTP_STRIPE_SIGNATURE' => stripeTestSignature($payload, $secret),
        ], content: $payload)->assertNoContent();
    }
    $this->assertDatabaseCount('webhook_events', 2);
    $handler = app(StripeSettlement::class);
    foreach (WebhookEvent::query()->orderByDesc('id')->get() as $event) {
        $handler->handle($event->id);
        $handler->handle($event->id);
    }

    $order = Order::query()->firstOrFail();
    $this->assertDatabaseHas('orders', ['id' => $order->id, 'status' => 'paid']);
    $this->assertDatabaseHas('payments', ['order_id' => $order->id, 'status' => 'succeeded', 'captured_amount' => $order->total_amount]);
    $this->assertDatabaseHas('coupon_redemptions', ['order_id' => $order->id, 'status' => CouponUsageStatus::Redeemed->value]);
    $this->assertDatabaseHas('product_metrics', ['product_id' => $product->id, 'paid_quantity' => 1]);
    expect($stock->refresh())->on_hand->toBe(1)->reserved->toBe(0);
    $this->assertDatabaseCount('inventory_movements', 1);
    $this->assertDatabaseCount('cart_items', 0);

    $mail = new SendOrderStatusNotice(DB::table('order_status_histories')->value('id'));
    $mail->handle();
    $mail->handle();
    Notification::assertSentToTimes($customer, OrderStatusNotice::class, 1);
});

it('does not mark failed payment as paid and releases the reservation', function () {
    config(['checkout.flat_shipping_amount' => 300]);
    Bus::fake([SendOrderStatusNotice::class]);
    [$customer, $address, , $stock] = checkoutFixture();
    $stripe = $this->mock(StripePayments::class);
    $stripe->shouldReceive('ensureConfigured')->once();
    $stripe->shouldReceive('createIntent')->once()->andReturnUsing(fn (string $id, int $amount) => checkoutIntent($id, $amount));
    $stripe->shouldReceive('getIntent')->once()->andReturnUsing(function (): PaymentIntent {
        $order = Order::query()->firstOrFail();

        return checkoutIntent($order->checkoutAttempt->public_id, $order->total_amount);
    });

    $this->withToken($customer->createToken('customer')->plainTextToken)
        ->postJson('/api/v1/checkout', ['address_id' => $address->public_id], ['Idempotency-Key' => 'declined'])->assertOk();
    $event = WebhookEvent::factory()->create(['type' => 'payment_intent.payment_failed', 'object_id' => 'pi_test_fixture']);
    app(StripeSettlement::class)->handle($event->id);

    $this->assertDatabaseHas('orders', ['status' => 'cancelled', 'paid_at' => null]);
    $this->assertDatabaseHas('payments', ['status' => 'failed', 'captured_amount' => 0]);
    expect($stock->refresh())->on_hand->toBe(2)->reserved->toBe(0);
});

it('sends an unfulfillable late payment to reconciliation without overselling', function () {
    config(['checkout.flat_shipping_amount' => 300]);
    Bus::fake([ReconcileOrderPayment::class, SendOrderStatusNotice::class]);
    $this->freezeTime();
    [$customer, $address, , $stock] = checkoutFixture(stock: 1);
    $stripe = $this->mock(StripePayments::class);
    $stripe->shouldReceive('ensureConfigured')->once();
    $stripe->shouldReceive('createIntent')->once()->andReturnUsing(fn (string $id, int $amount) => checkoutIntent($id, $amount));
    $stripe->shouldReceive('getIntent')->once()->andReturnUsing(function (): PaymentIntent {
        $order = Order::query()->firstOrFail();

        return checkoutIntent($order->checkoutAttempt->public_id, $order->total_amount, 'succeeded');
    });

    $this->withToken($customer->createToken('customer')->plainTextToken)
        ->postJson('/api/v1/checkout', ['address_id' => $address->public_id], ['Idempotency-Key' => 'late'])->assertOk();
    $this->travel(16)->minutes();
    $inventory = app(InventoryService::class);
    $inventory->expireDue();
    $inventory->reserve((string) Str::ulid(), [$stock->product_variant_id => 1], now()->addMinutes(15));
    $event = WebhookEvent::factory()->create(['object_id' => 'pi_test_fixture']);
    app(StripeSettlement::class)->handle($event->id);

    $this->assertDatabaseHas('payments', ['status' => 'payment_exception', 'exception_code' => 'INSUFFICIENT_STOCK']);
    $this->assertDatabaseHas('orders', ['status' => 'pending', 'paid_at' => null]);
    expect($stock->refresh())->on_hand->toBe(1)->reserved->toBe(1);
    $this->assertDatabaseCount('inventory_movements', 0);
});

<?php

use App\Jobs\SendOrderRefundNotice;
use App\Jobs\SendOrderStatusNotice;
use App\Jobs\SubmitStripeRefund;
use App\Models\Order;
use App\Models\OrderRefund;
use App\Models\User;
use App\Models\WebhookEvent;
use App\Notifications\OrderRefundNotice;
use App\Services\Payments\OrderRefunds;
use App\Services\Payments\StripePayments;
use App\Services\Payments\StripeSettlement;
use Database\Seeders\StaffAccessSeeder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Notification;
use Stripe\PaymentIntent;
use Stripe\Refund;

function confirmedOrderGateway(object $gateway): void
{
    $gateway->shouldReceive('ensureConfigured')->once();
    $gateway->shouldReceive('createIntent')->once()->andReturnUsing(fn (string $id, int $amount) => checkoutIntent($id, $amount));
    $gateway->shouldReceive('getIntent')->once()->andReturnUsing(function (): PaymentIntent {
        $order = Order::query()->firstOrFail();

        return checkoutIntent($order->checkoutAttempt->public_id, $order->total_amount, 'succeeded');
    });
}

it('keeps orders private and rejects illegal fulfilment transitions', function () {
    config(['checkout.flat_shipping_amount' => 300]);
    $this->seed(StaffAccessSeeder::class);
    Bus::fake([SendOrderStatusNotice::class]);
    [$customer, $address] = checkoutFixture();
    confirmedOrderGateway($this->mock(StripePayments::class));
    $headers = ['Idempotency-Key' => 'fulfilment'];
    $id = $this->withToken($customer->createToken('customer')->plainTextToken)
        ->postJson('/api/v1/checkout', ['address_id' => $address->public_id], $headers)->assertOk()->json('data.order_id');
    app(StripeSettlement::class)->handle(WebhookEvent::factory()->create(['object_id' => 'pi_test_fixture'])->id);

    Auth::forgetGuards();
    $this->withToken(User::factory()->create()->createToken('other')->plainTextToken)->getJson('/api/v1/orders/'.$id)->assertNotFound();
    Auth::forgetGuards();
    $this->withToken($customer->createToken('owner')->plainTextToken)->getJson('/api/v1/orders/'.$id)
        ->assertOk()->assertJsonPath('data.status', 'paid')
        ->assertJsonPath('data.items.0.product_name', 'Original shirt')
        ->assertJsonPath('data.address.line1', '100 Original Street');

    Auth::forgetGuards();
    $this->withToken(catalogStaffToken('order-manager'));
    $route = '/api/v1/admin/orders/'.$id.'/transitions';
    $this->postJson($route, ['status' => 'delivered'])
        ->assertConflict()->assertJsonPath('errors.code', 'ORDER_TRANSITION_INVALID');
    Auth::forgetGuards();
    $this->postJson($route, ['status' => 'processing'])->assertOk()->assertJsonPath('data.status', 'processing');
    Auth::forgetGuards();
    $this->postJson($route, ['status' => 'shipped'])->assertUnprocessable()
        ->assertJsonValidationErrors(['shipping_carrier', 'tracking_number'], 'errors.fields');
    Auth::forgetGuards();
    $this->postJson($route, ['status' => 'shipped', 'shipping_carrier' => 'Carrier', 'tracking_number' => 'LOCAL123'])
        ->assertOk()->assertJsonPath('data.tracking_number', 'LOCAL123');
    Auth::forgetGuards();
    $this->postJson($route, ['status' => 'delivered'])->assertOk()->assertJsonPath('data.status', 'delivered');
});

it('restores inventory once only after a paid cancellation refund succeeds', function () {
    config(['checkout.flat_shipping_amount' => 300]);
    $this->seed(StaffAccessSeeder::class);
    Bus::fake([SendOrderStatusNotice::class, SendOrderRefundNotice::class, SubmitStripeRefund::class]);
    Notification::fake();
    [$customer, $address, $product, $stock] = checkoutFixture();
    $gateway = $this->mock(StripePayments::class);
    confirmedOrderGateway($gateway);
    $gateway->shouldReceive('refund')->once()->andReturnUsing(fn (string $intent, string $refund, int $amount) => Refund::constructFrom([
        'id' => 're_fixture',
        'amount' => $amount,
        'currency' => 'usd',
        'payment_intent' => $intent,
        'status' => 'succeeded',
        'metadata' => ['refund_id' => $refund],
    ]));
    $id = $this->withToken($customer->createToken('customer')->plainTextToken)
        ->postJson('/api/v1/checkout', ['address_id' => $address->public_id], ['Idempotency-Key' => 'paid-cancel'])->assertOk()->json('data.order_id');
    app(StripeSettlement::class)->handle(WebhookEvent::factory()->create(['object_id' => 'pi_test_fixture'])->id);
    expect($stock->refresh()->on_hand)->toBe(1);

    Auth::forgetGuards();
    $this->withToken(catalogStaffToken('order-manager'));
    $route = '/api/v1/admin/orders/'.$id.'/cancel';
    $headers = ['Idempotency-Key' => 'refund-cancel'];
    $body = ['reason' => 'Cancelled before shipping'];
    $result = $this->postJson($route, $body, $headers)->assertAccepted()->assertJsonPath('data.status', 'pending');
    $refund = OrderRefund::query()->where('public_id', $result->json('data.id'))->firstOrFail();
    expect($stock->refresh()->on_hand)->toBe(1);
    $this->assertDatabaseHas('orders', ['status' => 'paid']);

    $handler = app(OrderRefunds::class);
    $handler->submit($refund->id);
    $handler->submit($refund->id);
    $this->assertDatabaseHas('orders', ['status' => 'cancelled']);
    $this->assertDatabaseHas('payments', ['status' => 'refunded']);
    $this->assertDatabaseHas('product_metrics', ['product_id' => $product->id, 'paid_quantity' => 0]);
    expect($stock->refresh()->on_hand)->toBe(2);
    $this->assertDatabaseCount('inventory_movements', 2);

    $mail = new SendOrderRefundNotice($refund->id);
    $mail->handle();
    $mail->handle();
    Notification::assertSentToTimes($customer, OrderRefundNotice::class, 1);

    Auth::forgetGuards();
    $this->postJson($route, $body, $headers)->assertAccepted()->assertJsonPath('data.id', $refund->public_id);
    $this->assertDatabaseCount('refunds', 1);
});

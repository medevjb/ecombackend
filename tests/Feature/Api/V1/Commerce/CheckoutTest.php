<?php

use App\Exceptions\ApiProblem;
use App\Models\CustomerAddress;
use App\Models\Order;
use App\Services\Payments\StripePayments;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Stripe\PaymentIntent;

it('retries a pending checkout and uses immutable address and line snapshots', function () {
    config(['checkout.flat_shipping_amount' => 300]);
    [$customer, $address, $product, $stock] = checkoutFixture();
    $gateway = $this->mock(StripePayments::class);
    $gateway->shouldReceive('ensureConfigured')->times(4);
    $gateway->shouldReceive('createIntent')->once()->andThrow(new ApiProblem('STRIPE_UNAVAILABLE', 'Provider timeout.', 503));
    $gateway->shouldReceive('createIntent')->once()->andReturnUsing(fn (string $checkoutId, int $amount) => checkoutIntent($checkoutId, $amount));
    $gateway->shouldReceive('getIntent')->once()->andReturnUsing(function (): PaymentIntent {
        $order = Order::query()->firstOrFail();

        return checkoutIntent($order->checkoutAttempt->public_id, $order->total_amount);
    });
    $this->withToken($customer->createToken('customer')->plainTextToken);
    $data = ['address_id' => $address->public_id];
    $headers = ['Idempotency-Key' => 'checkout-case-1'];

    $this->postJson('/api/v1/checkout', $data, $headers)->assertServiceUnavailable();
    expect($stock->refresh())->on_hand->toBe(2)->reserved->toBe(1);
    $this->assertDatabaseCount('orders', 1);
    $product->update(['name' => 'New name', 'regular_amount' => 9000]);
    $address->update(['line1' => '200 Updated Street']);

    Auth::forgetGuards();
    $checkout = $this->postJson('/api/v1/checkout', $data, $headers)->assertOk()
        ->assertJsonPath('data.total_amount', 4500)->assertJsonPath('data.payment_status', 'pending');
    Auth::forgetGuards();
    $this->postJson('/api/v1/checkout', $data, $headers)->assertOk()
        ->assertJsonPath('data.order_id', $checkout->json('data.order_id'))
        ->assertJsonPath('data.client_secret', $checkout->json('data.client_secret'));

    Auth::forgetGuards();
    $different = CustomerAddress::factory()->create(['user_id' => $customer->id]);
    $this->postJson('/api/v1/checkout', ['address_id' => $different->public_id], $headers)
        ->assertConflict()->assertJsonPath('errors.code', 'IDEMPOTENCY_CONFLICT');

    $this->assertDatabaseHas('order_addresses', ['line1' => '100 Original Street']);
    $this->assertDatabaseHas('order_items', ['product_name' => 'Original shirt', 'unit_amount' => 4200]);
    expect(DB::table('order_sequences')->value('next_number'))->toBe(2);
});

it('rejects production Stripe keys and does not reserve stock without test-mode credentials', function () {
    config(['checkout.flat_shipping_amount' => 300, 'services.stripe.secret' => 'sk_live_fixture_never_used']);
    [$customer, $address, , $stock] = checkoutFixture();

    $this->withToken($customer->createToken('customer')->plainTextToken)
        ->postJson('/api/v1/checkout', ['address_id' => $address->public_id], ['Idempotency-Key' => 'unconfigured'])
        ->assertServiceUnavailable()->assertJsonPath('errors.code', 'STRIPE_TEST_MODE_NOT_CONFIGURED');

    expect($stock->refresh()->reserved)->toBe(0);
    $this->assertDatabaseCount('orders', 0);
});

it('resumes an owned pending order without the original checkout key', function () {
    config(['checkout.flat_shipping_amount' => 300]);
    [$customer, $address, , $stock] = checkoutFixture();
    [$otherCustomer] = checkoutFixture();
    $gateway = $this->mock(StripePayments::class);
    $gateway->shouldReceive('ensureConfigured')->twice();
    $gateway->shouldReceive('createIntent')->once()->andReturnUsing(fn (string $checkoutId, int $amount) => checkoutIntent($checkoutId, $amount));
    $gateway->shouldReceive('getIntent')->once()->andReturnUsing(function (): PaymentIntent {
        $order = Order::query()->firstOrFail();

        return checkoutIntent($order->checkoutAttempt->public_id, $order->total_amount);
    });
    $token = $customer->createToken('customer')->plainTextToken;
    $checkout = $this->withToken($token)->postJson('/api/v1/checkout', ['address_id' => $address->public_id], ['Idempotency-Key' => 'original-tab'])
        ->assertOk()->json('data');
    $path = '/api/v1/orders/'.$checkout['order_id'].'/resume-checkout';

    Auth::forgetGuards();
    $this->withToken($otherCustomer->createToken('customer')->plainTextToken)->postJson($path)->assertNotFound();
    Auth::forgetGuards();
    $this->withToken($token)->postJson($path)->assertOk()
        ->assertJsonPath('data.checkout_id', $checkout['checkout_id'])
        ->assertJsonPath('data.client_secret', $checkout['client_secret'])
        ->assertJsonPath('data.total_amount', $checkout['total_amount']);

    $this->assertDatabaseCount('orders', 1);
    expect($stock->refresh()->reserved)->toBe(1);
});

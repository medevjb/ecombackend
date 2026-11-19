<?php

use App\Models\Order;
use App\Services\Payments\StripePayments;
use Database\Seeders\StaffAccessSeeder;
use Illuminate\Support\Facades\Auth;
use Stripe\PaymentIntent;

it('allows authorized staff to update shipping for new quotes while preserving an existing checkout', function () {
    config(['checkout.flat_shipping_amount' => null]);
    $this->seed(StaffAccessSeeder::class);
    [$customer, $address] = checkoutFixture();
    $customerToken = $customer->createToken('customer')->plainTextToken;
    $adminToken = catalogStaffToken('admin');
    $route = '/api/v1/admin/shipping-settings';
    $body = ['address_id' => $address->public_id];

    $this->withToken($adminToken)->getJson($route)->assertOk()
        ->assertJsonPath('data.flat_shipping_amount', 500)->assertJsonPath('data.currency', 'USD');

    Auth::forgetGuards();
    $gateway = $this->mock(StripePayments::class);
    $gateway->shouldReceive('ensureConfigured')->twice();
    $gateway->shouldReceive('createIntent')->once()
        ->andReturnUsing(fn (string $id, int $amount) => checkoutIntent($id, $amount));
    $gateway->shouldReceive('getIntent')->once()->andReturnUsing(function (): PaymentIntent {
        $order = Order::query()->firstOrFail();

        return checkoutIntent($order->checkoutAttempt->public_id, $order->total_amount);
    });
    $headers = ['Idempotency-Key' => 'before-shipping-change'];
    $this->withToken($customerToken)->postJson('/api/v1/checkout', $body, $headers)->assertOk()
        ->assertJsonPath('data.total_amount', 4700);

    Auth::forgetGuards();
    $this->withToken($adminToken)->patchJson($route, ['flat_shipping_amount' => 0])->assertOk()
        ->assertJsonPath('data.flat_shipping_amount', 0);
    $this->assertDatabaseHas('tyro_audit_logs', ['event' => 'checkout.shipping.updated']);

    Auth::forgetGuards();
    $this->withToken($customerToken)->postJson('/api/v1/checkout/preview', $body)->assertOk()
        ->assertJsonPath('data.shipping_amount', 0)->assertJsonPath('data.total_amount', 4200);
    Auth::forgetGuards();
    $this->postJson('/api/v1/checkout', $body, $headers)->assertOk()
        ->assertJsonPath('data.total_amount', 4700);
});

it('requires the shipping permission and validates the rate in cents', function () {
    $this->seed(StaffAccessSeeder::class);
    $route = '/api/v1/admin/shipping-settings';
    $this->patchJson($route, ['flat_shipping_amount' => 700])->assertUnauthorized();
    Auth::forgetGuards();
    $this->withToken(catalogStaffToken('support'))->patchJson($route, ['flat_shipping_amount' => 700])->assertForbidden();
    Auth::forgetGuards();
    $this->withToken(catalogStaffToken('admin'))->patchJson($route, ['flat_shipping_amount' => -1])
        ->assertUnprocessable()->assertJsonValidationErrors(['flat_shipping_amount'], 'errors.fields');
});

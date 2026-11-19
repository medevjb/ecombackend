<?php

use App\Enums\OrderStatus;
use App\Enums\RefundStatus;
use App\Models\Order;
use App\Models\OrderRefund;
use App\Models\Payment;
use Database\Seeders\StaffAccessSeeder;
use Illuminate\Support\Facades\Auth;

it('reports captured payments and completed refunds on their own UTC days', function () {
    $this->travelTo(now('UTC')->setDate(2026, 11, 19)->setTime(0, 5));
    $this->seed(StaffAccessSeeder::class);
    $order = Order::factory()->create(['created_at' => '2026-11-18 23:59:59', 'status' => OrderStatus::Paid]);
    Payment::factory()->for($order)->create(['captured_amount' => 2500, 'captured_at' => '2026-11-18 23:59:59']);
    OrderRefund::factory()->for($order)->create(['amount' => 700, 'completed_at' => '2026-11-19 00:00:00', 'status' => RefundStatus::Succeeded]);
    OrderRefund::factory()->for($order)->create(['amount' => 500]);

    $this->withToken(catalogStaffToken('order-manager'))
        ->getJson('/api/v1/admin/reports/sales?from=2026-11-18&to=2026-11-19')
        ->assertOk()->assertJsonPath('data.totals.net_revenue_amount', 1800)
        ->assertJsonPath('data.daily.0.orders', 1)->assertJsonPath('data.daily.0.net_revenue_amount', 2500)
        ->assertJsonPath('data.daily.1.orders', 0)->assertJsonPath('data.daily.1.net_revenue_amount', -700)
        ->assertJsonPath('data.order_status.paid', 1);

    Auth::forgetGuards();
    $this->getJson('/api/v1/admin/dashboard')->assertOk()->assertJsonPath('data.timezone', 'UTC')
        ->assertJsonPath('data.today.net_revenue_amount', -700)->assertJsonCount(30, 'data.sales_series')
        ->assertJsonPath('data.sales_series.0.net_revenue_amount', 0);
});

it('enforces report permissions and bounds the date range', function () {
    $this->seed(StaffAccessSeeder::class);
    $this->withToken(catalogStaffToken('support'))->getJson('/api/v1/admin/dashboard')->assertForbidden();
    Auth::forgetGuards();
    $this->withToken(catalogStaffToken('admin'))
        ->getJson('/api/v1/admin/reports/coupons?from=2024-11-01&to=2026-11-01')
        ->assertUnprocessable()->assertJsonValidationErrors(['to'], 'errors.fields');

    Auth::forgetGuards();
    $this->getJson('/api/v1/admin/reports/coupons')->assertOk()->assertJsonCount(0, 'data');
    Auth::forgetGuards();
    $this->getJson('/api/v1/admin/reports/sales?group_by=category')->assertOk()->assertJsonCount(0, 'data.items');
});

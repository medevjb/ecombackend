<?php

namespace App\Services\Orders;

use App\Enums\OrderStatus;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class SalesReporting
{
    /** @return array{currency: string, timezone: string, totals: array<string, int>, daily: list<array<string, int|string>>, order_status: array<string, int>} */
    public function summarize(Carbon $from, Carbon $until): array
    {
        $orders = DB::table('orders')->where('created_at', '>=', $from)->where('created_at', '<', $until);
        $orderCounts = (clone $orders)->selectRaw('DATE(created_at) AS day, COUNT(*) AS orders')
            ->groupByRaw('DATE(created_at)')->pluck('orders', 'day');
        $captured = DB::table('payments')->where('captured_at', '>=', $from)->where('captured_at', '<', $until)
            ->selectRaw('DATE(captured_at) AS day, SUM(captured_amount) AS amount')
            ->groupByRaw('DATE(captured_at)')->pluck('amount', 'day');
        $refunds = DB::table('refunds')->where('completed_at', '>=', $from)->where('completed_at', '<', $until)
            ->selectRaw('DATE(completed_at) AS day, SUM(amount) AS amount')
            ->groupByRaw('DATE(completed_at)')->pluck('amount', 'day');
        $statuses = $orders->selectRaw('status, COUNT(*) AS count')->groupBy('status')->pluck('count', 'status');
        $totals = ['orders' => 0, 'gross_captured_amount' => 0, 'refunded_amount' => 0, 'net_revenue_amount' => 0];
        $daily = [];
        for ($day = $from->copy(); $day->lt($until); $day->addDay()) {
            $date = $day->toDateString();
            $gross = (int) $captured->get($date, 0);
            $refunded = (int) $refunds->get($date, 0);
            $row = [
                'orders' => (int) $orderCounts->get($date, 0),
                'gross_captured_amount' => $gross,
                'refunded_amount' => $refunded,
                'net_revenue_amount' => $gross - $refunded,
            ];
            foreach ($row as $key => $amount) {
                $totals[$key] += $amount;
            }
            $daily[] = ['date' => $date] + $row;
        }

        return [
            'timezone' => 'UTC', 'currency' => 'USD', 'totals' => $totals, 'daily' => $daily,
            'order_status' => collect(OrderStatus::cases())->mapWithKeys(fn (OrderStatus $status): array => [
                $status->value => (int) $statuses->get($status->value, 0),
            ])->all(),
        ];
    }
}

<?php

namespace App\Jobs;

use App\Models\Order;
use App\Notifications\OrderStatusNotice;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\DB;

class SendOrderStatusNotice implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    /** @var list<int> */
    public array $backoff = [30, 120, 300, 900];

    public function __construct(public readonly int $historyId) {}

    /** @return list<WithoutOverlapping> */
    public function middleware(): array
    {
        return [new WithoutOverlapping('order-mail:'.$this->historyId)->releaseAfter(30)->expireAfter(120)];
    }

    public function handle(): void
    {
        $history = DB::table('order_status_histories')->where('id', $this->historyId)->first();
        if ($history === null || $history->notified_at !== null) {
            return;
        }

        $order = Order::query()->with('user')->whereKey($history->order_id)->firstOrFail();
        $order->user->notifyNow(new OrderStatusNotice($order->number, $history->to_status));
        DB::table('order_status_histories')->where('id', $this->historyId)->update(['notified_at' => now()]);
    }
}

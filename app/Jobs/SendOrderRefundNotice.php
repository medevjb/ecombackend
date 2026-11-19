<?php

namespace App\Jobs;

use App\Models\OrderRefund;
use App\Notifications\OrderRefundNotice;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;

class SendOrderRefundNotice implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    /** @var list<int> */
    public array $backoff = [30, 120, 300, 900];

    public function __construct(public readonly int $refundId) {}

    /** @return list<WithoutOverlapping> */
    public function middleware(): array
    {
        return [new WithoutOverlapping('refund-mail:'.$this->refundId)->releaseAfter(30)->expireAfter(120)];
    }

    public function handle(): void
    {
        $refund = OrderRefund::query()->with('order.user')->whereKey($this->refundId)->firstOrFail();
        if ($refund->notified_at === null) {
            $refund->order->user->notifyNow(new OrderRefundNotice($refund->order->number, $refund->status->value));
            $refund->forceFill(['notified_at' => now()])->save();
        }
    }
}

<?php

namespace App\Jobs;

use App\Services\Payments\OrderRefunds;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ReconcileOrderPayment implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 6;

    public int $uniqueFor = 1800;

    /** @var list<int> */
    public array $backoff = [15, 60, 180, 600, 1200];

    public function __construct(public readonly int $orderId) {}

    public function uniqueId(): string
    {
        return (string) $this->orderId;
    }

    public function handle(OrderRefunds $refunds): void
    {
        $refunds->reconcile($this->orderId);
    }
}

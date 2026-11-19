<?php

namespace App\Jobs;

use App\Services\Payments\StripeSettlement;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ProcessStripeWebhook implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 6;

    public int $uniqueFor = 900;

    /** @var list<int> */
    public array $backoff = [10, 30, 120, 300, 900];

    public function __construct(public readonly int $eventId) {}

    public function uniqueId(): string
    {
        return (string) $this->eventId;
    }

    public function handle(StripeSettlement $settlement): void
    {
        $settlement->handle($this->eventId);
    }
}

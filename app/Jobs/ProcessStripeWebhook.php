<?php

namespace App\Jobs;

use App\Models\WebhookEvent;
use App\Services\Payments\StripeSettlement;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

class ProcessStripeWebhook implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 6;

    public int $uniqueFor = 3600;

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

    public function failed(?Throwable $error): void
    {
        $changed = WebhookEvent::query()->whereKey($this->eventId)->whereNull('processed_at')
            ->update(['failed_at' => now()]);
        if ($changed > 0) {
            Log::error('Stripe webhook processing exhausted its retries.', [
                'receipt_id' => $this->eventId,
                'exception_type' => $error !== null ? $error::class : null,
            ]);
        }
    }
}

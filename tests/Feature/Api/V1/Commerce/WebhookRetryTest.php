<?php

use App\Jobs\ProcessStripeWebhook;
use App\Models\WebhookEvent;
use Illuminate\Support\Facades\Bus;

it('redispatches stale webhook receipts without repeatedly enqueueing failed or healthy receipts', function () {
    Bus::fake([ProcessStripeWebhook::class]);
    $pending = WebhookEvent::factory()->create(['created_at' => now()->subMinutes(10)]);
    $failed = WebhookEvent::factory()->create(['created_at' => now()->subMinutes(10)]);
    $recent = WebhookEvent::factory()->create();
    (new ProcessStripeWebhook($failed->id))->failed(new RuntimeException('Transient provider failure'));

    $this->artisan('payments:redispatch-receipts')->assertSuccessful();
    $this->artisan('payments:redispatch-receipts')->assertSuccessful();

    Bus::assertDispatchedTimes(ProcessStripeWebhook::class, 1);
    Bus::assertDispatched(ProcessStripeWebhook::class, fn (ProcessStripeWebhook $job): bool => $job->eventId === $pending->id);
    expect($failed->refresh()->failed_at)->not->toBeNull();
    expect($recent->refresh()->getAttribute('last_redispatched_at'))->toBeNull();
});

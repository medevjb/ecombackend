<?php

use App\Jobs\ProcessStripeWebhook;
use App\Models\WebhookEvent;
use App\Services\Inventory\InventoryService;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('inventory:expire-reservations', function (InventoryService $inventory): void {
    $expired = $inventory->expireDue();
    if ($expired > 0) {
        Log::info('Expired stock reservations released.', ['count' => $expired]);
    }
    $this->info($expired.' reservations expired.');
})->purpose('Release expired stock reservations');

Artisan::command('payments:redispatch-receipts', function (): void {
    $receipts = WebhookEvent::query()->whereNull('processed_at')->whereNull('failed_at')
        ->where('created_at', '<=', now()->subMinutes(5))
        ->where(fn ($query) => $query->whereNull('last_redispatched_at')->orWhere('last_redispatched_at', '<=', now()->subHour()))
        ->orderBy('last_redispatched_at')->orderBy('id')->limit(100)->get(['id']);
    foreach ($receipts as $event) {
        ProcessStripeWebhook::dispatch($event->id);
        WebhookEvent::query()->whereKey($event->id)->update(['last_redispatched_at' => now()]);
    }
    $this->info($receipts->count().' receipts checked for redispatch.');
})->purpose('Retry durable Stripe receipts that have not been processed');

Artisan::command('payments:receipt-status', function (): void {
    $pending = WebhookEvent::query()->whereNull('processed_at');
    $this->table(['State', 'Count'], [
        ['Pending', (clone $pending)->whereNull('failed_at')->count()],
        ['Failed', $pending->whereNotNull('failed_at')->count()],
    ]);
})->purpose('Show durable Stripe receipt queue counts');

Schedule::command('payments:redispatch-receipts')->everyFiveMinutes()->withoutOverlapping(10);

Schedule::command('inventory:expire-reservations')->everyMinute()->withoutOverlapping(10);

Schedule::command('sanctum:prune-expired --hours=24')->daily();

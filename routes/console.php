<?php

use App\Jobs\ProcessStripeWebhook;
use App\Models\WebhookEvent;
use App\Services\Inventory\InventoryService;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('inventory:expire-reservations', function (InventoryService $inventory): void {
    $this->info($inventory->expireDue().' reservations expired.');
})->purpose('Release expired stock reservations');

Artisan::command('payments:redispatch-receipts', function (): void {
    WebhookEvent::query()->whereNull('processed_at')->orderBy('id')->limit(100)->get(['id'])
        ->each(fn (WebhookEvent $event) => ProcessStripeWebhook::dispatch($event->id));
})->purpose('Retry durable Stripe receipts that have not been processed');

Schedule::command('payments:redispatch-receipts')->everyFiveMinutes()->withoutOverlapping(10);

Schedule::command('inventory:expire-reservations')->everyMinute()->withoutOverlapping(10);

Schedule::command('sanctum:prune-expired --hours=24')->daily();

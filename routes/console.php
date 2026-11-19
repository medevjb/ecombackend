<?php

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

Schedule::command('inventory:expire-reservations')->everyMinute()->withoutOverlapping(10);

Schedule::command('sanctum:prune-expired --hours=24')->daily();

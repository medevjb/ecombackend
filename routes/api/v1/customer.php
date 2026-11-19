<?php

use App\Http\Controllers\Api\V1\Auth\CustomerAccountController;
use App\Http\Controllers\Api\V1\Auth\TokenController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:customer', 'can:customer-account'])->group(function (): void {
    Route::get('me', [TokenController::class, 'show'])->name('customer.me.show');
    Route::patch('me', [CustomerAccountController::class, 'update'])->name('customer.me.update');
});

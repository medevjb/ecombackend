<?php

use App\Http\Controllers\Api\V1\Auth\CustomerAccountController;
use App\Http\Controllers\Api\V1\Auth\TokenController;
use App\Http\Controllers\Api\V1\Customer\AddressController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:customer', 'can:customer-account'])->group(function (): void {
    Route::get('me', [TokenController::class, 'show'])->name('customer.me.show');
    Route::patch('me', [CustomerAccountController::class, 'update'])->name('customer.me.update');
    Route::get('me/addresses', [AddressController::class, 'index'])->name('customer.addresses.index');
    Route::post('me/addresses', [AddressController::class, 'store'])->name('customer.addresses.store');
    Route::patch('me/addresses/{address}', [AddressController::class, 'update'])->name('customer.addresses.update');
    Route::delete('me/addresses/{address}', [AddressController::class, 'destroy'])->name('customer.addresses.destroy');
});

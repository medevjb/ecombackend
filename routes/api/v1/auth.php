<?php

use App\Http\Controllers\Api\V1\Auth\CustomerAccountController;
use App\Http\Controllers\Api\V1\Auth\CustomerVerificationController;
use App\Http\Controllers\Api\V1\Auth\PasswordController;
use App\Http\Controllers\Api\V1\Auth\TokenController;
use Illuminate\Support\Facades\Route;

Route::prefix('auth')->name('auth.')->group(function (): void {
    Route::post('login', [TokenController::class, 'store'])->middleware('throttle:api-login')->name('login');
    Route::post('register', [CustomerAccountController::class, 'store'])->middleware('throttle:10,1')->name('register');
    Route::post('email/resend-verification', [CustomerVerificationController::class, 'resend'])
        ->middleware('throttle:customer-email-verification')->name('verification.resend');
    Route::post('email/verify-code', [CustomerVerificationController::class, 'verifyCode'])
        ->middleware('throttle:customer-email-verification')->name('verification.verify-code');
    Route::post('forgot-password', [PasswordController::class, 'forgot'])->middleware('throttle:customer-email-verification')->name('password.forgot');
    Route::post('forgot-password/verify-code', [PasswordController::class, 'verifyCode'])
        ->middleware('throttle:customer-email-verification')->name('password.verify-code');
    Route::post('reset-password', [PasswordController::class, 'reset'])->middleware('throttle:customer-recovery')->name('password.reset');
    Route::get('email/verify/{id}/{hash}', [CustomerVerificationController::class, 'verify'])
        ->middleware(['signed:relative', 'throttle:customer-recovery'])->name('verification.verify');

    Route::middleware('auth:sanctum')->group(function (): void {
        Route::get('me', [TokenController::class, 'show'])->name('me');
        Route::post('logout', [TokenController::class, 'destroy'])->name('logout');

    });

    Route::middleware(['auth:customer', 'can:customer-account'])->group(function (): void {
        Route::post('change-password', [PasswordController::class, 'update'])->middleware('throttle:customer-recovery')->name('password.change');
        Route::post('email/verification-notification', [CustomerVerificationController::class, 'send'])
            ->middleware('throttle:customer-recovery')->name('verification.send');
    });
});

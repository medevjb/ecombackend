<?php

use App\Http\Controllers\Api\V1\Webhooks\StripeWebhookController;
use Illuminate\Support\Facades\Route;

Route::post('webhooks/stripe', [StripeWebhookController::class, 'store'])
    ->middleware('throttle:stripe-webhooks')->name('webhooks.stripe');

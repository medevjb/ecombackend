<?php

use App\Http\Controllers\Api\V1\Webhooks\StripeWebhookController;
use App\Http\Middleware\ValidateApiJson;
use Illuminate\Support\Facades\Route;

Route::post('webhooks/stripe', [StripeWebhookController::class, 'store'])
    ->withoutMiddleware(ValidateApiJson::class)
    ->middleware('throttle:stripe-webhooks')->name('webhooks.stripe');

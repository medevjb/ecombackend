<?php

use App\Http\Controllers\Api\V1\StatusController;
use Illuminate\Support\Facades\Route;

Route::get('status', StatusController::class)->name('status');

require __DIR__.'/v1/auth.php';
require __DIR__.'/v1/customer.php';
require __DIR__.'/v1/admin.php';
require __DIR__.'/v1/storefront.php';

<?php

use App\Http\Controllers\ApiReferenceController;
use Illuminate\Support\Facades\Route;

Route::view('/', 'api-status')->withoutMiddleware('web')->name('home');

Route::get('/docs', [ApiReferenceController::class, 'index'])->name('api-reference');
Route::get('/docs/openapi.yaml', [ApiReferenceController::class, 'specification'])->name('api-reference.specification');

<?php

use Illuminate\Support\Facades\Route;

Route::view('/', 'api-status')->withoutMiddleware('web')->name('home');

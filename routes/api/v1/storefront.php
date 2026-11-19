<?php

use App\Http\Controllers\Api\V1\Storefront\CategoryController;
use App\Http\Controllers\Api\V1\Storefront\TaxonomyController;
use Illuminate\Support\Facades\Route;

Route::get('categories', [CategoryController::class, 'index'])->name('storefront.categories.index');
Route::get('categories/{slug}', [CategoryController::class, 'show'])->name('storefront.categories.show');

Route::get('brands', [TaxonomyController::class, 'brands'])->name('storefront.brands.index');
Route::get('attributes', [TaxonomyController::class, 'attributes'])->name('storefront.attributes.index');

<?php

use App\Http\Controllers\Api\V1\Storefront\CategoryController;
use App\Http\Controllers\Api\V1\Storefront\ProductController;
use App\Http\Controllers\Api\V1\Storefront\TaxonomyController;
use Illuminate\Support\Facades\Route;

Route::get('categories', [CategoryController::class, 'index'])->name('storefront.categories.index');
Route::get('categories/{slug}', [CategoryController::class, 'show'])->name('storefront.categories.show');

Route::get('brands', [TaxonomyController::class, 'brands'])->name('storefront.brands.index');
Route::get('attributes', [TaxonomyController::class, 'attributes'])->name('storefront.attributes.index');

Route::middleware('throttle:120,1')->group(function (): void {
    Route::get('products', [ProductController::class, 'index'])->name('storefront.products.index');
    Route::get('products/{slug}', [ProductController::class, 'show'])->name('storefront.products.show');
    Route::get('products/{slug}/related', [ProductController::class, 'related'])->name('storefront.products.related');
    Route::get('collections/new-arrivals', [ProductController::class, 'index'])->name('storefront.collections.new-arrivals');
    Route::get('collections/top-selling', [ProductController::class, 'index'])->name('storefront.collections.top-selling');
});

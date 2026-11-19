<?php

use App\Http\Controllers\Api\V1\Storefront\CartController;
use App\Http\Controllers\Api\V1\Storefront\CartCouponController;
use App\Http\Controllers\Api\V1\Storefront\CategoryController;
use App\Http\Controllers\Api\V1\Storefront\ContentController;
use App\Http\Controllers\Api\V1\Storefront\NewsletterController;
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

Route::prefix('cart')->name('storefront.cart.')->middleware('throttle:60,1')->group(function (): void {
    Route::get('/', [CartController::class, 'show'])->name('show');
    Route::post('items', [CartController::class, 'store'])->name('items.store');
    Route::patch('items/{item}', [CartController::class, 'update'])->name('items.update');
    Route::delete('items/{item}', [CartController::class, 'destroy'])->name('items.destroy');
    Route::delete('items', [CartController::class, 'clear'])->name('items.clear');
    Route::post('merge', [CartController::class, 'merge'])->middleware(['auth:customer', 'can:customer-account'])->name('merge');
    Route::post('coupon', [CartCouponController::class, 'store'])->middleware('throttle:coupon-apply')->name('coupon.store');
    Route::delete('coupon', [CartCouponController::class, 'destroy'])->name('coupon.destroy');
});

Route::prefix('storefront')->name('storefront.content.')->middleware('throttle:120,1')->group(function (): void {
    Route::get('home', [ContentController::class, 'home'])->name('home');
    Route::get('navigation', [ContentController::class, 'navigation'])->name('navigation');
    Route::get('settings', [ContentController::class, 'settings'])->name('settings');
});
Route::prefix('newsletter')->name('newsletter.')->middleware('throttle:5,1')->group(function (): void {
    Route::post('subscribe', [NewsletterController::class, 'subscribe'])->name('subscribe');
    Route::post('unsubscribe', [NewsletterController::class, 'unsubscribe'])->name('unsubscribe');
});

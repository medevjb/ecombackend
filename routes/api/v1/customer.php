<?php

use App\Http\Controllers\Api\V1\Auth\CustomerAccountController;
use App\Http\Controllers\Api\V1\Auth\TokenController;
use App\Http\Controllers\Api\V1\Customer\AddressController;
use App\Http\Controllers\Api\V1\Customer\CheckoutController;
use App\Http\Controllers\Api\V1\Customer\OrderController;
use App\Http\Controllers\Api\V1\Customer\WishlistController;
use App\Http\Controllers\Api\V1\Storefront\ReviewController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:customer', 'can:customer-account'])->group(function (): void {
    Route::get('me', [TokenController::class, 'show'])->name('customer.me.show');
    Route::patch('me', [CustomerAccountController::class, 'update'])->name('customer.me.update');
    Route::get('me/addresses', [AddressController::class, 'index'])->name('customer.addresses.index');
    Route::post('me/addresses', [AddressController::class, 'store'])->name('customer.addresses.store');
    Route::patch('me/addresses/{address}', [AddressController::class, 'update'])->name('customer.addresses.update');
    Route::delete('me/addresses/{address}', [AddressController::class, 'destroy'])->name('customer.addresses.destroy');
    Route::post('checkout/preview', [CheckoutController::class, 'preview'])->middleware('throttle:checkout')->name('customer.checkout.preview');
    Route::post('checkout', [CheckoutController::class, 'store'])->middleware('throttle:checkout')->name('customer.checkout.store');
    Route::get('me/wishlist', [WishlistController::class, 'index'])->name('customer.wishlist.index');
    Route::get('me/wishlist/{product}', [WishlistController::class, 'show'])->whereUlid('product')->name('customer.wishlist.show');
    Route::put('me/wishlist/{product}', [WishlistController::class, 'store'])->whereUlid('product')->name('customer.wishlist.store');
    Route::delete('me/wishlist/{product}', [WishlistController::class, 'destroy'])->whereUlid('product')->name('customer.wishlist.destroy');
    Route::get('orders', [OrderController::class, 'index'])->name('customer.orders.index');
    Route::get('orders/{order}', [OrderController::class, 'show'])->name('customer.orders.show');
    Route::post('orders/{order}/cancel', [OrderController::class, 'cancel'])->name('customer.orders.cancel');
});

Route::middleware(['auth:customer', 'can:customer-account', 'throttle:15,1'])->name('customer.reviews.')->group(function (): void {
    Route::get('me/product-reviews/{slug}', [ReviewController::class, 'mine'])->name('mine');
    Route::post('products/{slug}/reviews', [ReviewController::class, 'store'])->name('store');
    Route::patch('me/reviews/{review}', [ReviewController::class, 'update'])->name('update');
    Route::delete('me/reviews/{review}', [ReviewController::class, 'destroy'])->name('destroy');
});

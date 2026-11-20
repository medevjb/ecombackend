<?php

use App\Http\Controllers\Api\V1\Admin\AttributeController;
use App\Http\Controllers\Api\V1\Admin\AttributeValueController;
use App\Http\Controllers\Api\V1\Admin\AuditLogController;
use App\Http\Controllers\Api\V1\Admin\BrandController;
use App\Http\Controllers\Api\V1\Admin\CategoryController;
use App\Http\Controllers\Api\V1\Admin\CouponController;
use App\Http\Controllers\Api\V1\Admin\CustomerController;
use App\Http\Controllers\Api\V1\Admin\InventoryController;
use App\Http\Controllers\Api\V1\Admin\MediaUploadController;
use App\Http\Controllers\Api\V1\Admin\OrderController;
use App\Http\Controllers\Api\V1\Admin\ProductController;
use App\Http\Controllers\Api\V1\Admin\ProductImageController;
use App\Http\Controllers\Api\V1\Admin\ProductVariantController;
use App\Http\Controllers\Api\V1\Admin\ReportController;
use App\Http\Controllers\Api\V1\Admin\ReviewController;
use App\Http\Controllers\Api\V1\Admin\RoleManagementController;
use App\Http\Controllers\Api\V1\Admin\ShippingSettingsController;
use App\Http\Controllers\Api\V1\Admin\StaffManagementController;
use App\Http\Controllers\Api\V1\Admin\StorefrontContentController;
use App\Http\Controllers\Api\V1\Auth\PasswordController;
use App\Http\Controllers\Api\V1\Auth\StaffAccountController;
use App\Http\Controllers\Api\V1\Auth\StaffTwoFactorController;
use App\Http\Controllers\Api\V1\Auth\TokenController;
use Illuminate\Support\Facades\Route;

Route::prefix('admin')->name('admin.')->group(function (): void {
    Route::post('auth/forgot-password', [PasswordController::class, 'forgot'])
        ->middleware('throttle:customer-recovery')->name('password.forgot');
    Route::post('auth/reset-password', [PasswordController::class, 'reset'])
        ->middleware('throttle:customer-recovery')->name('password.reset');

    Route::post('auth/2fa/challenge', [StaffTwoFactorController::class, 'challenge'])
        ->middleware('throttle:staff-two-factor-challenge')->name('two-factor.challenge');

    Route::middleware('auth:staff')->group(function (): void {
        Route::prefix('auth/2fa')->name('two-factor.')->middleware('throttle:staff-two-factor-manage')->group(function (): void {
            Route::post('setup', [StaffTwoFactorController::class, 'setup'])->name('setup');
            Route::post('confirm', [StaffTwoFactorController::class, 'confirm'])->name('confirm');
            Route::post('disable', [StaffTwoFactorController::class, 'disable'])->name('disable');
            Route::post('recovery', [StaffTwoFactorController::class, 'recovery'])->name('recovery');
        });

        Route::post('auth/logout', [TokenController::class, 'destroy'])->name('auth.logout');
        Route::get('me', [StaffAccountController::class, 'show'])->name('me.show');
        Route::patch('me', [StaffAccountController::class, 'update'])->name('me.update');
        Route::post('auth/change-password', [PasswordController::class, 'update'])
            ->middleware('throttle:customer-recovery')->name('password.change');
    });
});

Route::prefix('admin')->name('admin.')->middleware('auth:staff')->group(function (): void {
    Route::get('permissions', [RoleManagementController::class, 'permissions'])->can('roles.view')->name('permissions.index');
    Route::get('roles', [RoleManagementController::class, 'index'])->can('roles.view')->name('roles.index');
    Route::post('roles', [RoleManagementController::class, 'store'])->can('roles.manage')->name('roles.store');
    Route::get('roles/{roleSlug}', [RoleManagementController::class, 'show'])->can('roles.view')->name('roles.show');
    Route::patch('roles/{roleSlug}', [RoleManagementController::class, 'update'])->can('roles.manage')->name('roles.update');
    Route::delete('roles/{roleSlug}', [RoleManagementController::class, 'destroy'])->can('roles.manage')->name('roles.destroy');
    Route::put('roles/{roleSlug}/permissions', [RoleManagementController::class, 'replacePermissions'])->can('roles.manage')->name('roles.permissions.replace');

    Route::get('staff', [StaffManagementController::class, 'index'])->can('staff.view')->name('staff.index');
    Route::post('staff', [StaffManagementController::class, 'store'])->can('staff.manage')->name('staff.store');
    Route::get('staff/{staff}', [StaffManagementController::class, 'show'])->can('staff.view')->name('staff.show');
    Route::patch('staff/{staff}', [StaffManagementController::class, 'update'])->can('staff.manage')->name('staff.update');
    Route::put('staff/{staff}/roles', [StaffManagementController::class, 'replaceRoles'])->can('staff.manage')->name('staff.roles.replace');

    Route::get('activity-logs', [AuditLogController::class, 'index'])->can('audit.view')->name('activity-logs.index');
    Route::get('audit-logs', [AuditLogController::class, 'index'])->can('audit.view')->name('audit-logs.index');
});

Route::prefix('admin/categories')->name('admin.categories.')->middleware('auth:staff')->group(function (): void {
    Route::get('/', [CategoryController::class, 'index'])->can('categories.view')->name('index');
    Route::post('/', [CategoryController::class, 'store'])->can('categories.create')->name('store');
    Route::post('bulk-actions', [CategoryController::class, 'bulk'])->can('categories.view')->name('bulk');
    Route::patch('reorder', [CategoryController::class, 'reorder'])->can('categories.update')->name('reorder');
    Route::get('{category}', [CategoryController::class, 'show'])->can('categories.view')->name('show');
    Route::patch('{category}', [CategoryController::class, 'update'])->can('categories.update')->name('update');
    Route::patch('{category}/status', [CategoryController::class, 'status'])->can('categories.update')->name('status');
    Route::delete('{category}', [CategoryController::class, 'destroy'])->can('categories.delete')->name('destroy');
    Route::post('{category}/restore', [CategoryController::class, 'restore'])->can('categories.delete')->name('restore');
});

$taxonomies = [
    ['brands', 'brands', 'brand', BrandController::class, 'brands'],
    ['attributes', 'attributes', 'attribute', AttributeController::class, 'attributes'],
    ['attributes/{attribute}/values', 'attribute-values', 'value', AttributeValueController::class, 'attributes'],
];

foreach ($taxonomies as [$prefix, $name, $parameter, $controller, $permission]) {
    Route::prefix('admin/'.$prefix)->name('admin.'.$name.'.')->middleware('auth:staff')->group(function () use ($parameter, $controller, $permission): void {
        $record = '{'.$parameter.'}';
        Route::get('/', [$controller, 'index'])->can($permission.'.view')->name('index');
        Route::post('/', [$controller, 'store'])->can($permission.'.create')->name('store');
        Route::post('bulk-actions', [$controller, 'bulk'])->can($permission.'.view')->name('bulk');
        Route::patch('reorder', [$controller, 'reorder'])->can($permission.'.update')->name('reorder');
        Route::get($record, [$controller, 'show'])->can($permission.'.view')->name('show');
        Route::patch($record, [$controller, 'update'])->can($permission.'.update')->name('update');
        Route::patch($record.'/status', [$controller, 'status'])->can($permission.'.update')->name('status');
        Route::delete($record, [$controller, 'destroy'])->can($permission.'.delete')->name('destroy');
        Route::post($record.'/restore', [$controller, 'restore'])->can($permission.'.delete')->name('restore');
    });
}

Route::prefix('admin/products')->name('admin.products.')->middleware('auth:staff')->group(function (): void {
    Route::get('/', [ProductController::class, 'index'])->can('products.view')->name('index');
    Route::post('/', [ProductController::class, 'store'])->can('products.create')->name('store');
    Route::post('bulk-actions', [ProductController::class, 'bulk'])->can('products.view')->name('bulk');
    Route::get('{product}', [ProductController::class, 'show'])->can('products.view')->name('show');
    Route::patch('{product}', [ProductController::class, 'update'])->can('products.update')->name('update');
    Route::patch('{product}/status', [ProductController::class, 'status'])->can('products.update')->name('status');
    Route::delete('{product}', [ProductController::class, 'destroy'])->can('products.delete')->name('destroy');
    Route::post('{product}/restore', [ProductController::class, 'restore'])->can('products.delete')->name('restore');
});

Route::prefix('admin/products/{product}/variants')->name('admin.products.variants.')->middleware('auth:staff')->group(function (): void {
    Route::get('/', [ProductVariantController::class, 'index'])->can('products.view')->name('index');
    Route::post('/', [ProductVariantController::class, 'store'])->can('products.update')->name('store');
    Route::patch('{variant}', [ProductVariantController::class, 'update'])->can('products.update')->name('update');
    Route::patch('{variant}/status', [ProductVariantController::class, 'status'])->can('products.update')->name('status');
    Route::delete('{variant}', [ProductVariantController::class, 'destroy'])->can('products.delete')->name('destroy');
});

Route::prefix('admin/products/{product}/images')->name('admin.products.images.')->middleware('auth:staff')->group(function (): void {
    Route::get('/', [ProductImageController::class, 'index'])->can('products.view')->name('index');
    Route::post('/', [ProductImageController::class, 'store'])->can('products.update')->name('store');
    Route::patch('reorder', [ProductImageController::class, 'reorder'])->can('products.update')->name('reorder');
    Route::patch('{image}', [ProductImageController::class, 'update'])->can('products.update')->name('update');
    Route::post('{image}/replace', [ProductImageController::class, 'replace'])->can('products.update')->name('replace');
    Route::delete('{image}', [ProductImageController::class, 'destroy'])->can('products.delete')->name('destroy');
});

Route::prefix('admin/inventory')->name('admin.inventory.')->middleware('auth:staff')->group(function (): void {
    Route::get('/', [InventoryController::class, 'index'])->can('inventory.view')->name('index');
    Route::get('movements', [InventoryController::class, 'movements'])->can('inventory.view')->name('movements.index');
    Route::post('adjustments', [InventoryController::class, 'adjust'])->can('inventory.adjust')->name('adjustments.store');
});

Route::prefix('admin/coupons')->name('admin.coupons.')->middleware('auth:staff')->group(function (): void {
    Route::get('/', [CouponController::class, 'index'])->can('coupons.view')->name('index');
    Route::get('{coupon}', [CouponController::class, 'show'])->can('coupons.view')->name('show');
    Route::post('/', [CouponController::class, 'store'])->can('coupons.manage')->name('store');
    Route::patch('{coupon}', [CouponController::class, 'update'])->can('coupons.manage')->name('update');
    Route::patch('{coupon}/status', [CouponController::class, 'status'])->can('coupons.manage')->name('status');
    Route::delete('{coupon}', [CouponController::class, 'destroy'])->can('coupons.manage')->name('destroy');
    Route::post('{coupon}/restore', [CouponController::class, 'restore'])->can('coupons.manage')->name('restore');
    Route::post('bulk-actions', [CouponController::class, 'bulk'])->can('coupons.manage')->name('bulk');
});

Route::prefix('admin/orders')->name('admin.orders.')->middleware('auth:staff')->group(function (): void {
    Route::get('/', [OrderController::class, 'index'])->can('orders.view')->name('index');
    Route::get('{order}', [OrderController::class, 'show'])->can('orders.view')->name('show');
    Route::post('{order}/transitions', [OrderController::class, 'transition'])->can('orders.update_status')->name('transitions.store');
    Route::post('{order}/cancel', [OrderController::class, 'cancel'])->can('orders.update_status')->name('cancel');
    Route::get('{order}/refunds', [OrderController::class, 'refunds'])->can('orders.refund')->name('refunds.index');
    Route::post('{order}/refunds', [OrderController::class, 'refund'])->can('orders.refund')->name('refunds.store');
});

Route::prefix('admin/shipping-settings')->name('admin.shipping-settings.')->middleware('auth:staff')->group(function (): void {
    Route::get('/', [ShippingSettingsController::class, 'show'])->can('shipping.manage')->name('show');
    Route::patch('/', [ShippingSettingsController::class, 'update'])->can('shipping.manage')->name('update');
});

Route::prefix('admin')->name('admin.')->middleware('auth:staff')->group(function (): void {
    Route::get('customers', [CustomerController::class, 'index'])->can('customers.view')->name('customers.index');
    Route::get('customers/{customer}', [CustomerController::class, 'show'])->can('customers.view')->name('customers.show');
    Route::get('customers/{customer}/orders', [CustomerController::class, 'orders'])->can('customers.view')->name('customers.orders');
    Route::patch('customers/{customer}/status', [CustomerController::class, 'status'])->can('customers.manage')->name('customers.status');
    Route::get('dashboard', [ReportController::class, 'dashboard'])->can('reports.view')->name('dashboard');
    Route::get('reports/sales', [ReportController::class, 'sales'])->can('reports.view')->name('reports.sales');
    Route::get('reports/inventory', [ReportController::class, 'inventory'])->can('reports.view')->name('reports.inventory');
    Route::get('reports/coupons', [ReportController::class, 'coupons'])->can('reports.view')->name('reports.coupons');
});

Route::prefix('admin')->name('admin.')->middleware('auth:staff')->group(function (): void {
    $controller = StorefrontContentController::class;
    foreach (['banners' => 'banner', 'homepage-sections' => 'section', 'navigation' => 'navigation', 'testimonials' => 'testimonial'] as $path => $type) {
        Route::get($path, [$controller, 'index'])->defaults('contentType', $type)->can('content.view')->name($path.'.index');
        Route::post($path, [$controller, 'store'])->defaults('contentType', $type)->can('content.manage')->name($path.'.store');
        Route::get($path.'/{content}', [$controller, 'show'])->defaults('contentType', $type)->can('content.view')->name($path.'.show');
        Route::patch($path.'/{content}', [$controller, 'update'])->defaults('contentType', $type)->can('content.manage')->name($path.'.update');
        Route::delete($path.'/{content}', [$controller, 'destroy'])->defaults('contentType', $type)->can('content.manage')->name($path.'.destroy');
    }
    Route::get('site-settings', [$controller, 'settings'])->defaults('contentType', 'settings')->can('content.view')->name('site-settings.show');
    Route::patch('site-settings', [$controller, 'updateSettings'])->defaults('contentType', 'settings')->can('content.manage')->name('site-settings.update');
    Route::get('newsletter-subscribers', [$controller, 'subscribers'])->can('content.manage')->name('newsletter-subscribers.index');
});

Route::prefix('admin/reviews')->name('admin.reviews.')->middleware(['auth:staff', 'can:reviews.manage'])->group(function (): void {
    Route::get('/', [ReviewController::class, 'index'])->name('index');
    Route::post('bulk-actions', [ReviewController::class, 'bulk'])->name('bulk');
    Route::post('{review}/moderate', [ReviewController::class, 'moderate'])->name('moderate');
});

Route::post('admin/media/{scope}', [MediaUploadController::class, 'store'])
    ->whereIn('scope', ['category-create', 'category-update', 'brand-create', 'brand-update', 'content'])
    ->middleware(['auth:staff', 'throttle:30,1'])->name('admin.media.store');

<?php

use App\Http\Controllers\Api\V1\Admin\AttributeController;
use App\Http\Controllers\Api\V1\Admin\AttributeValueController;
use App\Http\Controllers\Api\V1\Admin\AuditLogController;
use App\Http\Controllers\Api\V1\Admin\BrandController;
use App\Http\Controllers\Api\V1\Admin\CategoryController;
use App\Http\Controllers\Api\V1\Admin\InventoryController;
use App\Http\Controllers\Api\V1\Admin\ProductController;
use App\Http\Controllers\Api\V1\Admin\ProductImageController;
use App\Http\Controllers\Api\V1\Admin\ProductVariantController;
use App\Http\Controllers\Api\V1\Admin\RoleManagementController;
use App\Http\Controllers\Api\V1\Admin\StaffManagementController;
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

<?php

use App\Http\Controllers\Api\V1\Admin\AuditLogController;
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

<?php

use App\Http\Controllers\Admin\AppearanceController;
use App\Http\Controllers\Admin\AuditLogController;
use App\Http\Controllers\Admin\DepartmentController;
use App\Http\Controllers\Admin\DepartmentDistributionController;
use App\Http\Controllers\Admin\DepartmentHoursController;
use App\Http\Controllers\Admin\DepartmentMemberController;
use App\Http\Controllers\Admin\DepartmentOperationsController;
use App\Http\Controllers\Admin\PlatformSettingsController;
use App\Http\Controllers\Admin\RoleController;
use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\Admin\UserEmailAvailabilityController;
use App\Http\Controllers\Admin\UserRoleImpactController;
use Illuminate\Support\Facades\Route;

/*
| Authorization for every route below is enforced by policies and gates in the
| controllers and form requests; hiding UI elements is never relied upon.
*/

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('departments', [DepartmentController::class, 'index'])->name('departments.index');
    Route::post('departments', [DepartmentController::class, 'store'])->name('departments.store');
    Route::get('departments/{department}', [DepartmentController::class, 'show'])->name('departments.show');
    Route::put('departments/{department}', [DepartmentController::class, 'update'])->name('departments.update');
    Route::delete('departments/{department}', [DepartmentController::class, 'destroy'])->name('departments.destroy');
    Route::patch('departments/{department}/restore', [DepartmentController::class, 'restore'])->name('departments.restore');
    Route::put('departments/{department}/operations', [DepartmentOperationsController::class, 'update'])->name('departments.operations.update');
    Route::put('departments/{department}/distribution', [DepartmentDistributionController::class, 'update'])->name('departments.distribution.update');
    Route::put('departments/{department}/hours', [DepartmentHoursController::class, 'update'])->name('departments.hours.update');
    Route::post('departments/{department}/members', [DepartmentMemberController::class, 'store'])->name('departments.members.store');
    Route::delete('departments/{department}/members/{member}', [DepartmentMemberController::class, 'destroy'])->name('departments.members.destroy');

    Route::prefix('admin')->name('admin.')->group(function () {
        Route::get('users', [UserController::class, 'index'])->name('users.index');
        Route::post('users', [UserController::class, 'store'])->name('users.store');
        Route::put('users/{user}', [UserController::class, 'update'])->name('users.update');
        Route::delete('users/{user}', [UserController::class, 'destroy'])->name('users.destroy');
        Route::patch('users/{user}/restore', [UserController::class, 'restore'])->name('users.restore');
        Route::post('users/{user}/role-impact', UserRoleImpactController::class)->name('users.role-impact');
        Route::post('users/email-availability', UserEmailAvailabilityController::class)
            ->middleware('throttle:30,1')
            ->name('users.email-availability');

        Route::get('roles', [RoleController::class, 'index'])->name('roles.index');
        Route::post('roles', [RoleController::class, 'store'])->name('roles.store');
        Route::put('roles/{role}', [RoleController::class, 'update'])->name('roles.update');
        Route::delete('roles/{role}', [RoleController::class, 'destroy'])->name('roles.destroy');
        Route::patch('roles/{role}/restore', [RoleController::class, 'restore'])->name('roles.restore');

        Route::get('audit', [AuditLogController::class, 'index'])->name('audit.index');

        Route::get('settings', [PlatformSettingsController::class, 'edit'])->name('settings.edit');
        Route::put('settings', [PlatformSettingsController::class, 'update'])->name('settings.update');

        Route::get('appearance', [AppearanceController::class, 'edit'])->name('appearance.edit');
        Route::put('appearance', [AppearanceController::class, 'update'])->name('appearance.update');
    });
});

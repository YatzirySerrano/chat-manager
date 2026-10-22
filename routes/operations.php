<?php

use App\Http\Controllers\Admin\SystemController;
use App\Http\Controllers\Admin\SystemFailedJobController;
use Illuminate\Support\Facades\Route;

/*
| Administración > Sistema (ops module). Authorization: gates view-system and
| manage-system (permissions system.view / system.manage, global scope).
*/

Route::middleware(['auth', 'verified'])->prefix('admin/system')->name('admin.system.')->group(function () {
    Route::get('/', [SystemController::class, 'index'])->name('index');
    Route::get('health', [SystemController::class, 'health'])->middleware('throttle:30,1')->name('health');
    Route::post('failed-jobs/{uuid}/retry', [SystemFailedJobController::class, 'retry'])
        ->whereUuid('uuid')
        ->middleware('throttle:30,1')
        ->name('failed-jobs.retry');
    Route::delete('failed-jobs/{uuid}', [SystemFailedJobController::class, 'destroy'])
        ->whereUuid('uuid')
        ->middleware('throttle:30,1')
        ->name('failed-jobs.destroy');
});

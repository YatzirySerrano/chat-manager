<?php

use App\Http\Controllers\Admin\StorageStatusController;
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

/*
| Administración > Almacenamiento: state of the media disk (NAS share, S3 or
| the server's disk). Same gates as Sistema; the page polls "status".
*/

Route::middleware(['auth', 'verified'])->prefix('admin/storage')->name('admin.storage.')->group(function () {
    Route::get('/', [StorageStatusController::class, 'index'])->name('index');
    Route::get('status', [StorageStatusController::class, 'status'])->middleware('throttle:30,1')->name('status');
    Route::post('probe', [StorageStatusController::class, 'probe'])->middleware('throttle:6,1')->name('probe');
});

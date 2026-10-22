<?php

use App\Enums\ExportFormat;
use App\Http\Controllers\Exports\DataTransferController;
use App\Http\Controllers\Exports\ReportExportController;
use Illuminate\Support\Facades\Route;

/*
| Exports: Excel and PDF of every module through one engine (app/Reports).
| Each export requires reports.export plus the module's own authorization;
| generated files are private to the user who requested them.
*/

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('exports', [DataTransferController::class, 'index'])->name('exports.index');
    Route::get('exports/transfers/{transfer}/download', [DataTransferController::class, 'download'])->name('exports.transfers.download');

    Route::get('exports/{report}/{format}', ReportExportController::class)
        ->where('report', '[a-z0-9-]+')
        ->whereIn('format', ExportFormat::values())
        ->middleware('throttle:10,1')
        ->name('exports.generate');
});

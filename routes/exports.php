<?php

use App\Enums\ExportFormat;
use App\Http\Controllers\Exports\DataTransferController;
use App\Http\Controllers\Exports\ReportExportController;
use Illuminate\Support\Facades\Route;

/*
| Exports: Excel and PDF of every module through one engine (app/Reports).
| Each export requires reports.export plus the module's own authorization;
| generated files are private to the user who requested them. Generating
| a file is a POST (CSRF protected); only downloading an already generated
| file is a GET. Small files download right away from the module; large ones
| are prepared in the background and downloaded from the notification or
| from the "Archivos preparados recientemente" section of the export menu.
| The exports page is no longer in the navigation but stays reachable from
| those menus, so old links keep working.
*/

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('exports', [DataTransferController::class, 'index'])->name('exports.index');
    Route::get('exports/recent', [DataTransferController::class, 'recent'])->name('exports.recent');
    Route::get('exports/transfers/{transfer}/download', [DataTransferController::class, 'download'])->name('exports.transfers.download');

    Route::post('exports/{report}/{format}', ReportExportController::class)
        ->where('report', '[a-z0-9-]+')
        ->whereIn('format', ExportFormat::values())
        ->middleware('throttle:10,1')
        ->name('exports.generate');
});

<?php

use App\Http\Controllers\Automation\HolidayController;
use Illuminate\Support\Facades\Route;

/*
| Business hours and automatic messages configuration that is not part of a
| department page (the platform's non-working days).
*/
Route::middleware(['auth', 'verified'])->group(function () {
    Route::put('admin/settings/holidays', [HolidayController::class, 'update'])->name('admin.settings.holidays.update');
});

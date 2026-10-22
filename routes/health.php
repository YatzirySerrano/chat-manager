<?php

use App\Http\Controllers\HealthReadinessController;
use Illuminate\Support\Facades\Route;

/*
| Public readiness probe (outside the "web" group: no session or cookies).
| Answers only {"status":"ok"|"fail"}; details live on /admin/system.
*/

Route::get('health/ready', HealthReadinessController::class)
    ->middleware('throttle:60,1')
    ->name('health.ready');

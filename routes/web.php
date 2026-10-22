<?php

use App\Http\Controllers\DashboardController;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/dashboard')->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('dashboard', DashboardController::class)->name('dashboard');
});

require __DIR__.'/admin.php';
require __DIR__.'/connections.php';
require __DIR__.'/imports.php';
require __DIR__.'/contacts.php';
require __DIR__.'/exports.php';
require __DIR__.'/conversations.php';
require __DIR__.'/tags.php';
require __DIR__.'/quick-replies.php';
require __DIR__.'/settings.php';
require __DIR__.'/reports.php';
require __DIR__.'/notifications.php';
require __DIR__.'/automation.php';
require __DIR__.'/team-chat.php';
require __DIR__.'/messaging.php';
require __DIR__.'/operations.php';

// Provider webhooks: public and outside the "web" group (no session, cookies or CSRF).
Route::withoutMiddleware('web')->group(__DIR__.'/webhooks.php');

// Public readiness probe for monitors: outside the "web" group as well.
Route::withoutMiddleware('web')->group(__DIR__.'/health.php');

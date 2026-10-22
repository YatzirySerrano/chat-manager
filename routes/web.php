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

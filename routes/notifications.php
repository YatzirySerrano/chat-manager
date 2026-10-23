<?php

use App\Http\Controllers\Notifications\NotificationController;
use App\Http\Controllers\Notifications\NotificationPreferenceController;
use App\Http\Controllers\Notifications\PushSubscriptionController;
use Illuminate\Support\Facades\Route;

/*
| In-app notifications of the authenticated user (bell and preferences).
| Every endpoint is bound to the user's own notifications.
*/
Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('notifications', [NotificationController::class, 'index'])->middleware('throttle:60,1')->name('notifications.index');
    Route::get('notifications/summary', [NotificationController::class, 'summary'])->middleware('throttle:120,1')->name('notifications.summary');
    Route::post('notifications/read-all', [NotificationController::class, 'readAll'])->name('notifications.read-all');
    Route::post('notifications/conversations/{conversation}/read', [NotificationController::class, 'readConversation'])->whereNumber('conversation')->name('notifications.conversations.read');
    Route::post('notifications/{notification}/read', [NotificationController::class, 'read'])->whereUuid('notification')->name('notifications.read');

    Route::get('settings/notifications', [NotificationPreferenceController::class, 'edit'])->name('notifications.preferences.edit');
    Route::put('settings/notifications', [NotificationPreferenceController::class, 'update'])->name('notifications.preferences.update');

    Route::post('settings/notifications/push-subscriptions', [PushSubscriptionController::class, 'store'])->middleware('throttle:20,1')->name('notifications.push-subscriptions.store');
    Route::delete('settings/notifications/push-subscriptions/{subscription}', [PushSubscriptionController::class, 'destroy'])->whereNumber('subscription')->middleware('throttle:20,1')->name('notifications.push-subscriptions.destroy');
});

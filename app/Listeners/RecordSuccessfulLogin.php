<?php

namespace App\Listeners;

use App\Models\User;
use Illuminate\Auth\Events\Login;

class RecordSuccessfulLogin
{
    /**
     * Store the last login time without touching `updated_at`.
     */
    public function handle(Login $event): void
    {
        if (! $event->user instanceof User) {
            return;
        }

        $user = $event->user;

        User::withoutTimestamps(fn () => $user->forceFill(['last_login_at' => now()])->saveQuietly());
    }
}

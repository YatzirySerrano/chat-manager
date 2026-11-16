<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * System notifications (Windows, macOS...) are now on by default for every
 * kind announced live (customer messages, internal chat, urgent ones).
 * Until now "Navegador" was off by default, so the saved rows of those kinds
 * still carry that old default; they are switched on once so every message
 * reaches the desktop. Anyone can turn it off again in their preferences.
 */
return new class extends Migration
{
    private const KINDS = [
        'conversation.pending',
        'conversation.assigned',
        'conversation.transferred',
        'conversation.message',
        'team-chat.mention',
        'team-chat.message',
        'connection.problem',
        'sla.warning',
        'sla.breach',
    ];

    public function up(): void
    {
        DB::table('user_notification_preferences')
            ->whereIn('kind', self::KINDS)
            ->where('in_app', true)
            ->update(['browser' => true]);
    }

    public function down(): void
    {
        // The previous values are not known; leaving them on is harmless.
    }
};

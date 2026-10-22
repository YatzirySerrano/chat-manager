<?php

namespace App\Actions\TeamChat;

use App\Enums\TeamChat\TeamChatSystemEvent;
use App\Enums\TeamChat\TeamParticipantRole;
use App\Models\TeamChat\TeamParticipant;
use App\Models\TeamChat\TeamThread;
use App\Services\TeamChat\TeamThreadActivity;

/**
 * A group with participants always keeps an administrator: when the last
 * one leaves or is removed, the longest-standing active member becomes one
 * (visible to everyone as a system line).
 */
class EnsureGroupHasAdministrator
{
    public function __construct(private TeamThreadActivity $activity) {}

    public function handle(TeamThread $thread): void
    {
        $active = TeamParticipant::query()
            ->where('team_thread_id', $thread->id)
            ->whereNull('left_at')
            ->whereHas('user', fn ($users) => $users->where('is_active', true))
            ->orderBy('joined_at')
            ->orderBy('id')
            ->get();

        if ($active->isEmpty() || $active->contains(fn (TeamParticipant $participant): bool => $participant->isAdmin())) {
            return;
        }

        $successor = $active->first();
        $successor->forceFill(['role' => TeamParticipantRole::Admin])->save();

        $this->activity->recordSystemEvent($thread, null, TeamChatSystemEvent::AdminGranted, $successor->user_id, ['automatic' => true]);
    }
}

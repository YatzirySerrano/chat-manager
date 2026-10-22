<?php

namespace App\Actions\TeamChat;

use App\Enums\AuditAction;
use App\Enums\TeamChat\TeamChatSystemEvent;
use App\Events\TeamChat\TeamThreadChanged;
use App\Events\Users\UserAccessChanged;
use App\Models\TeamChat\TeamParticipant;
use App\Models\TeamChat\TeamThread;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\TeamChat\TeamThreadActivity;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Remove someone from a group. Access ends immediately: the person no
 * longer sees the group, its history or its files (the participation row
 * stays as history).
 */
class RemoveGroupParticipant
{
    public function __construct(
        private AuditLogger $audit,
        private TeamThreadActivity $activity,
        private EnsureGroupHasAdministrator $ensureAdministrator,
    ) {}

    public function handle(TeamThread $thread, User $actor, User $target): void
    {
        if ($target->id === $actor->id) {
            throw ValidationException::withMessages(['user' => 'Para retirarte del grupo usa «Salir del grupo».']);
        }

        DB::transaction(function () use ($thread, $actor, $target): void {
            TeamThread::query()->whereKey($thread->id)->lockForUpdate()->first();

            $participation = TeamParticipant::query()
                ->where('team_thread_id', $thread->id)
                ->where('user_id', $target->id)
                ->whereNull('left_at')
                ->first();

            if ($participation === null) {
                throw ValidationException::withMessages(['user' => 'Esta persona ya no participa en el grupo.']);
            }

            $participation->forceFill(['left_at' => now(), 'removed_by_user_id' => $actor->id])->save();

            $this->activity->recordSystemEvent($thread, $actor, TeamChatSystemEvent::ParticipantRemoved, $target->id);
            $this->ensureAdministrator->handle($thread);

            $this->audit->record(AuditAction::TeamChatParticipantRemoved, $thread, [
                'participant' => $target->name,
            ], $actor);

            TeamThreadChanged::dispatch($thread->id, TeamThreadChanged::PARTICIPANTS, $actor->id, [$target->id]);
            UserAccessChanged::dispatch([$target->id], 'team-chat.membership');
        });
    }
}

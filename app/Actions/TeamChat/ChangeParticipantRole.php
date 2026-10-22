<?php

namespace App\Actions\TeamChat;

use App\Enums\AuditAction;
use App\Enums\TeamChat\TeamChatSystemEvent;
use App\Enums\TeamChat\TeamParticipantRole;
use App\Events\TeamChat\TeamThreadChanged;
use App\Models\TeamChat\TeamParticipant;
use App\Models\TeamChat\TeamThread;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\TeamChat\TeamThreadActivity;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Appoint or revoke a group administrator. A group always keeps at least
 * one. Returns whether the role changed.
 */
class ChangeParticipantRole
{
    public function __construct(
        private AuditLogger $audit,
        private TeamThreadActivity $activity,
    ) {}

    public function handle(TeamThread $thread, User $actor, User $target, TeamParticipantRole $role): bool
    {
        return DB::transaction(function () use ($thread, $actor, $target, $role): bool {
            TeamThread::query()->whereKey($thread->id)->lockForUpdate()->first();

            $participation = TeamParticipant::query()
                ->where('team_thread_id', $thread->id)
                ->where('user_id', $target->id)
                ->whereNull('left_at')
                ->first();

            if ($participation === null) {
                throw ValidationException::withMessages(['role' => 'Esta persona ya no participa en el grupo.']);
            }

            if ($participation->role === $role) {
                return false;
            }

            if ($role === TeamParticipantRole::Admin && ! $target->is_active) {
                throw ValidationException::withMessages(['role' => 'Una cuenta eliminada no puede administrar el grupo.']);
            }

            if ($role === TeamParticipantRole::Member) {
                $otherAdmins = TeamParticipant::query()
                    ->where('team_thread_id', $thread->id)
                    ->whereNull('left_at')
                    ->where('role', TeamParticipantRole::Admin)
                    ->whereKeyNot($participation->id)
                    ->exists();

                if (! $otherAdmins) {
                    throw ValidationException::withMessages(['role' => 'El grupo debe conservar al menos un administrador.']);
                }
            }

            $participation->forceFill(['role' => $role])->save();

            $this->activity->recordSystemEvent(
                $thread,
                $actor,
                $role === TeamParticipantRole::Admin ? TeamChatSystemEvent::AdminGranted : TeamChatSystemEvent::AdminRevoked,
                $target->id,
            );

            $this->audit->record(AuditAction::TeamChatParticipantRoleChanged, $thread, [
                'participant' => $target->name,
                'role' => $role->value,
            ], $actor);

            TeamThreadChanged::dispatch($thread->id, TeamThreadChanged::PARTICIPANTS, $actor->id);

            return true;
        });
    }
}

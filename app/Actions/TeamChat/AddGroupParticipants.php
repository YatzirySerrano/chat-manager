<?php

namespace App\Actions\TeamChat;

use App\Enums\AuditAction;
use App\Enums\TeamChat\TeamChatSystemEvent;
use App\Enums\TeamChat\TeamParticipantRole;
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
 * Add people to a group (already checked against the actor's reach).
 * Someone who left or was removed joins again with the member role.
 * Returns how many people were added.
 */
class AddGroupParticipants
{
    public function __construct(
        private AuditLogger $audit,
        private TeamThreadActivity $activity,
    ) {}

    /**
     * @param  list<int>  $userIds
     */
    public function handle(TeamThread $thread, User $actor, array $userIds): int
    {
        return DB::transaction(function () use ($thread, $actor, $userIds): int {
            $lastMessageId = TeamThread::query()->whereKey($thread->id)->lockForUpdate()->value('last_message_id');

            $participations = TeamParticipant::query()->where('team_thread_id', $thread->id)->get()->keyBy('user_id');
            $toAdd = array_values(array_filter(
                array_unique($userIds),
                fn (int $userId): bool => ! ($participations->get($userId)?->isActive() ?? false),
            ));

            if ($toAdd === []) {
                return 0;
            }

            $limit = (int) config('team-chat.group_max_participants');
            $current = $participations->filter(fn (TeamParticipant $participant): bool => $participant->isActive())->count();

            if ($current + count($toAdd) > $limit) {
                throw ValidationException::withMessages(['user_ids' => "Un grupo puede tener como máximo {$limit} participantes."]);
            }

            foreach ($toAdd as $userId) {
                $participation = $participations->get($userId) ?? new TeamParticipant(['user_id' => $userId]);
                $participation->team_thread_id = $thread->id;
                $participation->forceFill([
                    'role' => TeamParticipantRole::Member,
                    'joined_at' => now(),
                    'left_at' => null,
                    'added_by_user_id' => $actor->id,
                    'removed_by_user_id' => null,
                    'muted' => false,
                    'last_read_message_id' => $lastMessageId,
                ])->save();

                $this->activity->recordSystemEvent($thread, $actor, TeamChatSystemEvent::ParticipantAdded, $userId);
            }

            $this->audit->record(AuditAction::TeamChatParticipantsAdded, $thread, [
                'participants' => User::query()->whereKey($toAdd)->orderBy('name')->pluck('name')->all(),
            ], $actor);

            TeamThreadChanged::dispatch($thread->id, TeamThreadChanged::PARTICIPANTS, $actor->id, $toAdd);
            UserAccessChanged::dispatch($toAdd, 'team-chat.membership');

            return count($toAdd);
        });
    }
}

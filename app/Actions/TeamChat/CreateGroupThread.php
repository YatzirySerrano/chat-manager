<?php

namespace App\Actions\TeamChat;

use App\Enums\AuditAction;
use App\Enums\TeamChat\TeamChatSystemEvent;
use App\Enums\TeamChat\TeamParticipantRole;
use App\Enums\TeamChat\TeamThreadType;
use App\Events\TeamChat\TeamThreadChanged;
use App\Events\Users\UserAccessChanged;
use App\Models\TeamChat\TeamThread;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\TeamChat\TeamThreadActivity;
use Illuminate\Support\Facades\DB;

/**
 * Create a group; its creator becomes its first administrator. The
 * participants were already checked against the creator's reach.
 */
class CreateGroupThread
{
    public function __construct(
        private AuditLogger $audit,
        private TeamThreadActivity $activity,
    ) {}

    /**
     * @param  array{name: string, description: string|null, participant_ids: list<int>}  $data
     */
    public function handle(User $actor, array $data): TeamThread
    {
        $participantIds = array_values(array_diff(array_unique($data['participant_ids']), [$actor->id]));

        return DB::transaction(function () use ($actor, $data, $participantIds): TeamThread {
            $thread = new TeamThread([
                'type' => TeamThreadType::Group,
                'name' => $data['name'],
                'description' => $data['description'],
            ]);
            $thread->created_by_user_id = $actor->id;
            $thread->save();

            $thread->participations()->create([
                'user_id' => $actor->id,
                'role' => TeamParticipantRole::Admin,
                'joined_at' => now(),
                'added_by_user_id' => $actor->id,
            ]);

            foreach ($participantIds as $userId) {
                $thread->participations()->create([
                    'user_id' => $userId,
                    'role' => TeamParticipantRole::Member,
                    'joined_at' => now(),
                    'added_by_user_id' => $actor->id,
                ]);
            }

            $this->activity->recordSystemEvent($thread, $actor, TeamChatSystemEvent::GroupCreated);

            $this->audit->record(AuditAction::TeamChatGroupCreated, $thread, [
                'name' => $thread->name,
                'participants' => User::query()->whereKey([$actor->id, ...$participantIds])->orderBy('name')->pluck('name')->all(),
            ], $actor);

            $everyone = [$actor->id, ...$participantIds];
            TeamThreadChanged::dispatch($thread->id, TeamThreadChanged::CREATED, $actor->id, $everyone);
            UserAccessChanged::dispatch($everyone, 'team-chat.membership');

            return $thread;
        });
    }
}

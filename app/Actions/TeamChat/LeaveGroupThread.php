<?php

namespace App\Actions\TeamChat;

use App\Enums\TeamChat\TeamChatSystemEvent;
use App\Events\TeamChat\TeamThreadChanged;
use App\Events\Users\UserAccessChanged;
use App\Models\TeamChat\TeamParticipant;
use App\Models\TeamChat\TeamThread;
use App\Models\User;
use App\Services\TeamChat\TeamThreadActivity;
use Illuminate\Support\Facades\DB;

/**
 * Leave a group. Access ends immediately. If nobody remains the group is
 * archived; if no administrator remains, one is appointed automatically.
 */
class LeaveGroupThread
{
    public function __construct(
        private TeamThreadActivity $activity,
        private EnsureGroupHasAdministrator $ensureAdministrator,
    ) {}

    public function handle(TeamThread $thread, User $actor): void
    {
        DB::transaction(function () use ($thread, $actor): void {
            TeamThread::query()->whereKey($thread->id)->lockForUpdate()->first();

            $updated = TeamParticipant::query()
                ->where('team_thread_id', $thread->id)
                ->where('user_id', $actor->id)
                ->whereNull('left_at')
                ->update(['left_at' => now(), 'updated_at' => now()]);

            if ($updated === 0) {
                return;
            }

            $this->activity->recordSystemEvent($thread, $actor, TeamChatSystemEvent::ParticipantLeft);

            $remaining = TeamParticipant::query()->where('team_thread_id', $thread->id)->whereNull('left_at')->exists();

            if (! $remaining && ! $thread->isArchived()) {
                $thread->forceFill(['archived_at' => now(), 'archived_by_user_id' => null])->save();
            } else {
                $this->ensureAdministrator->handle($thread);
            }

            TeamThreadChanged::dispatch($thread->id, TeamThreadChanged::PARTICIPANTS, $actor->id, [$actor->id]);
            UserAccessChanged::dispatch([$actor->id], 'team-chat.membership');
        });
    }
}

<?php

namespace App\Actions\TeamChat;

use App\Enums\AuditAction;
use App\Enums\TeamChat\TeamChatSystemEvent;
use App\Events\TeamChat\TeamThreadChanged;
use App\Models\TeamChat\TeamThread;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\TeamChat\TeamThreadActivity;
use Illuminate\Support\Facades\DB;

/**
 * Archive a group (read-only: its history stays available to its
 * participants, nobody can write) or reactivate it. Archiving is not
 * deleting: nothing is erased.
 */
class ArchiveGroupThread
{
    public function __construct(
        private AuditLogger $audit,
        private TeamThreadActivity $activity,
    ) {}

    public function archive(TeamThread $thread, User $actor): void
    {
        DB::transaction(function () use ($thread, $actor): void {
            $thread->forceFill(['archived_at' => now(), 'archived_by_user_id' => $actor->id])->save();

            $this->activity->recordSystemEvent($thread, $actor, TeamChatSystemEvent::GroupArchived);
            $this->audit->record(AuditAction::TeamChatGroupArchived, $thread, [], $actor);

            TeamThreadChanged::dispatch($thread->id, TeamThreadChanged::ARCHIVED, $actor->id);
        });
    }

    public function unarchive(TeamThread $thread, User $actor): void
    {
        DB::transaction(function () use ($thread, $actor): void {
            $thread->forceFill(['archived_at' => null, 'archived_by_user_id' => null])->save();

            $this->activity->recordSystemEvent($thread, $actor, TeamChatSystemEvent::GroupUnarchived);
            $this->audit->record(AuditAction::TeamChatGroupUnarchived, $thread, [], $actor);

            TeamThreadChanged::dispatch($thread->id, TeamThreadChanged::UNARCHIVED, $actor->id);
        });
    }
}

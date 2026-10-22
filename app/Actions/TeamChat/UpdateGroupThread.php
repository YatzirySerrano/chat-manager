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
 * Rename a group or change its description. Returns whether anything changed.
 */
class UpdateGroupThread
{
    public function __construct(
        private AuditLogger $audit,
        private TeamThreadActivity $activity,
    ) {}

    /**
     * @param  array{name: string, description: string|null}  $data
     */
    public function handle(TeamThread $thread, User $actor, array $data): bool
    {
        $thread->fill(['name' => $data['name'], 'description' => $data['description']]);

        if (! $thread->isDirty()) {
            return false;
        }

        $changes = [];

        foreach (['name', 'description'] as $field) {
            if ($thread->isDirty($field)) {
                $changes[$field] = ['from' => $thread->getOriginal($field), 'to' => $thread->{$field}];
            }
        }

        DB::transaction(function () use ($thread, $actor, $changes): void {
            $thread->save();

            if (isset($changes['name'])) {
                $this->activity->recordSystemEvent($thread, $actor, TeamChatSystemEvent::GroupRenamed, null, ['name' => $thread->name]);
            }

            if (isset($changes['description'])) {
                $this->activity->recordSystemEvent($thread, $actor, TeamChatSystemEvent::DescriptionChanged);
            }

            $this->audit->record(AuditAction::TeamChatGroupUpdated, $thread, ['changes' => $changes], $actor);

            TeamThreadChanged::dispatch($thread->id, TeamThreadChanged::UPDATED, $actor->id);
        });

        return true;
    }
}

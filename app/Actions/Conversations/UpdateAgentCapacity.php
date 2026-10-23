<?php

namespace App\Actions\Conversations;

use App\Enums\AuditAction;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;

/**
 * Sets the agent's own load limit (null inherits the department and the
 * platform limits, see ConversationAccess::attentionLimit()). Lowering it
 * never takes conversations away; raising or removing it offers the waiting
 * conversations of the agent's automatic departments once committed.
 */
class UpdateAgentCapacity
{
    public function __construct(
        private AuditLogger $audit,
        private AssignAutomatically $assignAutomatically,
    ) {}

    /**
     * @return bool Whether the limit changed.
     */
    public function handle(User $agent, ?int $limit, User $actor): bool
    {
        return DB::transaction(function () use ($agent, $limit, $actor): bool {
            $agent->max_open_conversations = $limit;

            if (! $agent->isDirty('max_open_conversations')) {
                return false;
            }

            $old = $agent->getOriginal('max_open_conversations');
            $agent->save();

            $this->audit->record(AuditAction::UserUpdated, $agent, [
                'changes' => ['max_open_conversations' => ['old' => $old, 'new' => $limit]],
            ], $actor);

            if (AssignAutomatically::overrideMayFreeRoom($old, $limit)) {
                $this->assignAutomatically->distributeForAgentAfterCommit($agent->id);
            }

            return true;
        });
    }
}

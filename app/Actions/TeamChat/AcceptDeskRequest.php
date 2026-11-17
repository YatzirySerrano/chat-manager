<?php

namespace App\Actions\TeamChat;

use App\Enums\TeamChat\TeamChatSystemEvent;
use App\Enums\TeamChat\TeamDeskStatus;
use App\Enums\TeamChat\TeamParticipantRole;
use App\Events\TeamChat\TeamThreadChanged;
use App\Exceptions\TeamDeskConflict;
use App\Models\TeamChat\TeamParticipant;
use App\Models\TeamChat\TeamThread;
use App\Models\User;
use App\Services\TeamChat\ControlDesk;
use App\Services\TeamChat\TeamThreadActivity;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * An auditor takes a pending «Mesa de control» request: it becomes hers
 * (open, `desk_assigned_user_id`, `desk_accepted_at`) also while it has
 * unread messages, and she joins the thread as a participant so she gets
 * its notifications; the messages of the request (from
 * `desk_request_message_id` on) count as unread for her.
 *
 * Race-safe: the thread row is locked, so when two auditors accept at once
 * the second finds it open and receives a controlled conflict. Her user
 * row is locked too, which serializes two tabs of the same auditor, and her
 * open desk requests are counted with a locking read: the
 * simultaneous-attention limit (the one of customer conversations, counted
 * separately for the desk, see ControlDesk) is never exceeded. Accepting a
 * request she already holds answers it again (idempotent).
 */
class AcceptDeskRequest
{
    public function __construct(
        private ControlDesk $desk,
        private TeamThreadActivity $activity,
    ) {}

    /**
     * @throws TeamDeskConflict when the request is taken, not waiting or she is at her limit.
     */
    public function handle(TeamThread $thread, User $actor): TeamThread
    {
        return DB::transaction(function () use ($thread, $actor): TeamThread {
            $locked = TeamThread::query()->whereKey($thread->id)->lockForUpdate()->firstOrFail();
            $fresh = User::query()->with('roles')->lockForUpdate()->findOrFail($actor->id);

            // Again on the locked rows: a permission revoked meanwhile is honored.
            Gate::forUser($fresh)->authorize('attendDesk', $locked);

            if ($locked->isDeskAssignedTo($fresh)) {
                return $locked;
            }

            if ($locked->desk_status === TeamDeskStatus::Open) {
                throw TeamDeskConflict::alreadyTaken();
            }

            if ($locked->desk_status !== TeamDeskStatus::Pending) {
                throw TeamDeskConflict::notWaiting();
            }

            $limit = $this->desk->limit($fresh);

            if ($limit !== null && $this->desk->load($fresh, lockingRead: true) >= $limit) {
                throw TeamDeskConflict::capacity(ControlDesk::capacityMessage($limit));
            }

            $locked->forceFill([
                'desk_status' => TeamDeskStatus::Open,
                'desk_assigned_user_id' => $fresh->id,
                'desk_accepted_at' => now(),
            ])->save();

            $this->join($locked, $fresh);
            $this->activity->recordSystemEvent($locked, $fresh, TeamChatSystemEvent::DeskAccepted);

            TeamThreadChanged::dispatch($locked->id, TeamThreadChanged::DESK, $fresh->id, $this->desk->auditorIds());

            return $locked;
        });
    }

    /**
     * Make the auditor a current participant (reusing her row from an
     * earlier request), with everything of this request still unread.
     */
    private function join(TeamThread $thread, User $auditor): void
    {
        $readUpTo = $thread->desk_request_message_id === null ? null : $thread->desk_request_message_id - 1;

        $participation = TeamParticipant::query()
            ->where('team_thread_id', $thread->id)
            ->where('user_id', $auditor->id)
            ->lockForUpdate()
            ->first();

        if ($participation === null) {
            (new TeamParticipant)->forceFill([
                'team_thread_id' => $thread->id,
                'user_id' => $auditor->id,
                'role' => TeamParticipantRole::Member,
                'joined_at' => now(),
                'added_by_user_id' => $auditor->id,
                'last_read_message_id' => $readUpTo,
            ])->save();

            return;
        }

        $participation->forceFill([
            'left_at' => null,
            'joined_at' => now(),
            'removed_by_user_id' => null,
            'last_read_message_id' => $readUpTo,
            'muted' => false,
        ])->save();
    }
}

<?php

namespace App\Actions\TeamChat;

use App\Enums\TeamChat\TeamChatSystemEvent;
use App\Enums\TeamChat\TeamDeskStatus;
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
 * «Solicitud completa»: the auditor attending an open «Mesa de control»
 * request closes it (`desk_completed_at`, `desk_completed_by_user_id`), a
 * system line records it in the thread, and she leaves the thread as a
 * participant (every auditor can still read it). The requester's next
 * message opens a new request on the waiting list (ControlDesk).
 *
 * Only the assignee completes her request; the thread row is locked, so a
 * message posted at the same moment lands entirely before or after.
 * Completing an already completed request is a no-op.
 */
class CompleteDeskRequest
{
    public function __construct(
        private ControlDesk $desk,
        private TeamThreadActivity $activity,
    ) {}

    /**
     * @throws TeamDeskConflict when the request is not open with this auditor.
     */
    public function handle(TeamThread $thread, User $actor): TeamThread
    {
        return DB::transaction(function () use ($thread, $actor): TeamThread {
            $locked = TeamThread::query()->whereKey($thread->id)->lockForUpdate()->firstOrFail();
            Gate::forUser($actor)->authorize('attendDesk', $locked);

            if ($locked->desk_status === TeamDeskStatus::Closed && $locked->desk_completed_by_user_id === $actor->id) {
                return $locked;
            }

            if (! $locked->isDeskAssignedTo($actor)) {
                throw $locked->desk_status === TeamDeskStatus::Open ? TeamDeskConflict::notYours() : TeamDeskConflict::notWaiting();
            }

            $locked->forceFill([
                'desk_status' => TeamDeskStatus::Closed,
                'desk_assigned_user_id' => null,
                'desk_completed_at' => now(),
                'desk_completed_by_user_id' => $actor->id,
            ])->save();

            $this->activity->recordSystemEvent($locked, $actor, TeamChatSystemEvent::DeskCompleted);

            // The requester always keeps her own desk thread, also when she
            // attended her own request (an administrator testing the desk).
            TeamParticipant::query()
                ->where('team_thread_id', $locked->id)
                ->where('user_id', $actor->id)
                ->where('user_id', '!=', (int) $locked->desk_requester_id)
                ->whereNull('left_at')
                ->update(['left_at' => now(), 'removed_by_user_id' => $actor->id, 'updated_at' => now()]);

            TeamThreadChanged::dispatch($locked->id, TeamThreadChanged::DESK, $actor->id, [...$this->desk->auditorIds(), $actor->id]);

            return $locked;
        });
    }
}

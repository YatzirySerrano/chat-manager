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
 * «Liberar solicitud»: the auditor attending an open «Mesa de control»
 * request returns it to the waiting list (pending, unassigned) so another
 * auditor takes it, as «Liberar» does with a customer conversation. It
 * keeps its place (`desk_requested_at` is not touched), a system line
 * records it and she leaves the thread as a participant, like on
 * completion. Releasing a request that is already waiting answers it
 * again (idempotent).
 */
class ReleaseDeskRequest
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

            if ($locked->desk_status === TeamDeskStatus::Pending && $locked->desk_assigned_user_id === null) {
                return $locked;
            }

            if (! $locked->isDeskAssignedTo($actor)) {
                throw $locked->desk_status === TeamDeskStatus::Open ? TeamDeskConflict::notYours() : TeamDeskConflict::notWaiting();
            }

            $locked->forceFill([
                'desk_status' => TeamDeskStatus::Pending,
                'desk_assigned_user_id' => null,
                'desk_accepted_at' => null,
            ])->save();

            $this->activity->recordSystemEvent($locked, $actor, TeamChatSystemEvent::DeskReleased);

            // The requester always keeps her own desk thread.
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

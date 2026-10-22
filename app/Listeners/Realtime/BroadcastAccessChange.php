<?php

namespace App\Listeners\Realtime;

use App\Broadcasting\RealtimeSignals;
use App\Events\Users\UserAccessChanged;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * The role, permissions, departments or state of these users changed: their
 * open sessions reload their permissions and authorize every channel again,
 * so access that was revoked stops at once (deleted accounts are signed out
 * by the reload itself).
 */
class BroadcastAccessChange implements ShouldQueue
{
    use QueuesRealtimeSignals;

    public function __construct(private RealtimeSignals $signals) {}

    public function handle(UserAccessChanged $event): void
    {
        $this->signals->toUsers(array_unique($event->userIds), 'access.changed', [
            'reason' => $event->reason,
        ]);
    }
}

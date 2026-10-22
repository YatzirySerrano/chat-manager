<?php

namespace App\Listeners\Realtime;

use App\Broadcasting\RealtimeChannels;
use App\Broadcasting\RealtimeSignals;
use App\Events\Users\AgentAvailabilityChanged;
use App\Models\User;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * An agent became available or unavailable: their other tabs update the
 * switch, and the team inboxes of their departments (supervisors, global
 * users) refresh who can receive conversations. Personal queues are not
 * told: agents never see their colleagues' availability.
 */
class BroadcastAvailabilityChange implements ShouldQueue
{
    use QueuesRealtimeSignals;

    public function __construct(private RealtimeSignals $signals) {}

    public function handle(AgentAvailabilityChanged $event): void
    {
        $user = User::query()->find($event->userId);

        if ($user === null) {
            return;
        }

        $this->signals->toUsers([$user->id], 'availability.changed', [
            'availability' => $event->availability,
        ]);

        $this->signals->send([
            RealtimeChannels::globalInbox(),
            ...array_map(RealtimeChannels::departmentInbox(...), $user->departmentIds()),
        ], 'availability.changed', [
            'user_id' => $user->id,
            'availability' => $event->availability,
        ]);
    }
}

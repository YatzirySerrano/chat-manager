<?php

namespace App\Listeners\Notifications;

use App\Broadcasting\RealtimeSignals;
use App\Events\TeamChat\TeamThreadRead;
use App\Models\User;
use App\Notifications\NotificationCenter;
use App\Notifications\NotificationKind;
use App\Support\Queues;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * Reading an internal thread (in any tab or device) reads its message and
 * mention notifications too, like opening a customer conversation does.
 * The user's bells are then told to refresh (the same content-free
 * `.notification` hint, published directly: a NotificationSignal would also
 * wake their browsers with a Web Push about news that is already read), so
 * a toast still on screen in another tab goes away and the counter drops.
 *
 * Queued on the notifications queue, behind the job that notifies the
 * message being read: a notification stored after the read would otherwise
 * stay unread (NotifyTeamChatMessage also skips messages already read).
 */
class ReadTeamChatNotifications implements ShouldQueue
{
    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [5, 30];

    public function __construct(private NotificationCenter $center, private RealtimeSignals $signals) {}

    public function viaQueue(): string
    {
        return Queues::name(Queues::NOTIFICATIONS);
    }

    public function handle(TeamThreadRead $event): void
    {
        $user = User::query()->find($event->userId);

        if ($user === null) {
            return;
        }

        $ids = $this->center->markTeamThreadRead($user, $event->threadId, $event->messageId);

        if ($ids !== [] && config('broadcasting.default') !== 'null') {
            $this->signals->toUsers([$user->id], 'notification', [
                'id' => $ids[0],
                'kind' => NotificationKind::TeamChatMessage->value,
            ]);
        }
    }
}

<?php

namespace App\Listeners\Notifications;

use App\Enums\TeamChat\TeamDeskStatus;
use App\Enums\TeamChat\TeamMessageKind;
use App\Events\TeamChat\TeamMessagePosted;
use App\Models\TeamChat\TeamThread;
use App\Models\User;
use App\Notifications\TeamDeskRequestNotification;
use App\Services\TeamChat\ControlDesk;
use App\Support\Queues;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Notification;

/**
 * The message that puts a desk thread on the waiting list (it is the
 * thread's `desk_request_message_id` and the request is still pending)
 * notifies every active auditor except the requester, so whoever is free
 * accepts it. Later messages of the same pending request do not repeat it.
 */
class NotifyDeskRequest implements ShouldQueue
{
    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [5, 30];

    public function __construct(private ControlDesk $desk) {}

    public function viaQueue(): string
    {
        return Queues::name(Queues::NOTIFICATIONS);
    }

    public function handle(TeamMessagePosted $event): void
    {
        if ($event->kind !== TeamMessageKind::Message->value || $event->authorId === null) {
            return;
        }

        $thread = TeamThread::query()->find($event->threadId);

        if ($thread === null
            || ! $thread->isDesk()
            || $thread->desk_status !== TeamDeskStatus::Pending
            || $thread->desk_request_message_id !== $event->messageId
            || $thread->desk_requester_id !== $event->authorId) {
            return;
        }

        $recipients = User::query()
            ->whereKey(array_values(array_diff($this->desk->auditorIds(), [$event->authorId])))
            ->get();

        if ($recipients->isEmpty()) {
            return;
        }

        Notification::send($recipients, new TeamDeskRequestNotification($thread->id, $event->messageId, $event->authorId));
    }
}

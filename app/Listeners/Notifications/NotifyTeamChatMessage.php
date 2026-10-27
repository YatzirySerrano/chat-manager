<?php

namespace App\Listeners\Notifications;

use App\Enums\Permission;
use App\Enums\TeamChat\TeamMessageKind;
use App\Events\TeamChat\TeamMessagePosted;
use App\Models\TeamChat\TeamMessage;
use App\Models\TeamChat\TeamParticipant;
use App\Notifications\NotificationCenter;
use App\Notifications\TeamChatMessageNotification;
use App\Support\Queues;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * A message posted in an internal thread notifies its other current
 * participants (not the author, not those who muted the thread), aggregated
 * per thread. A participant listed in the message's `metadata.mentions`
 * (user ids) receives an urgent mention instead, even if the thread is muted.
 * System lines (joins, renames) notify nobody, and neither does a message
 * the participant already read (their chat was open when it arrived and
 * marked it read before this queued job ran). A read that lands while the
 * notification is being stored (another worker ran ReadTeamChatNotifications
 * in between) is checked again right after, so it never stays unread.
 */
class NotifyTeamChatMessage implements ShouldQueue
{
    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [5, 30];

    public function __construct(private NotificationCenter $center) {}

    public function viaQueue(): string
    {
        return Queues::name(Queues::NOTIFICATIONS);
    }

    public function handle(TeamMessagePosted $event): void
    {
        $message = TeamMessage::query()->find($event->messageId);

        if ($message === null || $message->kind !== TeamMessageKind::Message || $message->isDeleted() || $message->team_thread_id !== $event->threadId) {
            return;
        }

        $mentions = array_values(array_filter((array) ($message->metadata['mentions'] ?? []), 'is_int'));

        $participants = TeamParticipant::query()
            ->with(['user.roles.permissions'])
            ->where('team_thread_id', $event->threadId)
            ->whereNull('left_at')
            ->when($message->user_id !== null, fn ($query) => $query->where('user_id', '!=', $message->user_id))
            ->get();

        foreach ($participants as $participant) {
            $user = $participant->user;
            $mentioned = in_array($participant->user_id, $mentions, true);

            if ($user === null || ! $user->is_active || ! $user->holdsPermission(Permission::UseTeamChat) || ($participant->muted && ! $mentioned)) {
                continue;
            }

            if ((int) $participant->last_read_message_id >= $message->id) {
                continue;
            }

            $user->notify(new TeamChatMessageNotification($event->threadId, $message->id, $message->user_id, $mentioned));

            $readUpTo = (int) TeamParticipant::query()->whereKey($participant->id)->value('last_read_message_id');

            if ($readUpTo >= $message->id) {
                $this->center->markTeamThreadRead($user, $event->threadId, $readUpTo);
            }
        }
    }
}

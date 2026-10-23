<?php

namespace App\Listeners\Notifications;

use App\Jobs\SendWebPush;
use App\Models\PushSubscription;
use App\Models\User;
use App\Notifications\NotificationKind;
use App\Notifications\NotificationLevel;
use App\Notifications\NotificationPreferences;
use App\Notifications\NotificationSignal;
use App\Notifications\Push\Vapid;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Hooks Web Push onto the in-app channel: NotificationSignal is dispatched
 * after the notification commits (InAppChannel), so a push only follows a
 * notification really stored for that user, with their permissions and
 * preferences already checked. It is sent when the user subscribed a
 * browser and keeps "Navegador" on for the kind.
 *
 * At most one push per user every `webpush.throttle_seconds` (atomic
 * Cache::add): a burst or an aggregated notification wakes each browser
 * once, and the browser shows the current unread counter, not one alert per
 * event. Sending happens in SendWebPush jobs on the notifications queue.
 *
 * Best-effort, like the realtime hint: the listener runs synchronously in the
 * job that fans the in-app notification out, so any failure (cache store,
 * database, OpenSSL) is reported and swallowed instead of interrupting or
 * retrying the in-app delivery for the remaining recipients.
 */
class QueueWebPush
{
    public function __construct(private Vapid $vapid) {}

    public function handle(NotificationSignal $event): void
    {
        try {
            $this->queuePushFor($event);
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    private function queuePushFor(NotificationSignal $event): void
    {
        $kind = NotificationKind::tryFrom($event->kind);

        if ($kind === null || ! $this->vapid->isConfigured()) {
            return;
        }

        $subscriptionIds = PushSubscription::query()->where('user_id', $event->userId)->pluck('id');

        if ($subscriptionIds->isEmpty()) {
            return;
        }

        $user = User::query()->active()->find($event->userId);

        if ($user === null || ! app(NotificationPreferences::class)->of($user, $kind)['browser']) {
            return;
        }

        $seconds = max(1, (int) config('webpush.throttle_seconds', 60));

        if (! Cache::add("webpush:throttle:{$user->id}", true, $seconds)) {
            return;
        }

        $urgency = $kind->level() === NotificationLevel::Urgent ? 'high' : 'normal';

        foreach ($subscriptionIds as $subscriptionId) {
            SendWebPush::dispatch((int) $subscriptionId, $urgency);
        }
    }
}

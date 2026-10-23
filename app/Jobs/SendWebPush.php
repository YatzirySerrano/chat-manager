<?php

namespace App\Jobs;

use App\Models\PushSubscription;
use App\Notifications\Push\Vapid;
use App\Support\Queues;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Wakes one subscribed browser with a push WITHOUT payload: the push
 * service learns nothing but "there is something new" (no encryption
 * needed, no content leaves the server). The service worker then fetches
 * the unread counter with the user's own session (public/sw.js).
 *
 * One job per subscription, on the notifications queue (never a critical
 * one), so a retry never wakes the browsers that already got it.
 *
 * - 2xx: the subscription is alive (updated_at is touched; pruning uses it).
 * - 404/410: the browser unsubscribed or expired; the row is deleted.
 * - 429/5xx and connection errors: bounded retry (Retry-After honoured up
 *   to 5 minutes); after the last attempt it is logged and dropped, since a
 *   late push is worthless.
 * - Other 4xx (wrong VAPID keys, malformed request): logged, not retried.
 *
 * Logs never include the endpoint (it works as a capability URL).
 */
class SendWebPush implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /**
     * Delay before each retry (released, not thrown: a busy push service is
     * not an application error).
     *
     * @var list<int>
     */
    public array $backoff = [15, 60];

    public int $timeout = 30;

    public function __construct(public int $subscriptionId, public string $urgency = 'normal')
    {
        $this->onQueue(Queues::name(Queues::NOTIFICATIONS));
    }

    public function handle(Vapid $vapid): void
    {
        $subscription = PushSubscription::query()->with('user')->find($this->subscriptionId);

        if ($subscription === null || ! $vapid->isConfigured()) {
            return;
        }

        if (! $subscription->user->is_active) {
            $subscription->delete();

            return;
        }

        try {
            $response = Http::withoutRedirecting()
                ->timeout(max(1, (int) config('webpush.timeout', 5)))
                ->connectTimeout(max(1, (int) config('webpush.timeout', 5)))
                ->withHeaders([
                    'Authorization' => $vapid->authorization($subscription->endpoint),
                    'TTL' => (string) max(0, (int) config('webpush.ttl', 3600)),
                    'Urgency' => $this->urgency === 'high' ? 'high' : 'normal',
                    'Topic' => 'notifications',
                    'Content-Length' => '0',
                ])
                ->send('POST', $subscription->endpoint);
        } catch (ConnectionException) {
            $this->retryOrDrop($subscription, null);

            return;
        }

        $status = $response->status();

        if ($response->successful()) {
            $subscription->touch();

            return;
        }

        if ($status === 404 || $status === 410) {
            $subscription->delete();

            return;
        }

        if ($status === 429 || $response->serverError()) {
            $this->retryOrDrop($subscription, $status, (int) $response->header('Retry-After'));

            return;
        }

        Log::warning('Un servicio push rechazó la notificación.', [
            'subscription_id' => $subscription->id,
            'status' => $status,
        ]);
    }

    private function retryOrDrop(PushSubscription $subscription, ?int $status, int $retryAfter = 0): void
    {
        if ($this->attempts() >= $this->tries) {
            Log::warning('No se pudo entregar una notificación push tras varios intentos.', [
                'subscription_id' => $subscription->id,
                'status' => $status,
            ]);

            return;
        }

        $this->release($retryAfter > 0 ? min($retryAfter, 300) : ($this->backoff[$this->attempts() - 1] ?? 60));
    }
}

<?php

namespace App\Jobs;

use App\Actions\Messaging\ProcessMetaMessagingPayload;
use App\Actions\Messaging\ProcessWebhookPayload;
use App\Enums\ConnectionChannel;
use App\Enums\WebhookEventStatus;
use App\Models\WebhookEvent;
use App\Support\Queues;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Processes one stored webhook delivery on the webhooks queue. Every step it
 * runs is idempotent, so retries (bounded, with backoff) and repeated
 * deliveries never duplicate messages or move statuses backwards. After the
 * last attempt the event stays "failed" with a sanitized error and can be
 * processed again with whatsapp:retry-webhooks.
 */
class ProcessWhatsAppWebhook implements ShouldQueue
{
    use Queueable;

    public int $tries;

    public int $timeout = 120;

    public function __construct(public int $webhookEventId)
    {
        $this->onQueue(Queues::name(Queues::WEBHOOKS));
        $this->tries = max(1, (int) config('whatsapp.webhooks.tries', 5));
    }

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return array_values(array_map('intval', (array) config('whatsapp.webhooks.backoff', [10, 30, 120, 600])));
    }

    public function handle(ProcessWebhookPayload $process, ProcessMetaMessagingPayload $messaging): void
    {
        $event = WebhookEvent::query()->with('channelConnection')->find($this->webhookEventId);

        if ($event === null || $event->status === WebhookEventStatus::Processed) {
            return;
        }

        $event->increment('attempts');
        $connection = $event->channelConnection;

        if ($connection->isDeleted()) {
            $event->forceFill(['status' => WebhookEventStatus::Processed, 'processed_at' => now(), 'summary' => ['skipped' => 'connection_deleted']])->save();

            return;
        }

        try {
            // Messenger and Instagram events are stored per account by the
            // app-level webhook (webhooks/meta) and share this queue and log.
            $summary = in_array($connection->channel, [ConnectionChannel::Messenger, ConnectionChannel::Instagram], true)
                ? $messaging->handle($connection, $event->decodedPayload())
                : $process->handle($connection, $event->decodedPayload());
        } catch (Throwable $exception) {
            $event->forceFill(['error' => self::describe($exception)])->save();

            throw $exception;
        }

        $event->forceFill([
            'status' => WebhookEventStatus::Processed,
            'processed_at' => now(),
            'error' => null,
            'summary' => $summary,
        ])->save();
    }

    public function failed(?Throwable $exception): void
    {
        WebhookEvent::query()->whereKey($this->webhookEventId)->where('status', '!=', WebhookEventStatus::Processed)->update([
            'status' => WebhookEventStatus::Failed,
            'error' => $exception === null ? 'Se agotaron los intentos.' : self::describe($exception),
            'updated_at' => now(),
        ]);

        Log::error('WhatsApp webhook event could not be processed.', ['webhook_event_id' => $this->webhookEventId, 'exception' => $exception === null ? null : $exception::class]);
    }

    /**
     * Error kept on the event: the exception class and a trimmed message,
     * without the payload (it may contain customer data).
     */
    private static function describe(Throwable $exception): string
    {
        if ($exception instanceof QueryException) {
            return 'QueryException: SQLSTATE '.$exception->getCode();
        }

        return Str::limit(class_basename($exception).': '.preg_replace('/\s+/', ' ', $exception->getMessage()), 480, '');
    }
}

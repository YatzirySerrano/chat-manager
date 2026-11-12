<?php

namespace App\Jobs;

use App\Actions\Messaging\ProcessMetaMessagingPayload;
use App\Actions\Messaging\ProcessWebhookPayload;
use App\Enums\ConnectionChannel;
use App\Enums\WebhookEventStatus;
use App\Models\WebhookEvent;
use App\Support\Queues;
use Carbon\CarbonImmutable;
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
 *
 * The summary of a processed event keeps its timing (UTC, milliseconds):
 * when Meta says the earliest message or status happened (meta_occurred_at,
 * whole seconds), when the body was received and stored, when this attempt
 * started and when it finished, with the queue wait and processing time.
 * Timestamps and durations only, never content.
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
        $startedAt = microtime(true);
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

        $payload = $event->decodedPayload();

        try {
            // Messenger and Instagram events are stored per account by the
            // app-level webhook (webhooks/meta) and share this queue and log.
            $summary = in_array($connection->channel, [ConnectionChannel::Messenger, ConnectionChannel::Instagram], true)
                ? $messaging->handle($connection, $payload)
                : $process->handle($connection, $payload);
        } catch (Throwable $exception) {
            $event->forceFill(['error' => self::describe($exception)])->save();

            throw $exception;
        }

        $event->forceFill([
            'status' => WebhookEventStatus::Processed,
            'processed_at' => now(),
            'error' => null,
            'summary' => [...$summary, 'timing' => self::timing($event, $payload, $startedAt, microtime(true))],
        ])->save();
    }

    /**
     * @param  array<array-key, mixed>  $payload
     * @return array<string, int|string>
     */
    private static function timing(WebhookEvent $event, array $payload, float $startedAt, float $processedAt): array
    {
        $receivedAt = (float) $event->received_at->getPreciseTimestamp(3) / 1000;
        $occurredAt = self::earliestProviderTimestamp($payload);
        $milliseconds = fn (float $from, float $to): int => max(0, (int) round(($to - $from) * 1000));
        $stamp = fn (float $at): string => CarbonImmutable::createFromTimestampMs((int) round($at * 1000), 'UTC')->format('Y-m-d\TH:i:s.v\Z');

        return array_filter([
            'meta_occurred_at' => $occurredAt === null ? null : $stamp((float) $occurredAt),
            'received_at' => $stamp($receivedAt),
            'job_started_at' => $stamp($startedAt),
            'processed_at' => $stamp($processedAt),
            'meta_to_received_ms' => $occurredAt === null ? null : $milliseconds((float) $occurredAt, $receivedAt),
            'queue_wait_ms' => $milliseconds($receivedAt, $startedAt),
            'processing_ms' => $milliseconds($startedAt, $processedAt),
            'attempt' => (int) $event->attempts,
        ], fn (int|string|null $value): bool => $value !== null);
    }

    /**
     * Earliest message or status timestamp of a WhatsApp body (Unix seconds).
     *
     * @param  array<array-key, mixed>  $payload
     */
    private static function earliestProviderTimestamp(array $payload): ?int
    {
        $earliest = null;

        foreach ((array) ($payload['entry'] ?? []) as $entry) {
            foreach ((array) (is_array($entry) ? ($entry['changes'] ?? []) : []) as $change) {
                $value = is_array($change) && is_array($change['value'] ?? null) ? $change['value'] : [];

                foreach ([...(array) ($value['messages'] ?? []), ...(array) ($value['statuses'] ?? [])] as $item) {
                    $timestamp = is_array($item) ? ($item['timestamp'] ?? null) : null;

                    if (is_numeric($timestamp) && (int) $timestamp > 0) {
                        $earliest = $earliest === null ? (int) $timestamp : min($earliest, (int) $timestamp);
                    }
                }
            }
        }

        return $earliest;
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

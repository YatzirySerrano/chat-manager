<?php

namespace App\Support;

use Illuminate\Support\Facades\Context;
use Illuminate\Support\Str;

/**
 * Correlation identifiers shared by every log entry of the current request
 * or job (Laravel Context: they are appended to each log record and travel
 * with jobs dispatched from here). Only identifiers are accepted, never
 * message bodies, phone numbers or payloads, so a webhook delivery, the
 * messages it created and their conversation can be followed across the
 * HTTP request, the webhook job and the delivery jobs.
 *
 * Every queued job gets its class, queue and the identifiers of its public
 * constructor properties (webhookEventId, messageId, conversationId...)
 * automatically (see OperationsServiceProvider); jobs and actions may add
 * more with LogContext::add().
 */
final class LogContext
{
    /**
     * Context keys this helper writes.
     *
     * @var list<string>
     */
    public const KEYS = [
        'request_id', 'job', 'job_id', 'queue', 'webhook_event_id', 'message_id', 'conversation_id',
        'attachment_id', 'connection_id', 'transfer_id', 'contact_id', 'thread_id',
    ];

    /**
     * Job property names (camelCase) mapped to context keys.
     *
     * @var array<string, string>
     */
    private const JOB_PROPERTIES = [
        'webhookEventId' => 'webhook_event_id',
        'messageId' => 'message_id',
        'conversationId' => 'conversation_id',
        'attachmentId' => 'attachment_id',
        'connectionId' => 'connection_id',
        'transferId' => 'transfer_id',
        'contactId' => 'contact_id',
        'threadId' => 'thread_id',
    ];

    /**
     * Add correlation identifiers; unknown keys and non-scalar values are ignored.
     *
     * @param  array<string, int|string|null>  $identifiers
     */
    public static function add(array $identifiers): void
    {
        foreach ($identifiers as $key => $value) {
            if (in_array($key, self::KEYS, true) && (is_int($value) || (is_string($value) && $value !== ''))) {
                Context::add($key, is_string($value) ? Str::limit($value, 120, '') : $value);
            }
        }
    }

    public static function forWebhookEvent(int $webhookEventId, ?int $connectionId = null): void
    {
        self::add(['webhook_event_id' => $webhookEventId, 'connection_id' => $connectionId]);
    }

    public static function forMessage(int $messageId, ?int $conversationId = null): void
    {
        self::add(['message_id' => $messageId, 'conversation_id' => $conversationId]);
    }

    public static function forConversation(int $conversationId): void
    {
        self::add(['conversation_id' => $conversationId]);
    }

    /**
     * Identifiers of a queued job, read from its payload without
     * unserializing the command again: the display name, the queue and the
     * integer public properties listed in JOB_PROPERTIES.
     *
     * @param  array<array-key, mixed>  $payload
     * @return array<string, int|string>
     */
    public static function fromJobPayload(array $payload, ?string $queue = null): array
    {
        $identifiers = [];

        if (is_string($payload['displayName'] ?? null)) {
            $identifiers['job'] = $payload['displayName'];
        }

        if (is_string($payload['uuid'] ?? null)) {
            $identifiers['job_id'] = $payload['uuid'];
        }

        if ($queue !== null && $queue !== '') {
            $identifiers['queue'] = $queue;
        }

        $command = $payload['data']['command'] ?? null;

        if (is_string($command)) {
            foreach (self::JOB_PROPERTIES as $property => $key) {
                if (preg_match('/s:'.strlen($property).':"'.$property.'";i:(\d+);/', $command, $matches) === 1) {
                    $identifiers[$key] = (int) $matches[1];
                }
            }
        }

        return $identifiers;
    }
}

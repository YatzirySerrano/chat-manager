<?php

namespace App\Notifications;

/**
 * A conversation that went over a time limit of «Alertas de tiempo»
 * (ConversationTimeAlerts):
 *
 * - queue (general): a request ready for attention has waited in
 *   «Pendientes» longer than the limit; told to whoever can take it.
 * - attention (individual): the agent has had it «En atención» longer
 *   than the limit without resolving it; told to that agent.
 *
 * Idempotent per conversation and wait/attention start (sourceKey), so the
 * evaluator may run again without repeating the alert.
 */
class ConversationOverdueNotification extends InAppNotification
{
    public const QUEUE = 'queue';

    public const ATTENTION = 'attention';

    /**
     * @param  'queue'|'attention'  $type
     */
    public function __construct(
        public readonly string $type,
        public readonly int $conversationId,
        public readonly ?int $departmentId,
        public readonly int $minutes,
        public readonly string $since,
    ) {}

    public function kind(): NotificationKind
    {
        return $this->type === self::QUEUE ? NotificationKind::ConversationQueueOverdue : NotificationKind::ConversationAttentionOverdue;
    }

    public function sourceKey(): string
    {
        return "{$this->kind()->value}:{$this->conversationId}:{$this->since}";
    }

    public function payload(): array
    {
        return [
            'conversation_id' => $this->conversationId,
            'department_id' => $this->departmentId,
            'minutes' => $this->minutes,
        ];
    }
}

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
     * `$priority` and `$message` come from the configured escalation step
     * («Alertas automáticas»): the urgency of this alert and an optional text
     * that replaces the default. Each step alerts once per conversation and
     * wait/attention start, so `$minutes` joins the sourceKey.
     *
     * @param  'queue'|'attention'  $type
     */
    public function __construct(
        public readonly string $type,
        public readonly int $conversationId,
        public readonly ?int $departmentId,
        public readonly int $minutes,
        public readonly string $since,
        public readonly string $priority = 'urgent',
        public readonly ?string $message = null,
    ) {}

    public function kind(): NotificationKind
    {
        return $this->type === self::QUEUE ? NotificationKind::ConversationQueueOverdue : NotificationKind::ConversationAttentionOverdue;
    }

    /**
     * The step's urgency wins over the kind's base level.
     */
    public function level(): NotificationLevel
    {
        return NotificationLevel::tryFrom($this->priority) ?? NotificationLevel::Urgent;
    }

    public function sourceKey(): string
    {
        return "{$this->kind()->value}:{$this->conversationId}:{$this->since}:{$this->minutes}";
    }

    public function payload(): array
    {
        return [
            'conversation_id' => $this->conversationId,
            'department_id' => $this->departmentId,
            'minutes' => $this->minutes,
            'message' => $this->message,
        ];
    }
}

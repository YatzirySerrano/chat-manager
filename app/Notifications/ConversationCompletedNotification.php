<?php

namespace App\Notifications;

/**
 * A request was marked as completed («Solicitud completada»). The whole team
 * that can see the conversation is told, so everyone knows it was resolved.
 *
 * The urgency («prioridad») and the optional extra message are configured in
 * Seguimiento de atención → «Alertas automáticas» (time_alerts.completed_*).
 *
 * Idempotent per conversation and completion time (sourceKey), so a retried
 * dispatch never repeats the alert.
 */
class ConversationCompletedNotification extends InAppNotification
{
    public function __construct(
        public readonly int $conversationId,
        public readonly ?int $departmentId,
        public readonly ?int $actorId,
        public readonly string $completedAt,
        public readonly string $priority = 'informative',
        public readonly ?string $message = null,
    ) {}

    public function kind(): NotificationKind
    {
        return NotificationKind::ConversationCompleted;
    }

    /**
     * The configured urgency wins over the kind's base level, so an
     * administrator can make the completion alert «Requiere acción».
     */
    public function level(): NotificationLevel
    {
        return NotificationLevel::tryFrom($this->priority) ?? NotificationLevel::Informative;
    }

    public function sourceKey(): string
    {
        return "{$this->kind()->value}:{$this->conversationId}:{$this->completedAt}";
    }

    public function payload(): array
    {
        return [
            'conversation_id' => $this->conversationId,
            'department_id' => $this->departmentId,
            'actor_id' => $this->actorId,
            'message' => $this->message,
        ];
    }
}

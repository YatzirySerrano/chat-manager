<?php

namespace App\Notifications;

/**
 * Automatic SLA alert raised by the evaluator (`sla:evaluate`) for one
 * response cycle: «por vencer» (warning) or «vencida» (breach, and its
 * bounded reminders). Not to be confused with the manual attention alerts
 * a supervisor writes (`attention_alerts`).
 *
 * Idempotent per recipient: the source key is the cycle and its stage (plus
 * the reminder number), so the same transition never notifies twice.
 *
 * - The responsible agent gets one notification per cycle; the reminders of
 *   a breach fold into it while unread.
 * - Supervisors get one unread notification per stage and department that
 *   gathers the cycles (never one item per request).
 *
 * Only identifiers are stored: the text is built when read and tells what
 * the request is like at that moment (NotificationPresenter).
 */
class SlaAlertNotification extends InAppNotification
{
    public const STAGE_WARNING = 'warning';

    public const STAGE_BREACH = 'breach';

    /** Cycles remembered by an aggregated notification. */
    public const MAX_TRACKED = 20;

    public function __construct(
        public readonly string $stage,
        public readonly int $cycleId,
        public readonly int $conversationId,
        public readonly ?int $departmentId,
        public readonly bool $forSupervisor = false,
        public readonly int $reminder = 0,
    ) {}

    public function kind(): NotificationKind
    {
        return $this->stage === self::STAGE_BREACH ? NotificationKind::SlaBreach : NotificationKind::SlaWarning;
    }

    public function sourceKey(): string
    {
        return "sla:{$this->cycleId}:{$this->stage}".($this->reminder > 0 ? ":r{$this->reminder}" : '');
    }

    public function aggregateKey(): ?string
    {
        if ($this->forSupervisor) {
            return "sla.{$this->stage}:department:".($this->departmentId ?? 'none');
        }

        return $this->stage === self::STAGE_BREACH ? "sla.breach:cycle:{$this->cycleId}" : null;
    }

    public function payload(): array
    {
        return [
            'conversation_id' => $this->conversationId,
            'department_id' => $this->departmentId,
            'cycle_id' => $this->cycleId,
            'cycle_ids' => [$this->cycleId],
            'conversation_ids' => [$this->conversationId],
            'supervisor' => $this->forSupervisor,
            'reminder' => $this->reminder,
        ];
    }

    public function aggregate(array $existing): array
    {
        $merge = fn (string $key, int $newest): array => array_slice(array_values(array_unique([
            $newest,
            ...array_values(array_filter((array) ($existing[$key] ?? []), 'is_int')),
        ])), 0, self::MAX_TRACKED);

        return [
            ...$this->payload(),
            'cycle_ids' => $merge('cycle_ids', $this->cycleId),
            'conversation_ids' => $merge('conversation_ids', $this->conversationId),
        ];
    }
}

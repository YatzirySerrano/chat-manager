<?php

namespace App\Enums;

/**
 * Attention lifecycle of a conversation (a "ticket").
 *
 *   pending ──accept/assign──▶ open
 *      ▲  ◀──release/transfer──  │
 *      │                         ▼
 *      └────────reopen──────── closed
 *
 * Any transition not listed in transitions() is rejected by the engine.
 * `on_hold` is a retired (legacy) state: no transition leads into it any
 * more; old rows only leave it (conversations:retire-on-hold).
 */
enum ConversationStatus: string
{
    /** Waiting in a queue (a department or the unrouted queue) without an agent. */
    case Pending = 'pending';

    /** An authorized agent is responsible for it and attending it. */
    case Open = 'open';

    /**
     * Legacy: attention paused keeping its agent. Retired operationally (no
     * transition leads into it, nothing offers it); the case stays so old
     * rows, metrics and timelines keep deserializing. Such rows still count
     * as "in attention" for load and capacity until they are migrated with
     * `php artisan conversations:retire-on-hold --apply`.
     */
    case OnHold = 'on_hold';

    /** Attention finished; history is kept and it can be reopened. */
    case Closed = 'closed';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pendiente',
            self::Open => 'En atención',
            self::OnHold => 'En espera (estado ya no usado)',
            self::Closed => 'Cerrada',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Pending => 'Espera a que un agente la acepte o se le asigne.',
            self::Open => 'Un agente es responsable de atenderla.',
            self::OnHold => 'Estado retirado: la atención estaba pausada y conservaba a su agente.',
            self::Closed => 'La atención terminó; su historial se conserva.',
        };
    }

    /**
     * States reachable from this one. Moving to the same state is allowed only
     * where it has a meaning (a pending conversation changing queue, an open
     * one changing agent).
     *
     * @return list<self>
     */
    public function transitions(): array
    {
        return match ($this) {
            self::Pending => [self::Pending, self::Open, self::Closed],
            self::Open => [self::Open, self::Pending, self::Closed],
            self::OnHold => [self::Open, self::Pending, self::Closed],
            self::Closed => [self::Pending, self::Open],
        };
    }

    public function canTransitionTo(self $target): bool
    {
        return in_array($target, $this->transitions(), true);
    }

    /**
     * Whether an agent is responsible for the conversation in this state.
     * Legacy on_hold rows keep their agent until they are migrated.
     */
    public function hasResponsible(): bool
    {
        return $this === self::Open || $this === self::OnHold;
    }

    /**
     * Whether the state is retired and only kept for old rows.
     */
    public function isLegacy(): bool
    {
        return $this === self::OnHold;
    }

    /**
     * States in operation: the ones offered to users (filters, reports,
     * API) and reachable through transitions.
     *
     * @return list<self>
     */
    public static function operational(): array
    {
        return array_values(array_filter(self::cases(), fn (self $status): bool => ! $status->isLegacy()));
    }

    /**
     * States in which an agent is attending the conversation: counted as
     * load against the capacity limit and as "en atención" in metrics.
     * Includes legacy on_hold rows so nothing changes before they are migrated.
     *
     * @return list<self>
     */
    public static function attending(): array
    {
        return [self::Open, self::OnHold];
    }

    /**
     * @return list<array{value: string, label: string, description: string}>
     */
    public static function options(): array
    {
        return array_map(fn (self $status): array => [
            'value' => $status->value,
            'label' => $status->label(),
            'description' => $status->description(),
        ], self::operational());
    }
}

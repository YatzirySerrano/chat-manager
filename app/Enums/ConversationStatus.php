<?php

namespace App\Enums;

/**
 * Attention lifecycle of a conversation (a "ticket").
 *
 *   pending ──accept/assign──▶ open ──hold──▶ on_hold
 *      ▲  ◀──release/transfer──  │  ◀──resume──   │
 *      │                         ▼                ▼
 *      └────────reopen──────── closed ◀──close────┘
 *
 * Any transition not listed in transitions() is rejected by the engine.
 */
enum ConversationStatus: string
{
    /** Waiting in a queue (a department or the unrouted queue) without an agent. */
    case Pending = 'pending';

    /** An authorized agent is responsible for it and attending it. */
    case Open = 'open';

    /** Attention is not finished but is paused; it keeps its agent. */
    case OnHold = 'on_hold';

    /** Attention finished; history is kept and it can be reopened. */
    case Closed = 'closed';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pendiente',
            self::Open => 'En atención',
            self::OnHold => 'En espera',
            self::Closed => 'Cerrada',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Pending => 'Espera a que un agente la acepte o se le asigne.',
            self::Open => 'Un agente es responsable de atenderla.',
            self::OnHold => 'La atención está pausada temporalmente y conserva a su agente.',
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
            self::Open => [self::Open, self::Pending, self::OnHold, self::Closed],
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
     */
    public function hasResponsible(): bool
    {
        return $this === self::Open || $this === self::OnHold;
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
        ], self::cases());
    }
}

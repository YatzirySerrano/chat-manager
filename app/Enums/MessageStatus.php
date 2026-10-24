<?php

namespace App\Enums;

/**
 * Delivery status of a message. Outbound messages only move forward:
 *
 * queued (stored, waiting for a worker) → sending (a worker claimed it and is
 * calling Meta) → accepted (Meta's API returned a message id) → sent →
 * delivered → read. The last three only come from Meta's status webhooks.
 * failed is terminal; only an explicit, authorized retry queues the message
 * again. Inbound messages are always "received".
 */
enum MessageStatus: string
{
    case Received = 'received';
    case Queued = 'queued';
    case Sending = 'sending';
    case Accepted = 'accepted';
    case Sent = 'sent';
    case Delivered = 'delivered';
    case Read = 'read';
    case Failed = 'failed';

    /**
     * Label shown to people. Presentation only: queued and sending both read
     * "Enviando", and accepted and sent both read "Enviado"; the stored
     * values keep the full progression.
     */
    public function label(): string
    {
        return match ($this) {
            self::Received => 'Recibido',
            self::Queued, self::Sending => 'Enviando',
            self::Accepted, self::Sent => 'Enviado',
            self::Delivered => 'Entregado',
            self::Read => 'Leído',
            self::Failed => 'Fallido',
        };
    }

    /**
     * Position in the outbound progression; failed and received are outside it.
     */
    public function rank(): int
    {
        return match ($this) {
            self::Queued => 1,
            self::Sending => 2,
            self::Accepted => 3,
            self::Sent => 4,
            self::Delivered => 5,
            self::Read => 6,
            self::Received, self::Failed => 0,
        };
    }

    /**
     * Whether a status reported later may replace this one (forward only).
     */
    public function canAdvanceTo(self $next): bool
    {
        if ($this === self::Failed || $this === self::Received || $next === self::Received) {
            return false;
        }

        if ($next === self::Failed) {
            return $this->rank() < self::Delivered->rank();
        }

        return $next->rank() > $this->rank();
    }

    /**
     * Whether Meta has confirmed the message (it has a provider id).
     */
    public function isConfirmedByProvider(): bool
    {
        return $this->rank() >= self::Accepted->rank();
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(fn (self $status): array => ['value' => $status->value, 'label' => $status->label()], self::cases());
    }
}

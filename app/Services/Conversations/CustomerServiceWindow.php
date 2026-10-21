<?php

namespace App\Services\Conversations;

use App\Enums\CustomerWindowStatus;
use App\Models\Conversation;
use App\Support\CustomerWindowState;
use Carbon\CarbonImmutable;
use DateTimeInterface;

/**
 * WhatsApp lets a business send ordinary messages only within 24 hours of
 * the customer's last message. The window depends exclusively on the last
 * inbound message (`last_inbound_at`, the provider's timestamp, kept as the
 * latest one even when events arrive out of order): replies, internal notes,
 * transfers and reopening a conversation never extend it.
 *
 * This only answers whether a free-form message is allowed. It says nothing
 * about pricing, which follows the provider's current rules.
 */
class CustomerServiceWindow
{
    public const WINDOW_HOURS = 24;

    /** Remaining time below which the window is reported as about to expire. */
    public const WARNING_MINUTES = 60;

    public function for(Conversation $conversation, ?DateTimeInterface $now = null): CustomerWindowState
    {
        return $this->at($conversation->last_inbound_at, $now);
    }

    public function at(?DateTimeInterface $lastInboundAt, ?DateTimeInterface $now = null): CustomerWindowState
    {
        $now = CarbonImmutable::instance($now ?? now())->utc();

        if ($lastInboundAt === null) {
            return new CustomerWindowState(
                CustomerWindowStatus::None,
                null,
                null,
                0,
                'El cliente todavía no ha escrito. Iniciar una conversación requiere una plantilla aprobada por Meta, que no se envía desde el compositor de los agentes.',
            );
        }

        $lastInbound = CarbonImmutable::instance($lastInboundAt)->utc();
        $expiresAt = $lastInbound->addHours(self::WINDOW_HOURS);
        $remaining = max(0, (int) $now->diffInSeconds($expiresAt, false));

        if ($remaining === 0) {
            return new CustomerWindowState(
                CustomerWindowStatus::Expired,
                $lastInbound,
                $expiresAt,
                0,
                'Pasaron más de 24 horas desde el último mensaje del cliente. Podrás responder cuando vuelva a escribir.',
            );
        }

        return new CustomerWindowState(
            $remaining <= self::WARNING_MINUTES * 60 ? CustomerWindowStatus::Expiring : CustomerWindowStatus::Open,
            $lastInbound,
            $expiresAt,
            $remaining,
            null,
        );
    }
}

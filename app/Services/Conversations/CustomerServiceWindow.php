<?php

namespace App\Services\Conversations;

use App\Enums\ConnectionChannel;
use App\Enums\CustomerWindowStatus;
use App\Models\Connection;
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
 *
 * Messenger and Instagram follow the same 24 hour rule. When the Meta app
 * has the Human Agent feature approved (services.meta.human_agent_tag),
 * their window for human replies lasts HUMAN_AGENT_WINDOW_HOURS (7 days)
 * and MetaMessagingTransport tags replies past 24 hours with HUMAN_AGENT.
 *
 * Channels without that window (the web chat) are always NotApplicable and
 * allow free-form messages. The channel comes from the loaded connection
 * when it carries it, otherwise from one query per connection (the channel
 * of a connection never changes), so lists never trigger lazy loading.
 */
class CustomerServiceWindow
{
    public const WINDOW_HOURS = 24;

    /** Window of human replies on Messenger/Instagram with the HUMAN_AGENT tag. */
    public const HUMAN_AGENT_WINDOW_HOURS = 168;

    /** Remaining time below which the window is reported as about to expire. */
    public const WARNING_MINUTES = 60;

    /** @var array<int, ConnectionChannel|null> */
    private array $channels = [];

    public function for(Conversation $conversation, ?DateTimeInterface $now = null): CustomerWindowState
    {
        $channel = $this->channelOf($conversation);

        if ($channel !== null && ! $channel->usesCustomerServiceWindow()) {
            return new CustomerWindowState(CustomerWindowStatus::NotApplicable, null, null, 0, null);
        }

        return $this->at($conversation->last_inbound_at, $now, self::hoursFor($channel));
    }

    /**
     * Length of the window on the channel: 24 hours, or 7 days on Messenger
     * and Instagram when the HUMAN_AGENT tag is enabled.
     */
    public static function hoursFor(?ConnectionChannel $channel): int
    {
        return in_array($channel, [ConnectionChannel::Messenger, ConnectionChannel::Instagram], true) && self::humanAgentTagEnabled()
            ? self::HUMAN_AGENT_WINDOW_HOURS
            : self::WINDOW_HOURS;
    }

    public static function humanAgentTagEnabled(): bool
    {
        return (bool) config('services.meta.human_agent_tag', false);
    }

    public function at(?DateTimeInterface $lastInboundAt, ?DateTimeInterface $now = null, int $hours = self::WINDOW_HOURS): CustomerWindowState
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
        $expiresAt = $lastInbound->addHours($hours);
        $remaining = max(0, (int) $now->diffInSeconds($expiresAt, false));

        if ($remaining === 0) {
            return new CustomerWindowState(
                CustomerWindowStatus::Expired,
                $lastInbound,
                $expiresAt,
                0,
                ($hours === self::HUMAN_AGENT_WINDOW_HOURS ? 'Pasaron más de 7 días' : 'Pasaron más de '.$hours.' horas').' desde el último mensaje del cliente. Podrás responder cuando vuelva a escribir.',
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

    private function channelOf(Conversation $conversation): ?ConnectionChannel
    {
        $connection = $conversation->relationLoaded('channelConnection') ? $conversation->channelConnection : null;

        if ($connection instanceof Connection && array_key_exists('channel', $connection->getAttributes())) {
            return $connection->channel;
        }

        $connectionId = $conversation->connection_id;

        if (! array_key_exists($connectionId, $this->channels)) {
            $value = Connection::query()->whereKey($connectionId)->toBase()->value('channel');
            $this->channels[$connectionId] = is_string($value) ? ConnectionChannel::tryFrom($value) : null;
        }

        return $this->channels[$connectionId];
    }
}

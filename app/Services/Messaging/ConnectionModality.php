<?php

namespace App\Services\Messaging;

use App\Enums\ConnectionProvider;
use App\Models\Connection;
use App\Models\Conversation;
use Illuminate\Container\Attributes\Scoped;

/**
 * How the connection of a conversation is operated, for the rules that
 * differ inside one channel: a WhatsApp connection is either WhatsApp Cloud
 * API (24 hour window, approved templates) or a phone linked by QR
 * (WhatsApp Web: neither). The provider of a connection never changes, so
 * it is read from the loaded connection when it carries it and otherwise
 * from one query per connection and request (lists never lazy load).
 */
#[Scoped]
class ConnectionModality
{
    /** @var array<int, ConnectionProvider|null> */
    private array $providers = [];

    public function isWhatsAppWeb(Conversation $conversation): bool
    {
        return $this->providerOf($conversation) === ConnectionProvider::WhatsappWeb;
    }

    public function providerOf(Conversation $conversation): ?ConnectionProvider
    {
        $connection = $conversation->relationLoaded('channelConnection') ? $conversation->channelConnection : null;

        if ($connection instanceof Connection && array_key_exists('provider', $connection->getAttributes())) {
            return $connection->provider;
        }

        $connectionId = (int) $conversation->connection_id;

        if (! array_key_exists($connectionId, $this->providers)) {
            $value = Connection::query()->whereKey($connectionId)->toBase()->value('provider');
            $this->providers[$connectionId] = is_string($value) ? ConnectionProvider::tryFrom($value) : null;
        }

        return $this->providers[$connectionId];
    }
}

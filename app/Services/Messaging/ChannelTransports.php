<?php

namespace App\Services\Messaging;

use App\Enums\ConnectionChannel;
use App\Models\Connection;
use App\Services\Meta\WhatsAppTransport;
use App\Services\WebChat\WebChatTransport;

/**
 * Resolves the outbound transport of a connection by its channel. The
 * WhatsApp transport is resolved from the container on every call (never
 * cached), so the binding of config('whatsapp.transport') and the doubles
 * tests register with app()->instance() always apply.
 */
class ChannelTransports
{
    public function for(Connection $connection): MessageTransport
    {
        return match ($connection->channel) {
            ConnectionChannel::Whatsapp => app(WhatsAppTransport::class),
            ConnectionChannel::WebChat => app(WebChatTransport::class),
        };
    }
}

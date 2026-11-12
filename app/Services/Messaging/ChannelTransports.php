<?php

namespace App\Services\Messaging;

use App\Enums\ConnectionChannel;
use App\Models\Connection;
use App\Services\Meta\MetaMessagingTransport;
use App\Services\Meta\WhatsAppTransport;
use App\Services\WebChat\WebChatTransport;
use App\Services\WhatsAppWeb\WhatsAppWebTransport;

/**
 * Resolves the outbound transport of a connection by its channel. The
 * WhatsApp transport is resolved from the container on every call (never
 * cached), so the binding of config('whatsapp.transport') and the doubles
 * tests register with app()->instance() always apply. Messenger and
 * Instagram use the Meta Send API (MetaMessagingTransport). A WhatsApp
 * connection whose provider is "whatsapp_web" (a phone linked by QR) uses
 * the private WhatsApp Web service instead (WhatsAppWebTransport): the
 * modality is decided per connection, never by one global value.
 */
class ChannelTransports
{
    public function for(Connection $connection): MessageTransport
    {
        // A phone linked by QR never uses the Cloud API transport, whatever
        // config('whatsapp.transport') says; every other WhatsApp connection
        // keeps using it exactly as before.
        if ($connection->isWhatsAppWeb()) {
            return app(WhatsAppWebTransport::class);
        }

        return match ($connection->channel) {
            ConnectionChannel::Whatsapp => app(WhatsAppTransport::class),
            ConnectionChannel::WebChat => app(WebChatTransport::class),
            ConnectionChannel::Messenger, ConnectionChannel::Instagram => app(MetaMessagingTransport::class, ['channel' => $connection->channel]),
        };
    }
}

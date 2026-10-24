<?php

namespace App\Services\Meta;

use App\Models\Message;
use App\Services\Messaging\MessageTransport;

/**
 * Sends one stored outbound message (text, media with optional caption or
 * template, optionally replying to a customer message) to WhatsApp. Only the
 * delivery job calls it, after claiming the message and checking the
 * connection, the customer service window and the template rules again.
 */
interface WhatsAppTransport extends MessageTransport
{
    /**
     * @throws WhatsAppTransportUnavailable When this installation cannot send at all.
     * @throws MetaGraphException When the provider failed or refused the message (classified).
     */
    public function send(Message $message): SendResult;

    /**
     * Short label of the transport for diagnostics ("meta", "simulated", "unavailable").
     */
    public function name(): string;
}

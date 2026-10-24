<?php

namespace App\Services\Messaging;

use App\Models\Message;
use App\Services\Meta\SendResult;

/**
 * Sends one stored outbound message through the provider of its channel.
 * Only DeliverOutboundMessage calls it, after claiming the message and
 * checking the connection, the sender and the customer service window
 * again. ChannelTransports picks the implementation of each connection.
 */
interface MessageTransport
{
    /**
     * The provider message id it returns makes the message "accepted";
     * implementations throw when the provider failed or refused it.
     */
    public function send(Message $message): SendResult;

    /**
     * Short label of the transport for diagnostics ("meta", "simulated", "web_chat"…).
     */
    public function name(): string;
}

<?php

namespace App\Services\Meta;

use App\Models\Message;

/**
 * Sends an ordinary text message to WhatsApp Cloud API. Only the outbound
 * delivery job uses it, after checking the customer service window again.
 */
interface WhatsAppTransport
{
    /**
     * @return string The provider message id (Meta "wamid").
     *
     * @throws WhatsAppTransportUnavailable When the integration cannot send.
     */
    public function sendText(Message $message): string;
}

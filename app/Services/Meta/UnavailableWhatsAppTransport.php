<?php

namespace App\Services\Meta;

use App\Models\Message;

/**
 * Default transport while the WhatsApp Cloud API integration is not built:
 * it never pretends a message was delivered.
 */
class UnavailableWhatsAppTransport implements WhatsAppTransport
{
    public function sendText(Message $message): string
    {
        throw new WhatsAppTransportUnavailable('El envío a WhatsApp Cloud API todavía no está habilitado.');
    }
}

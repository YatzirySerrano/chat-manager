<?php

namespace App\Services\Meta;

use App\Models\Message;

/**
 * Default transport while WhatsApp Cloud API sending is not enabled for this
 * installation (config whatsapp.transport): it never pretends a message was
 * delivered.
 */
class UnavailableWhatsAppTransport implements WhatsAppTransport
{
    public function send(Message $message): SendResult
    {
        throw new WhatsAppTransportUnavailable('El envío a WhatsApp Cloud API no está habilitado en esta instalación.');
    }

    public function name(): string
    {
        return 'unavailable';
    }
}

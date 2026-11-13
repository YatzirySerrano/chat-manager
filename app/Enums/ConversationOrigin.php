<?php

namespace App\Enums;

/**
 * Where a conversation comes from: attended on the platform (live),
 * imported from a Whaticket export, or the chat history WhatsApp hands to a
 * number linked by QR (ImportWhatsAppWebHistory). Anything but live is
 * history: read-only, never reopened and outside metrics and queues.
 */
enum ConversationOrigin: string
{
    case Live = 'live';
    case Whaticket = 'whaticket';
    case WhatsappHistory = 'whatsapp_history';

    public function label(): string
    {
        return match ($this) {
            self::Live => 'Atendida en Chat Manager',
            self::Whaticket => 'Importada de Whaticket',
            self::WhatsappHistory => 'Historial de WhatsApp',
        };
    }
}

<?php

namespace App\Enums;

/**
 * How the welcome menu offered to a conversation is doing:
 *
 * - awaiting: the menu was offered and the customer has not chosen yet; the
 *   conversation waits in its provisional queue without being distributed
 *   nor announced;
 * - routed: the customer chose an option and the conversation went to its
 *   department;
 * - answered: the customer chose an option that answers and ends the
 *   menu; the conversation follows the ordinary routing;
 * - fallback: no valid answer after the allowed attempts (or the menu
 *   stopped being available); the conversation stays where the ordinary
 *   routing put it;
 * - cancelled: a person took over before the customer chose (accepted,
 *   assigned, transferred or closed it).
 */
enum ChatbotSessionStatus: string
{
    case Awaiting = 'awaiting';
    case Routed = 'routed';
    case Answered = 'answered';
    case Fallback = 'fallback';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Awaiting => 'Esperando una opción',
            self::Routed => 'Enviada al departamento elegido',
            self::Answered => 'Contestada por el chatbot',
            self::Fallback => 'Sin opción válida',
            self::Cancelled => 'Atendida antes de elegir',
        };
    }
}

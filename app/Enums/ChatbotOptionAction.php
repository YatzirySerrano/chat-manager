<?php

namespace App\Enums;

/**
 * What a chatbot menu option does when the customer chooses it:
 *
 * - submenu: sends its text and its own numbered options (any depth);
 * - reply_stay: answers and keeps the same menu open (informative options:
 *   hours, address, requirements…);
 * - reply: answers and ends the menu; the conversation follows the
 *   ordinary routing and an agent takes it;
 * - transfer: answers (optional) and sends the conversation to a
 *   department, ending the menu.
 */
enum ChatbotOptionAction: string
{
    case Submenu = 'submenu';
    case ReplyStay = 'reply_stay';
    case Reply = 'reply';
    case Transfer = 'transfer';

    public function label(): string
    {
        return match ($this) {
            self::Submenu => 'Abre un submenú',
            self::ReplyStay => 'Contesta y sigue en el menú',
            self::Reply => 'Contesta y termina',
            self::Transfer => 'Pasa a un departamento',
        };
    }

    public function needsDepartment(): bool
    {
        return $this === self::Transfer;
    }

    public function needsReply(): bool
    {
        return $this === self::Reply || $this === self::ReplyStay;
    }
}

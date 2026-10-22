<?php

namespace App\Notifications;

/**
 * How pressing a notification is. Urgent ones ask for an action now (a
 * conversation waits for someone, a connection stopped working); informative
 * ones report something that already happened.
 */
enum NotificationLevel: string
{
    case Urgent = 'urgent';
    case Informative = 'informative';

    public function label(): string
    {
        return match ($this) {
            self::Urgent => 'Requiere acción',
            self::Informative => 'Informativa',
        };
    }
}

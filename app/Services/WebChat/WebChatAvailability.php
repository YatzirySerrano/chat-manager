<?php

namespace App\Services\WebChat;

use App\Enums\WebChatAvailabilityMode;
use App\Models\WebChatWidget;
use App\Services\BusinessHours;

/**
 * Whether the widget shows the team as available. A widget in "business
 * hours" mode follows the schedule of the connection's default department
 * (BusinessHours: department, platform or continuous attention); a
 * connection that is deactivated, deleted or not configured is offline.
 * Offline visitors can still write when the connection operates: their
 * message waits in the queue like any other.
 */
class WebChatAvailability
{
    public function __construct(private BusinessHours $hours) {}

    /**
     * @return array{online: bool, message: string|null}
     */
    public function for(WebChatWidget $widget): array
    {
        $connection = $widget->connection;

        $online = $connection !== null
            && $connection->isOperational()
            && $connection->isConfigured()
            && ($widget->availability_mode !== WebChatAvailabilityMode::BusinessHours
                || $this->hours->isOpenAt($connection->defaultDepartment, now()));

        return [
            'online' => $online,
            'message' => $online ? null : ($widget->offline_text ?? 'En este momento no estamos disponibles. Déjanos tu mensaje y te responderemos lo antes posible.'),
        ];
    }
}

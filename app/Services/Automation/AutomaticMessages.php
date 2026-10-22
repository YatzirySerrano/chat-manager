<?php

namespace App\Services\Automation;

use App\Models\Connection;
use App\Models\Department;
use App\Services\BusinessHours;
use Carbon\CarbonInterface;

/**
 * Decides which automatic message, if any, a conversation gets. Priorities
 * (documented in docs/notificaciones-y-horarios.md):
 *
 * Greeting, when a cycle starts because the customer wrote (new conversation
 * or reopened by an inbound message):
 * 1. outside business hours (BusinessHours of the conversation's
 *    department, holidays included): the away message of the department if
 *    it has one, else the away message of the connection if enabled; when
 *    neither exists nothing is sent (a welcome promising attention would be
 *    misleading outside hours);
 * 2. within hours: the welcome message of the department if it has one,
 *    else the welcome message of the connection if enabled.
 *
 * Farewell, when a conversation closes: the farewell message of the
 * connection if enabled (departments have no farewell of their own).
 *
 * This class only chooses the text; QueueAutomaticMessage enforces the
 * customer service window, the connection state and idempotency.
 */
class AutomaticMessages
{
    public function __construct(private BusinessHours $hours) {}

    public function greeting(Connection $connection, ?Department $department, CarbonInterface $at): ?AutomaticMessagePlan
    {
        if (! $this->hours->isOpenAt($department, $at)) {
            return $this->pick(AutomaticMessageKind::Away, $department?->away_message, $connection->away_enabled ? $connection->away_message : null);
        }

        return $this->pick(AutomaticMessageKind::Welcome, $department?->welcome_message, $connection->welcome_enabled ? $connection->welcome_message : null);
    }

    public function farewell(Connection $connection): ?AutomaticMessagePlan
    {
        return $this->pick(AutomaticMessageKind::Farewell, null, $connection->farewell_enabled ? $connection->farewell_message : null);
    }

    private function pick(AutomaticMessageKind $kind, ?string $departmentText, ?string $connectionText): ?AutomaticMessagePlan
    {
        $departmentText = trim((string) $departmentText);
        $connectionText = trim((string) $connectionText);

        return match (true) {
            $departmentText !== '' => new AutomaticMessagePlan($kind, $departmentText, 'department'),
            $connectionText !== '' => new AutomaticMessagePlan($kind, $connectionText, 'connection'),
            default => null,
        };
    }
}

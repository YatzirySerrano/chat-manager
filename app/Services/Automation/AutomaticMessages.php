<?php

namespace App\Services\Automation;

use App\Models\Connection;
use App\Models\Department;
use App\Models\User;
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
 * Every channel that can send follows these priorities, WhatsApp Web (QR)
 * included since the administrator turns each message on per connection.
 *
 * Actions of the team (SendActionMessages): «Agente asignado», «Transferido
 * a departamento» and «Transferido a otro agente», each one the connection's
 * text when enabled.
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

    /**
     * The connection's message for one of the team's actions, when enabled
     * and written. The agent is the one {{agente}} names.
     */
    public function action(Connection $connection, AutomaticMessageKind $kind, ?User $agent = null): ?AutomaticMessagePlan
    {
        if (! in_array($kind, AutomaticMessageKind::actions(), true) || ! $connection->getAttribute("{$kind->value}_enabled")) {
            return null;
        }

        $text = trim((string) $connection->getAttribute("{$kind->value}_message"));

        return $text === '' ? null : new AutomaticMessagePlan($kind, $text, 'connection', $agent);
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

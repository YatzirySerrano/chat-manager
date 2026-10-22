<?php

namespace App\Broadcasting;

/**
 * Names of the private real-time channels. Every channel is private (the
 * "private-" prefix is added by the broadcaster and the client) and is
 * authorized again, against fresh data, each time a client subscribes.
 *
 * - users.{id}: one user only (their inbox, notifications, access changes);
 * - conversations.{id}: whoever can view that conversation now;
 * - inbox.departments.{id}: the team inbox of a department (members with a
 *   departments scope, users with a global scope);
 * - inbox.queues.{id}: the pending queue of a department, for whoever may
 *   accept from it; it only carries signals about queued conversations, so
 *   agents never learn about colleagues' assigned conversations;
 * - inbox.global: every conversation, for users with a global scope.
 */
final class RealtimeChannels
{
    public const USER = 'users.{userId}';

    public const CONVERSATION = 'conversations.{conversationId}';

    public const DEPARTMENT_INBOX = 'inbox.departments.{departmentId}';

    public const DEPARTMENT_QUEUE = 'inbox.queues.{departmentId}';

    public const GLOBAL_INBOX = 'inbox.global';

    public static function user(int $userId): string
    {
        return "users.{$userId}";
    }

    public static function conversation(int $conversationId): string
    {
        return "conversations.{$conversationId}";
    }

    public static function departmentInbox(int $departmentId): string
    {
        return "inbox.departments.{$departmentId}";
    }

    public static function departmentQueue(int $departmentId): string
    {
        return "inbox.queues.{$departmentId}";
    }

    public static function globalInbox(): string
    {
        return self::GLOBAL_INBOX;
    }

    /**
     * Positive integer identifier from a channel parameter, or null when the
     * parameter is anything else ("1e3", "-1", "01" or " 1" never match).
     */
    public static function identifier(int|string $value): ?int
    {
        $value = (string) $value;

        return preg_match('/^[1-9][0-9]{0,18}$/', $value) === 1 ? (int) $value : null;
    }
}

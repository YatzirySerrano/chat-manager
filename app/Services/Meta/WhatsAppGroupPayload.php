<?php

namespace App\Services\Meta;

use Illuminate\Support\Facades\Log;

/**
 * Recognizes WhatsApp Groups API content in a Cloud API webhook body
 * (object "whatsapp_business_account") so it is never handled as 1:1
 * customer service. This application does not support groups: a group
 * message turned into a conversation with its sender would mix contexts
 * and could get a private reply to something said in a group.
 *
 * Markers documented by Meta:
 * - change fields "group_lifecycle_update", "group_participants_update",
 *   "group_settings_update" and "group_status_update" (any "group_" field is
 *   treated the same, defensively);
 * - a "group_id" key on an incoming message of the "messages" field;
 * - "recipient_type": "group" on a status of the "messages" field.
 *   A "group_id" on a status or "recipient_type": "group" on a message are
 *   not documented and are also honoured, defensively.
 *
 * Group content is removed from the body and only counted: the log keeps
 * the fields, counts and a short hash of each group id, never phone
 * numbers, names or message content.
 */
final class WhatsAppGroupPayload
{
    /**
     * Whether a change field belongs to the Groups API.
     */
    public static function isGroupField(mixed $field): bool
    {
        return is_string($field) && str_starts_with(strtolower($field), 'group_');
    }

    /**
     * Whether an item of value.messages was sent in a group.
     *
     * @param  array<array-key, mixed>  $message
     */
    public static function isGroupMessage(array $message): bool
    {
        return self::hasGroupId($message) || self::isGroupRecipient($message);
    }

    /**
     * Whether an item of value.statuses reports a message sent to a group.
     *
     * @param  array<array-key, mixed>  $status
     */
    public static function isGroupStatus(array $status): bool
    {
        return self::isGroupRecipient($status) || self::hasGroupId($status);
    }

    /**
     * The body without any group content, with what was removed. The body is
     * returned unchanged (same array) when it has no group content.
     *
     * @param  array<array-key, mixed>  $payload
     * @return array{payload: array<array-key, mixed>, ignored: array{fields: list<string>, messages: int, statuses: int, groups: list<string>}}
     */
    public static function strip(array $payload): array
    {
        $ignored = ['fields' => [], 'messages' => 0, 'statuses' => 0, 'groups' => []];

        if (($payload['object'] ?? null) !== 'whatsapp_business_account' || ! is_array($payload['entry'] ?? null)) {
            return ['payload' => $payload, 'ignored' => $ignored];
        }

        $entries = [];
        $changed = false;

        foreach ($payload['entry'] as $entry) {
            if (! is_array($entry) || ! is_array($entry['changes'] ?? null)) {
                $entries[] = $entry;

                continue;
            }

            $changes = [];

            foreach ($entry['changes'] as $change) {
                $field = is_array($change) ? ($change['field'] ?? null) : null;
                $value = is_array($change) && is_array($change['value'] ?? null) ? $change['value'] : null;

                if (self::isGroupField($field)) {
                    $ignored['fields'][] = substr((string) $field, 0, 60);
                    foreach (is_array($value['groups'] ?? null) ? $value['groups'] : [] as $group) {
                        self::remember($ignored, is_array($group) ? ($group['group_id'] ?? null) : null);
                    }
                    $changed = true;

                    continue;
                }

                if ($field !== 'messages' || $value === null) {
                    $changes[] = $change;

                    continue;
                }

                $stripped = self::stripMessages($value, $ignored);

                if ($stripped === $value) {
                    $changes[] = $change;

                    continue;
                }

                $changed = true;

                if (($stripped['messages'] ?? []) !== [] || ($stripped['statuses'] ?? []) !== [] || ! empty($stripped['errors'])) {
                    $changes[] = [...$change, 'value' => $stripped];
                }
            }

            if ($changes !== []) {
                $entries[] = [...$entry, 'changes' => $changes];
            }
        }

        $ignored['groups'] = array_values(array_unique($ignored['groups']));

        return ['payload' => $changed ? [...$payload, 'entry' => $entries] : $payload, 'ignored' => $ignored];
    }

    /**
     * Whether strip() found nothing to remove.
     *
     * @param  array{fields: list<string>, messages: int, statuses: int, groups: list<string>}  $ignored
     */
    public static function nothingIgnored(array $ignored): bool
    {
        return $ignored['fields'] === [] && $ignored['messages'] === 0 && $ignored['statuses'] === 0;
    }

    /**
     * Record ignored group content without personal data.
     *
     * @param  array{fields: list<string>, messages: int, statuses: int, groups: list<string>}  $ignored
     */
    public static function log(int $connectionId, array $ignored): void
    {
        Log::notice('WhatsApp group content ignored: groups are not supported.', [
            'connection_id' => $connectionId,
            'fields' => array_values(array_unique($ignored['fields'])),
            'messages' => $ignored['messages'],
            'statuses' => $ignored['statuses'],
            'group_refs' => array_slice($ignored['groups'], 0, 10),
        ]);
    }

    /**
     * A short, non-reversible reference of a group id for the logs.
     */
    public static function reference(mixed $groupId): ?string
    {
        // Keyed with the application key: the reference correlates log lines
        // but nobody can confirm a known group id from it.
        return is_scalar($groupId) && (string) $groupId !== '' ? substr(hash_hmac('sha256', (string) $groupId, (string) config('app.key')), 0, 12) : null;
    }

    /**
     * @param  array<array-key, mixed>  $value
     * @param  array{fields: list<string>, messages: int, statuses: int, groups: list<string>}  $ignored
     * @return array<array-key, mixed>
     */
    private static function stripMessages(array $value, array &$ignored): array
    {
        $groupSenders = [];
        $kept = [];

        if (is_array($value['messages'] ?? null)) {
            foreach ($value['messages'] as $message) {
                if (is_array($message) && self::isGroupMessage($message)) {
                    $ignored['messages']++;
                    self::remember($ignored, $message['group_id'] ?? null);
                    $groupSenders[] = $message['from'] ?? null;

                    continue;
                }

                $kept[] = $message;
            }

            if (count($kept) !== count($value['messages'])) {
                $value['messages'] = $kept;
            }
        }

        if (is_array($value['statuses'] ?? null)) {
            $statuses = array_values(array_filter($value['statuses'], function (mixed $status) use (&$ignored): bool {
                if (is_array($status) && self::isGroupStatus($status)) {
                    $ignored['statuses']++;
                    self::remember($ignored, $status['recipient_id'] ?? $status['group_id'] ?? null);

                    return false;
                }

                return true;
            }));

            if (count($statuses) !== count($value['statuses'])) {
                $value['statuses'] = $statuses;
            }
        }

        // Profiles of group participants are dropped unless they also sent a 1:1 message here.
        if ($groupSenders !== [] && is_array($value['contacts'] ?? null)) {
            $directSenders = array_map(fn (mixed $message): mixed => is_array($message) ? ($message['from'] ?? null) : null, $kept);
            $value['contacts'] = array_values(array_filter($value['contacts'], fn (mixed $contact): bool => ! is_array($contact)
                || ! in_array($contact['wa_id'] ?? null, $groupSenders, true)
                || in_array($contact['wa_id'] ?? null, $directSenders, true)));
        }

        return $value;
    }

    /**
     * @param  array{fields: list<string>, messages: int, statuses: int, groups: list<string>}  $ignored
     */
    private static function remember(array &$ignored, mixed $groupId): void
    {
        $reference = self::reference($groupId);

        if ($reference !== null) {
            $ignored['groups'][] = $reference;
        }
    }

    /**
     * @param  array<array-key, mixed>  $item
     */
    private static function hasGroupId(array $item): bool
    {
        $groupId = $item['group_id'] ?? null;

        return $groupId !== null && $groupId !== '' && $groupId !== false;
    }

    /**
     * @param  array<array-key, mixed>  $item
     */
    private static function isGroupRecipient(array $item): bool
    {
        return is_string($item['recipient_type'] ?? null) && strtolower($item['recipient_type']) === 'group';
    }
}

<?php

namespace App\Actions\Messaging;

use App\Enums\ConnectionChannel;
use App\Models\Connection;
use App\Services\Meta\WhatsAppGroupPayload;
use Illuminate\Database\Eloquent\Collection;
use Throwable;

/**
 * Decides which connection each change of an authenticated WhatsApp webhook
 * body belongs to, when it was delivered to the URL of one connection but
 * talks about another number or business account. Meta configures a single
 * callback URL per app, so with several numbers under the same app the
 * deliveries of all of them may arrive at one connection's URL (ADR-META-12).
 *
 * The signature of the body was verified with the app secret of the
 * receiving connection, which only proves it comes from THAT Meta app. A
 * change is therefore handed to another connection (a "sibling") only when
 * that connection provably belongs to the same app: both store an app secret
 * and the decrypted values are equal (constant-time comparison; never logged
 * or exposed), and their Meta App IDs do not contradict each other. Anything
 * else is refused and reported, never attached to a connection.
 *
 * - "messages" changes (customer messages, statuses and change-level errors)
 *   belong to the connection of their metadata.phone_number_id, which must
 *   also belong to the business account of the entry;
 * - template changes are applied once, through one connection of the entry's
 *   business account (templates are stored per account);
 * - account and number notices go to every connection of that account, a
 *   quality notice of one number only to the connection of that number.
 *
 * With a single connection, or when every change is about the receiving
 * connection, the body is returned untouched (messages need no query at
 * all; an account-level change costs one lookup of its account).
 *
 * @phpstan-type RefusedChange array{kind: 'phone_number'|'business_account', identifier: string, reason: 'unknown'|'deleted'|'other_app'|'other_account', entry_time: int|null}
 * @phpstan-type SiblingChanges array{connection: Connection, payload: array<array-key, mixed>}
 */
class RouteWebhookChanges
{
    /** Template changes are stored per business account: one handler is enough. */
    private const TEMPLATE_FIELDS = ['message_template_status_update', 'message_template_quality_update'];

    /** @var array<string, Connection|null> */
    private array $numberOwners = [];

    /** @var array<string, Collection<int, Connection>> */
    private array $accountConnections = [];

    /**
     * @param  array<array-key, mixed>  $payload
     * @return array{own: array<array-key, mixed>, siblings: list<SiblingChanges>, refused: list<RefusedChange>}
     */
    public function handle(Connection $receiving, array $payload): array
    {
        $unchanged = ['own' => $payload, 'siblings' => [], 'refused' => []];

        if (($payload['object'] ?? null) !== 'whatsapp_business_account' || ! is_array($payload['entry'] ?? null)) {
            return $unchanged;
        }

        $this->numberOwners = [];
        $this->accountConnections = [];

        $ownPhone = (string) $receiving->phone_number_id;
        $ownAccount = (string) $receiving->business_account_id;
        $ownEntries = [];
        /** @var array<int, Connection> $owners */
        $owners = [];
        /** @var array<int, list<array<array-key, mixed>>> $routed */
        $routed = [];
        $refused = [];
        $touched = false;

        foreach ($payload['entry'] as $entry) {
            if (! is_array($entry) || ! is_array($entry['changes'] ?? null)) {
                $ownEntries[] = $entry;

                continue;
            }

            $entryId = isset($entry['id']) && is_scalar($entry['id']) ? (string) $entry['id'] : null;
            $entryTime = isset($entry['time']) && is_numeric($entry['time']) && (int) $entry['time'] > 0 ? (int) $entry['time'] : null;
            $ownChanges = [];
            /** @var array<int, list<array<array-key, mixed>>> $siblingChanges */
            $siblingChanges = [];

            foreach ($entry['changes'] as $change) {
                $field = is_array($change) ? ($change['field'] ?? null) : null;

                if (! is_array($change) || ! is_string($field) || ! is_array($change['value'] ?? null) || WhatsAppGroupPayload::isGroupField($field)) {
                    $ownChanges[] = $change;

                    continue;
                }

                if ($field === 'messages') {
                    $phone = $change['value']['metadata']['phone_number_id'] ?? null;

                    if (! is_string($phone) || $phone === '' || $phone === $ownPhone) {
                        $ownChanges[] = $change;

                        continue;
                    }

                    // Never the receiving connection's: it leaves its body.
                    $touched = true;
                    $owner = $this->numberOwner($phone);
                    $reason = $this->refusalOf($receiving, $owner, $entryId);

                    if ($owner === null || $reason !== null) {
                        $refused[] = ['kind' => 'phone_number', 'identifier' => $phone, 'reason' => $reason ?? 'unknown', 'entry_time' => $entryTime];

                        continue;
                    }

                    $owners[$owner->id] = $owner;
                    $siblingChanges[$owner->id][] = $change;

                    continue;
                }

                $foreign = $ownAccount !== '' && $entryId !== null && $entryId !== $ownAccount;
                $keepOwn = true;
                $targets = $entryId === null ? [] : $this->siblingsOfAccount($receiving, $entryId);

                if (in_array($field, self::TEMPLATE_FIELDS, true)) {
                    $targets = $foreign ? array_slice($targets, 0, 1) : [];
                } elseif ($field === 'phone_number_quality_update') {
                    [$targets, $keepOwn] = $this->numberNoticeTargets($receiving, $targets, $change['value'], $foreign);
                }

                if ($keepOwn) {
                    // The receiving connection handles (or, for another
                    // account, ignores) it exactly as it always did.
                    $ownChanges[] = $change;
                } else {
                    $touched = true;
                }

                foreach ($targets as $target) {
                    $touched = true;
                    $owners[$target->id] = $target;
                    $siblingChanges[$target->id][] = $change;
                }

                if ($foreign && $targets === []) {
                    $touched = true;
                    $refused[] = ['kind' => 'business_account', 'identifier' => $entryId, 'reason' => $this->accountRefusal($entryId), 'entry_time' => $entryTime];
                }
            }

            if ($ownChanges !== [] || $entry['changes'] === []) {
                $ownEntries[] = [...$entry, 'changes' => $ownChanges];
            }

            foreach ($siblingChanges as $connectionId => $changes) {
                $routed[$connectionId][] = [...$entry, 'changes' => $changes];
            }
        }

        if (! $touched) {
            return $unchanged;
        }

        return [
            'own' => [...$payload, 'entry' => $ownEntries],
            'siblings' => array_values(array_map(fn (Connection $owner): array => [
                'connection' => $owner,
                'payload' => [...$payload, 'entry' => $routed[$owner->id] ?? []],
            ], $owners)),
            'refused' => $refused,
        ];
    }

    /**
     * Whether both connections provably belong to the same Meta app.
     */
    public function sameApp(Connection $receiving, Connection $candidate): bool
    {
        try {
            $ours = (string) $receiving->getAttribute('app_secret');
            $theirs = (string) $candidate->getAttribute('app_secret');
        } catch (Throwable) {
            // A secret that cannot be decrypted proves nothing.
            return false;
        }

        if ($ours === '' || $theirs === '' || ! hash_equals($ours, $theirs)) {
            return false;
        }

        return blank($receiving->meta_app_id) || blank($candidate->meta_app_id)
            || (string) $receiving->meta_app_id === (string) $candidate->meta_app_id;
    }

    /**
     * Why the content of a number cannot be handed to its connection, or null
     * when it can.
     *
     * @return 'unknown'|'deleted'|'other_app'|'other_account'|null
     */
    private function refusalOf(Connection $receiving, ?Connection $owner, ?string $entryId): ?string
    {
        return match (true) {
            $owner === null => 'unknown',
            $owner->isDeleted() => 'deleted',
            ! $this->sameApp($receiving, $owner) => 'other_app',
            filled($owner->business_account_id) && $entryId !== (string) $owner->business_account_id => 'other_account',
            default => null,
        };
    }

    /**
     * The WhatsApp connection registered with the phone number id (a live
     * one before a deleted one, which keeps the id reserved).
     */
    private function numberOwner(string $phoneNumberId): ?Connection
    {
        if (! array_key_exists($phoneNumberId, $this->numberOwners)) {
            $this->numberOwners[$phoneNumberId] = Connection::query()
                ->where('channel', ConnectionChannel::Whatsapp)
                ->where('phone_number_id', $phoneNumberId)
                ->orderByRaw('deleted_at is not null')
                ->orderBy('id')
                ->first();
        }

        return $this->numberOwners[$phoneNumberId];
    }

    /**
     * @return Collection<int, Connection>
     */
    private function connectionsOfAccount(string $businessAccountId): Collection
    {
        return $this->accountConnections[$businessAccountId] ??= Connection::query()
            ->where('channel', ConnectionChannel::Whatsapp)
            ->where('business_account_id', $businessAccountId)
            ->orderBy('id')
            ->get();
    }

    /**
     * Other live connections of the business account that belong to the
     * same app as the receiving one.
     *
     * @return list<Connection>
     */
    private function siblingsOfAccount(Connection $receiving, string $businessAccountId): array
    {
        return array_values($this->connectionsOfAccount($businessAccountId)
            ->filter(fn (Connection $candidate): bool => $candidate->id !== $receiving->id
                && ! $candidate->isDeleted()
                && $this->sameApp($receiving, $candidate))
            ->all());
    }

    /**
     * A quality notice names one number (display_phone_number): it goes to
     * the sibling of that number only, and leaves the receiving connection
     * when it is provably about another one. A notice whose number matches
     * no connection stays where it always was handled.
     *
     * @param  list<Connection>  $siblings
     * @param  array<array-key, mixed>  $value
     * @return array{list<Connection>, bool}
     */
    private function numberNoticeTargets(Connection $receiving, array $siblings, array $value, bool $foreign): array
    {
        $number = self::digits($value['display_phone_number'] ?? null);

        if ($number === '') {
            return [$foreign ? $siblings : [], true];
        }

        $matching = array_values(array_filter($siblings, fn (Connection $sibling): bool => self::digits($sibling->display_phone_number) === $number));
        $own = self::digits($receiving->display_phone_number);

        if ($own === $number) {
            return [[], true];
        }

        if ($matching === []) {
            return [$foreign ? $siblings : [], true];
        }

        return [$matching, $own === ''];
    }

    /**
     * @return 'unknown'|'deleted'|'other_app'
     */
    private function accountRefusal(string $businessAccountId): string
    {
        $connections = $this->connectionsOfAccount($businessAccountId);

        return match (true) {
            $connections->isEmpty() => 'unknown',
            $connections->every(fn (Connection $connection): bool => $connection->isDeleted()) => 'deleted',
            default => 'other_app',
        };
    }

    private static function digits(mixed $value): string
    {
        return is_scalar($value) ? (string) preg_replace('/\D+/', '', (string) $value) : '';
    }
}

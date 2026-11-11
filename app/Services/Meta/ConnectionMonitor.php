<?php

namespace App\Services\Meta;

use App\Events\Connections\ConnectionHealthChanged;
use App\Models\Connection;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Records the evidence a connection's health is derived from (webhook
 * verification and deliveries, inbound and outbound traffic, runtime errors
 * reported by Meta) and announces ConnectionHealthChanged when the derived
 * health changes.
 *
 * Every runtime error is recorded with its class
 * (`provider_signals['platform.error']`), because what proves it is over
 * depends on what it was about (ADR-META-13): a verification of the
 * credentials only attests credential problems; a quality or account
 * restriction, a webhook-level error or a rejected send stay current until
 * the channel works again or Meta says the restriction was lifted
 * (restrictionLifted()).
 *
 * Traffic timestamps of a busy number are only written when they moved by
 * more than TOUCH_INTERVAL_SECONDS, so thousands of messages never contend
 * on the connection row.
 */
class ConnectionMonitor
{
    private const TOUCH_INTERVAL_SECONDS = 60;

    /** Prefix of the notes this platform keeps beside Meta's own notices. */
    public const PLATFORM_SIGNAL_PREFIX = 'platform.';

    /** Content of numbers or accounts that could not be attributed to a connection. */
    public const UNATTRIBUTED_SIGNAL = 'platform.unattributed';

    /** Outcome of the last automatic check against Meta. */
    public const CHECK_SIGNAL = 'platform.check';

    /**
     * Notes the application keeps under its own names, without the prefix.
     * A webhook field may never be stored under any of them.
     *
     * @var list<string>
     */
    public const RESERVED_SIGNALS = ['meta_authorization', 'token_refresh'];

    /** Class and source of the error in `last_error_at` (see ConnectionHealth). */
    public const ERROR_SIGNAL = 'platform.error';

    /** About the credentials: a later verification accepted by Meta attests it is over. */
    public const ERROR_CREDENTIALS = 'credentials';

    /** Meta lowered the quality of the number (phone_number_quality_update FLAGGED). */
    public const ERROR_QUALITY = 'quality';

    /** Meta restricted, disabled or deleted the account. */
    public const ERROR_ACCOUNT = 'account';

    /** Meta blocked the messaging of the page or account (policy enforcement). */
    public const ERROR_POLICY = 'policy';

    /** Meta rejected a send for a reason that concerns the whole connection. */
    public const ERROR_SEND = 'send';

    /** Any other error reported by Meta (webhook-level errors). */
    public const ERROR_PROVIDER = 'provider';

    /** Unattributed numbers or accounts remembered per connection. */
    private const UNATTRIBUTED_KEPT = 10;

    public function webhookVerified(Connection $connection): void
    {
        $this->change($connection, ['webhook_verified_at' => now()]);
    }

    /**
     * An authenticated webhook delivery arrived (cheap, unlocked update).
     */
    public function webhookReceived(Connection $connection): void
    {
        $this->touch($connection, 'last_webhook_at', CarbonImmutable::now());
    }

    public function inboundReceived(Connection $connection, CarbonImmutable $at): void
    {
        $this->touch($connection, 'last_inbound_at', $at->greaterThan(now()) ? CarbonImmutable::now() : $at, activity: true);
    }

    /**
     * Meta accepted an outbound message (returned its id).
     */
    public function outboundAccepted(Connection $connection): void
    {
        $this->touch($connection, 'last_outbound_at', CarbonImmutable::now(), activity: true);
    }

    /**
     * A runtime error that concerns the whole connection (not one message).
     *
     * `$source` says what it is about (ERROR_*): only ERROR_CREDENTIALS is
     * resolved by a later verification of the credentials. `$reference`
     * identifies the notice it comes from (the time of the webhook entry):
     * the same notice applied again (a retried delivery) changes nothing,
     * so it never looks like a new error after the channel recovered.
     */
    public function failure(Connection $connection, string $message, string $source = self::ERROR_PROVIDER, ?int $reference = null): void
    {
        $message = Str::limit($message, 500, '');

        $this->change($connection, function (Connection $current) use ($message, $source, $reference): array {
            $known = $current->provider_signals[self::ERROR_SIGNAL]['value'] ?? null;

            if ($reference !== null && is_array($known) && ($known['source'] ?? null) === $source
                && ($known['reference'] ?? null) === $reference && $current->last_error_message === $message) {
                return [];
            }

            $now = now()->startOfSecond();

            return [
                'last_error_at' => $now,
                'last_error_message' => $message,
                'provider_signals' => self::withError($current, $source, $now, $reference),
            ];
        });
    }

    /**
     * Meta said that a restriction it had reported is over (the quality is
     * no longer flagged, the account was reinstated, the page unblocked).
     * It resolves the current error only when that error came from the same
     * source; anything else is left as it is.
     */
    public function restrictionLifted(Connection $connection, string $source): void
    {
        $this->change($connection, function (Connection $current) use ($source): array {
            $signals = $current->provider_signals ?? [];
            $known = $signals[self::ERROR_SIGNAL]['value'] ?? null;

            if (! is_array($known) || ($known['source'] ?? null) !== $source || isset($known['lifted_at'])
                || ! self::describesCurrentError($current, $known)) {
                return [];
            }

            $signals[self::ERROR_SIGNAL]['value']['lifted_at'] = now()->toIso8601String();

            return ['provider_signals' => $signals];
        });
    }

    /**
     * Whether the error in `last_error_at` is one that a verification of
     * the credentials accepted by Meta proves to be over. Errors recorded
     * without their class (before it was kept, or written by a flow that
     * only concerns credentials or the subscription) keep that meaning.
     */
    public static function verificationAttests(Connection $connection): bool
    {
        $known = $connection->provider_signals[self::ERROR_SIGNAL]['value'] ?? null;

        return ! is_array($known) || ! self::describesCurrentError($connection, $known)
            || ($known['source'] ?? null) === self::ERROR_CREDENTIALS;
    }

    /**
     * When Meta said the restriction behind the current error was lifted.
     */
    public static function restrictionLiftedAt(Connection $connection): ?CarbonImmutable
    {
        $known = $connection->provider_signals[self::ERROR_SIGNAL]['value'] ?? null;

        return is_array($known) && is_string($known['lifted_at'] ?? null) && self::describesCurrentError($connection, $known)
            ? CarbonImmutable::parse($known['lifted_at'])
            : null;
    }

    /**
     * Meta rejected the stored token at runtime: the credentials are recorded
     * as invalid until an administrator replaces and verifies them.
     */
    public function credentialsRejected(Connection $connection, string $message): void
    {
        $this->change($connection, function (Connection $current) use ($message): array {
            $now = now()->startOfSecond();

            return [
                'credentials_verified_at' => null,
                'credentials_error' => Str::limit($message, 500, ''),
                'last_error_at' => $now,
                'last_error_message' => Str::limit($message, 500, ''),
                'provider_signals' => self::withError($current, self::ERROR_CREDENTIALS, $now, null),
            ];
        });
    }

    /**
     * Meta just accepted the stored credentials (automatic check of a
     * Messenger or Instagram account) and answered whether the app is
     * subscribed to its webhooks.
     */
    public function credentialsConfirmed(Connection $connection, ?bool $subscribed = null): void
    {
        $this->change($connection, [
            'credentials_verified_at' => now(),
            'credentials_error' => null,
            ...($subscribed === null ? [] : ['app_subscribed' => $subscribed, 'subscription_checked_at' => now()]),
        ]);
    }

    /**
     * Outcome of the last automatic check, kept so the interface can tell
     * "could not be checked" (Meta unreachable or throttling) from a real
     * credential failure. Never changes the health.
     *
     * @param  'verified'|'rejected'|'unreachable'|'rate_limited'  $outcome
     */
    public function checked(Connection $connection, string $outcome): void
    {
        $this->signal($connection, self::CHECK_SIGNAL, ['outcome' => $outcome]);
    }

    /**
     * An authenticated delivery brought content of another number or
     * business account that could not be handed to a connection. Only its
     * identifier and the reason are kept (never message content), bounded
     * and at most once a minute per identifier.
     *
     * @param  'phone_number'|'business_account'  $kind
     * @param  'unknown'|'deleted'|'other_app'|'other_account'  $reason
     */
    public function unattributed(Connection $connection, string $kind, string $identifier, string $reason): void
    {
        $identifier = Str::limit((string) preg_replace('/[^0-9A-Za-z_\-]/', '', $identifier), 40, '');
        $key = "{$kind}:{$identifier}";
        $known = $connection->provider_signals[self::UNATTRIBUTED_SIGNAL]['value'][$key] ?? null;

        if (is_array($known) && ($known['reason'] ?? null) === $reason && is_string($known['last_at'] ?? null)
            && CarbonImmutable::parse($known['last_at'])->greaterThan(now()->subSeconds(self::TOUCH_INTERVAL_SECONDS))) {
            return;
        }

        DB::transaction(function () use ($connection, $kind, $identifier, $reason, $key): void {
            $current = Connection::query()->whereKey($connection->id)->lockForUpdate()->firstOrFail();
            $signals = $current->provider_signals ?? [];
            $items = is_array($signals[self::UNATTRIBUTED_SIGNAL]['value'] ?? null) ? $signals[self::UNATTRIBUTED_SIGNAL]['value'] : [];
            $now = now()->toIso8601String();

            $items[$key] = [
                'kind' => $kind,
                'identifier' => $identifier,
                'reason' => $reason,
                'first_at' => is_string($items[$key]['first_at'] ?? null) ? $items[$key]['first_at'] : $now,
                'last_at' => $now,
            ];

            uasort($items, fn (array $a, array $b): int => strcmp((string) ($b['last_at'] ?? ''), (string) ($a['last_at'] ?? '')));

            $signals[self::UNATTRIBUTED_SIGNAL] = ['at' => $now, 'value' => array_slice($items, 0, self::UNATTRIBUTED_KEPT, true)];
            $current->forceFill(['provider_signals' => $signals])->save();
            $connection->setRawAttributes($current->getAttributes(), true);
        });
    }

    /**
     * Keep the last notice of a kind reported by Meta (account_update,
     * phone_number_quality_update...).
     *
     * @param  array<array-key, mixed>  $value
     */
    public function signal(Connection $connection, string $field, array $value): void
    {
        DB::transaction(function () use ($connection, $field, $value): void {
            $current = Connection::query()->whereKey($connection->id)->lockForUpdate()->firstOrFail();
            $signals = $current->provider_signals ?? [];
            $signals[$field] = ['at' => now()->toIso8601String(), 'value' => $value];
            $current->forceFill(['provider_signals' => $signals])->save();
            $connection->setRawAttributes($current->getAttributes(), true);
        });
    }

    /**
     * The note of the error being recorded, tied to its instant: an error
     * written later by another flow (without note) is never read with the
     * class of this one.
     *
     * @return array<string, mixed>
     */
    private static function withError(Connection $current, string $source, CarbonInterface $at, ?int $reference): array
    {
        return [
            ...($current->provider_signals ?? []),
            self::ERROR_SIGNAL => ['at' => $at->toIso8601String(), 'value' => array_filter([
                'source' => $source,
                'error_at' => $at->getTimestamp(),
                'reference' => $reference,
            ], fn (mixed $value): bool => $value !== null)],
        ];
    }

    /**
     * @param  array<array-key, mixed>  $known
     */
    private static function describesCurrentError(Connection $connection, array $known): bool
    {
        return $connection->last_error_at !== null && ($known['error_at'] ?? null) === $connection->last_error_at->getTimestamp();
    }

    /**
     * Apply attributes under the connection lock and announce a health
     * change. A closure receives the locked row and returns the attributes
     * (none: nothing to change).
     *
     * @param  array<string, mixed>|Closure(Connection): array<string, mixed>  $attributes
     */
    private function change(Connection $connection, array|Closure $attributes): void
    {
        DB::transaction(function () use ($connection, $attributes): void {
            $current = Connection::query()->whereKey($connection->id)->lockForUpdate()->firstOrFail();
            $previous = $current->health();
            $attributes = $attributes instanceof Closure ? $attributes($current) : $attributes;

            $current->forceFill($attributes)->save();
            $health = $current->health();

            if ($health !== $previous) {
                ConnectionHealthChanged::dispatch($current->id, $health->value, $previous->value, $current->last_error_message);
            }

            $connection->setRawAttributes($current->getAttributes(), true);
        });
    }

    private function touch(Connection $connection, string $column, CarbonImmutable $at, bool $activity = false): void
    {
        $current = $connection->getAttribute($column);

        if ($current instanceof \DateTimeInterface && $at->getTimestamp() - $current->getTimestamp() < self::TOUCH_INTERVAL_SECONDS) {
            return;
        }

        $previous = $connection->health();
        $values = [$column => $at];

        if ($activity) {
            $values['last_activity_at'] = $at;
        }

        $updated = Connection::query()
            ->whereKey($connection->id)
            ->where(fn ($query) => $query->whereNull($column)->orWhere($column, '<', $at->subSeconds(self::TOUCH_INTERVAL_SECONDS)))
            ->update($values);

        if ($updated === 0) {
            return;
        }

        $connection->forceFill($values)->syncOriginalAttributes(array_keys($values));
        $health = $connection->health();

        if ($health !== $previous) {
            ConnectionHealthChanged::dispatch($connection->id, $health->value, $previous->value, null);
        }
    }
}

<?php

namespace App\Services\Meta;

use App\Events\Connections\ConnectionHealthChanged;
use App\Models\Connection;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Records the evidence a connection's health is derived from (webhook
 * verification and deliveries, inbound and outbound traffic, runtime errors
 * reported by Meta) and announces ConnectionHealthChanged when the derived
 * health changes.
 *
 * Traffic timestamps of a busy number are only written when they moved by
 * more than TOUCH_INTERVAL_SECONDS, so thousands of messages never contend
 * on the connection row.
 */
class ConnectionMonitor
{
    private const TOUCH_INTERVAL_SECONDS = 60;

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
     */
    public function failure(Connection $connection, string $message): void
    {
        $this->change($connection, [
            'last_error_at' => now(),
            'last_error_message' => Str::limit($message, 500, ''),
        ]);
    }

    /**
     * Meta rejected the stored token at runtime: the credentials are recorded
     * as invalid until an administrator replaces and verifies them.
     */
    public function credentialsRejected(Connection $connection, string $message): void
    {
        $this->change($connection, [
            'credentials_verified_at' => null,
            'credentials_error' => Str::limit($message, 500, ''),
            'last_error_at' => now(),
            'last_error_message' => Str::limit($message, 500, ''),
        ]);
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
     * Apply attributes under the connection lock and announce a health change.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function change(Connection $connection, array $attributes): void
    {
        DB::transaction(function () use ($connection, $attributes): void {
            $current = Connection::query()->whereKey($connection->id)->lockForUpdate()->firstOrFail();
            $previous = $current->health();

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

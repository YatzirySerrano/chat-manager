<?php

namespace App\Services\Meta;

use App\Enums\ConnectionChannel;
use App\Enums\ConnectionHealth;
use App\Models\Connection;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * What is known about a connection right now, for the connections list and
 * the detail of each one, built only from recorded evidence and with the
 * part that applies to its channel (a web chat has no credentials, only
 * WhatsApp has a webhook of its own).
 *
 * It separates RECENT evidence (Meta answered a check, or traffic was seen,
 * within whatsapp.monitoring.fresh_minutes) from HISTORY (it worked at some
 * point): old activity never reads as "connected now". A quiet period is
 * normal and produces no warning. It never includes secrets, tokens or
 * message content.
 *
 * @phpstan-type MonitoringWarning array{code: string, message: string, at: string|null}
 */
class ConnectionMonitoring
{
    /**
     * @return array<string, mixed>
     */
    public static function for(Connection $connection): array
    {
        $usesMeta = ! $connection->isWebChat();
        $attempt = $connection->provider_signals[ConnectionMonitor::CHECK_SIGNAL] ?? null;
        $problem = in_array($connection->configurationHealth(), [ConnectionHealth::Error, ConnectionHealth::CredentialsInvalid], true);

        return [
            'configuration' => [
                'complete' => $connection->isConfigured(),
                'label' => $connection->isConfigured() ? 'Datos completos' : 'Faltan datos',
            ],
            'credentials' => ! $usesMeta ? null : [
                'state' => match (true) {
                    ! $connection->isConfigured() => 'missing',
                    filled($connection->credentials_error) => 'invalid',
                    $connection->credentials_verified_at !== null => 'verified',
                    default => 'unverified',
                },
                'checked_at' => $connection->credentials_verified_at?->toIso8601String(),
                'fresh' => self::isFresh($connection->credentials_verified_at),
                'error' => $connection->credentials_error,
                // Only an active, configured connection is checked by the scheduler.
                'automatic' => $connection->isOperational() && $connection->hasRequiredCredentials(),
                'last_attempt' => is_array($attempt) && is_string($attempt['at'] ?? null) && is_string($attempt['value']['outcome'] ?? null)
                    ? ['at' => $attempt['at'], 'outcome' => $attempt['value']['outcome']]
                    : null,
            ],
            'webhook' => $connection->channel !== ConnectionChannel::Whatsapp ? null : [
                'verified_at' => $connection->webhook_verified_at?->toIso8601String(),
                'last_received_at' => $connection->last_webhook_at?->toIso8601String(),
                'fresh' => self::isFresh($connection->last_webhook_at),
            ],
            'subscription' => ! $usesMeta ? null : [
                'state' => match ($connection->app_subscribed) {
                    true => 'subscribed',
                    false => 'not_subscribed',
                    default => 'unknown',
                },
                'checked_at' => $connection->subscription_checked_at?->toIso8601String(),
                'fresh' => self::isFresh($connection->subscription_checked_at),
            ],
            'last_inbound_at' => $connection->last_inbound_at?->toIso8601String(),
            'last_outbound_at' => $connection->last_outbound_at?->toIso8601String(),
            'last_error' => $connection->last_error_at === null ? null : [
                'at' => $connection->last_error_at->toIso8601String(),
                'message' => $connection->last_error_message,
                // Whether nothing has worked since (it is the current state) or it is history.
                'current' => $connection->configurationHealth() === ConnectionHealth::Error,
            ],
            'evidence' => self::evidence($connection, $problem),
            'warnings' => self::warnings($connection),
            'fresh_minutes' => self::freshMinutes(),
        ];
    }

    /**
     * Operational warnings an administrator should look at. Silence (no
     * traffic) is never one of them.
     *
     * @return list<MonitoringWarning>
     */
    public static function warnings(Connection $connection): array
    {
        $warnings = [];

        if (! $connection->isWebChat() && $connection->app_subscribed === false) {
            $warnings[] = [
                'code' => 'not_subscribed',
                'message' => $connection->channel === ConnectionChannel::Whatsapp
                    ? 'Meta respondió que la app no está suscrita a los avisos de esta cuenta de WhatsApp Business: los mensajes de los clientes no llegarán hasta activar la recepción.'
                    : 'Meta respondió que la app no está suscrita a los avisos de esta cuenta: los mensajes no llegarán. Vuelve a autorizar la cuenta.',
                'at' => $connection->subscription_checked_at?->toIso8601String(),
            ];
        }

        if ($connection->channel === ConnectionChannel::Whatsapp && filled($connection->phone_status) && $connection->phone_status !== 'CONNECTED' && blank($connection->credentials_error)) {
            $warnings[] = [
                'code' => 'phone_not_connected',
                'message' => "Meta reporta el número con estado {$connection->phone_status}. Debe estar CONNECTED para enviar y recibir mensajes.",
                'at' => $connection->credentials_verified_at?->toIso8601String(),
            ];
        }

        if ($connection->token_expires_at !== null && filled($connection->access_token)) {
            if ($connection->token_expires_at->isPast()) {
                $warnings[] = ['code' => 'token_expired', 'message' => 'La autorización de Meta venció. Vuelve a autorizar la cuenta para seguir recibiendo y enviando mensajes.', 'at' => $connection->token_expires_at->toIso8601String()];
            } elseif ($connection->token_expires_at->lessThan(now()->addDays(7))) {
                $warnings[] = ['code' => 'token_expiring', 'message' => 'La autorización de Meta vence en menos de 7 días. Se renueva sola; si no ocurre, vuelve a autorizar la cuenta.', 'at' => $connection->token_expires_at->toIso8601String()];
            }
        }

        return [...$warnings, ...self::unattributed($connection)];
    }

    /**
     * Content of other numbers or accounts that reached this connection's
     * webhook and could not be handed to a connection (RouteWebhookChanges),
     * while it keeps arriving (whatsapp.monitoring.warning_days).
     *
     * @return list<MonitoringWarning>
     */
    private static function unattributed(Connection $connection): array
    {
        $items = $connection->provider_signals[ConnectionMonitor::UNATTRIBUTED_SIGNAL]['value'] ?? null;
        $since = now()->subDays(max(1, (int) config('whatsapp.monitoring.warning_days', 7)));
        $warnings = [];

        foreach (is_array($items) ? $items : [] as $item) {
            if (! is_array($item) || ! is_string($item['last_at'] ?? null) || ! is_string($item['identifier'] ?? null) || Carbon::parse($item['last_at'])->lessThan($since)) {
                continue;
            }

            $id = $item['identifier'];
            $account = ($item['kind'] ?? null) === 'business_account';

            $warnings[] = [
                'code' => 'unattributed_'.(is_string($item['reason'] ?? null) ? $item['reason'] : 'unknown'),
                'message' => match (true) {
                    $account && ($item['reason'] ?? null) === 'other_app' => "Llegaron avisos de otra cuenta de WhatsApp Business (ID {$id}) cuya conexión usa un App Secret distinto. No se aplicaron: revisa en Meta que cada app envíe sus avisos a la URL de su propia conexión.",
                    $account && ($item['reason'] ?? null) === 'deleted' => "Llegaron avisos de la cuenta de WhatsApp Business (ID {$id}) de una conexión eliminada. No se aplicaron.",
                    $account => "Llegaron avisos de otra cuenta de WhatsApp Business (ID {$id}) que no está registrada aquí. No se aplicaron.",
                    ($item['reason'] ?? null) === 'other_app' => "Llegaron eventos de otro número (ID {$id}) registrado en una conexión con un App Secret distinto, así que no se puede comprobar que sean de la misma app de Meta. No se guardaron: si ambos números son de la misma app, guarda el mismo App Secret en las dos conexiones.",
                    ($item['reason'] ?? null) === 'deleted' => "Llegaron eventos de otro número (ID {$id}) cuya conexión fue eliminada. No se guardaron: restaura esa conexión o retira el número de la app en Meta.",
                    ($item['reason'] ?? null) === 'other_account' => "Llegaron eventos de otro número (ID {$id}) bajo una cuenta de WhatsApp Business distinta a la guardada en su conexión. No se guardaron: revisa el WhatsApp Business Account ID de esa conexión.",
                    default => "Llegaron eventos de otro número (ID {$id}) que no está registrado aquí. No se guardaron: si ese número debe atenderse en la plataforma, crea su conexión con el mismo App Secret.",
                },
                'at' => $item['last_at'],
            ];
        }

        return $warnings;
    }

    /**
     * The headline: is there recent evidence that the connection works, only
     * history, or nothing yet.
     *
     * @return array{level: 'problem'|'recent'|'historical'|'none', source: 'check'|'traffic'|null, at: string|null}
     */
    private static function evidence(Connection $connection, bool $problem): array
    {
        $check = $connection->isWebChat() || filled($connection->credentials_error) ? null : $connection->credentials_verified_at;
        $traffic = self::latest($connection->last_inbound_at, $connection->last_outbound_at, $connection->last_webhook_at);
        $latest = self::latest($check, $traffic);
        $source = $latest === null ? null : ($check !== null && $latest->equalTo($check) ? 'check' : 'traffic');

        return [
            'level' => match (true) {
                $problem => 'problem',
                $latest === null => 'none',
                self::isFresh($latest) => 'recent',
                default => 'historical',
            },
            'source' => $source,
            'at' => $latest?->toIso8601String(),
        ];
    }

    private static function isFresh(?CarbonInterface $moment): bool
    {
        return $moment !== null && $moment->greaterThanOrEqualTo(now()->subMinutes(self::freshMinutes()));
    }

    private static function freshMinutes(): int
    {
        return max(5, (int) config('whatsapp.monitoring.fresh_minutes', 90));
    }

    private static function latest(?CarbonInterface ...$moments): ?CarbonInterface
    {
        $latest = null;

        foreach ($moments as $moment) {
            if ($moment !== null && ($latest === null || $moment->greaterThan($latest))) {
                $latest = $moment;
            }
        }

        return $latest;
    }
}

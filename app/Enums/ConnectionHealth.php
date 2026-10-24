<?php

namespace App\Enums;

use App\Models\Connection;
use Carbon\CarbonInterface;

/**
 * Health of a connection, derived from recorded evidence only. Saving the
 * configuration never makes a connection "operational": that requires an
 * inbound message AND an outbound message confirmed by the provider.
 *
 * Precedence (first match wins): inactive overlay, not configured, invalid
 * credentials, error newer than the last success, operational (only with
 * verified credentials), receiving, verified credentials, configured. A web
 * chat connection skips the credential states (see resolveWebChat).
 */
enum ConnectionHealth: string
{
    /** Turned off by an administrator; nothing is received or sent. */
    case Inactive = 'inactive';

    /** Identifiers or access token are missing. */
    case NotConfigured = 'not_configured';

    /** Saved, but the credentials were never verified against the provider. */
    case Configured = 'configured';

    /** The last verification was rejected by the provider. */
    case CredentialsInvalid = 'credentials_invalid';

    /** The provider accepted the credentials; no webhook evidence yet. */
    case CredentialsVerified = 'credentials_verified';

    /** The webhook was verified or an inbound message was received. */
    case Receiving = 'receiving';

    /** Inbound and outbound messages have both been confirmed. */
    case Operational = 'operational';

    /** The most recent event is an error newer than the last success. */
    case Error = 'error';

    /**
     * Health including the inactive overlay.
     */
    public static function resolve(Connection $connection): self
    {
        return $connection->is_active ? self::resolveConfiguration($connection) : self::Inactive;
    }

    /**
     * Health of the configuration and integration regardless of `is_active`.
     */
    public static function resolveConfiguration(Connection $connection): self
    {
        if ($connection->isWebChat()) {
            return self::resolveWebChat($connection);
        }

        if (! $connection->hasRequiredCredentials()) {
            return self::NotConfigured;
        }

        if (filled($connection->credentials_error)) {
            return self::CredentialsInvalid;
        }

        $lastSuccess = self::latest(
            $connection->credentials_verified_at,
            $connection->webhook_verified_at,
            $connection->last_inbound_at,
            $connection->last_outbound_at,
        );

        if ($connection->last_error_at !== null && ($lastSuccess === null || $connection->last_error_at->greaterThan($lastSuccess))) {
            return self::Error;
        }

        // Past traffic does not vouch for credentials that are pending
        // verification (changed token, restored connection).
        if ($connection->credentials_verified_at !== null && $connection->last_inbound_at !== null && $connection->last_outbound_at !== null) {
            return self::Operational;
        }

        if ($connection->webhook_verified_at !== null || $connection->last_inbound_at !== null) {
            return self::Receiving;
        }

        if ($connection->credentials_verified_at !== null) {
            return self::CredentialsVerified;
        }

        return self::Configured;
    }

    /**
     * A web chat connection has no provider credentials: it is configured
     * with its widget and authorized origins, receiving once a visitor wrote
     * and operational once a reply was also accepted.
     */
    private static function resolveWebChat(Connection $connection): self
    {
        if (! $connection->isConfigured()) {
            return self::NotConfigured;
        }

        $lastSuccess = self::latest($connection->last_inbound_at, $connection->last_outbound_at);

        if ($connection->last_error_at !== null && ($lastSuccess === null || $connection->last_error_at->greaterThan($lastSuccess))) {
            return self::Error;
        }

        if ($connection->last_inbound_at !== null && $connection->last_outbound_at !== null) {
            return self::Operational;
        }

        if ($connection->last_inbound_at !== null) {
            return self::Receiving;
        }

        return self::Configured;
    }

    public function label(): string
    {
        return match ($this) {
            self::Inactive => 'Inactiva',
            self::NotConfigured => 'Sin configurar',
            self::Configured => 'Configurada sin verificar',
            self::CredentialsInvalid => 'Credenciales inválidas',
            self::CredentialsVerified => 'Credenciales verificadas',
            self::Receiving => 'Recibiendo mensajes',
            self::Operational => 'Operativa',
            self::Error => 'Con error',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Inactive => 'La conexión está desactivada y no recibe ni envía mensajes.',
            self::NotConfigured => 'Faltan identificadores o el token de acceso.',
            self::Configured => 'Los datos están guardados, pero las credenciales aún no se han verificado con Meta.',
            self::CredentialsInvalid => 'Meta rechazó las credenciales en la última verificación.',
            self::CredentialsVerified => 'Meta aceptó las credenciales; aún no hay evidencia de recepción por webhook.',
            self::Receiving => 'El webhook fue verificado o ya se recibió al menos un mensaje.',
            self::Operational => 'Credenciales verificadas y se confirmó la recepción y el envío de mensajes.',
            self::Error => 'El evento más reciente fue un error posterior al último éxito.',
        };
    }

    /**
     * Visual severity used by the interface.
     */
    public function tone(): string
    {
        return match ($this) {
            self::Operational => 'success',
            self::Receiving, self::CredentialsVerified => 'primary',
            self::Configured, self::NotConfigured => 'warning',
            self::CredentialsInvalid, self::Error => 'danger',
            self::Inactive => 'neutral',
        };
    }

    /**
     * Whether the provider has actually delivered traffic to the platform.
     * Saved or verified credentials alone are not evidence of a working channel.
     */
    public function hasConfirmedTraffic(): bool
    {
        return in_array($this, [self::Receiving, self::Operational], true);
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(fn (self $health): array => ['value' => $health->value, 'label' => $health->label()], self::cases());
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

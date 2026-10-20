<?php

namespace App\Actions\Connections;

/**
 * Write-only credential fields of a connection. They are accepted from the
 * administrator, stored encrypted and never sent back to the browser or
 * written to the audit log (only the fact that one was replaced).
 */
final class ConnectionSecrets
{
    /**
     * @var array<string, string>
     */
    public const FIELDS = [
        'access_token' => 'Token de acceso',
        'app_secret' => 'App secret',
        'webhook_verify_token' => 'Token de verificación del webhook',
    ];

    public static function label(string $field): string
    {
        return self::FIELDS[$field] ?? $field;
    }
}

<?php

namespace App\Services\Ai;

use RuntimeException;
use Throwable;

/**
 * A provider could not answer. The type is what the administrator sees
 * («Probar conexión», last error) and what the log records; the provider's
 * own message is never shown nor logged, as it may echo the request.
 */
class AiProviderException extends RuntimeException
{
    public const INVALID_KEY = 'invalid_key';

    public const PERMISSION = 'permission';

    public const BILLING = 'billing';

    public const RATE_LIMIT = 'rate_limit';

    public const OVERLOADED = 'overloaded';

    public const TIMEOUT = 'timeout';

    public const CONNECTION = 'connection';

    public const MODEL_NOT_FOUND = 'model_not_found';

    public const BAD_REQUEST = 'bad_request';

    public const UNKNOWN = 'unknown';

    public function __construct(public readonly string $type, public readonly ?int $status = null, ?Throwable $previous = null)
    {
        parent::__construct(self::describe($type), 0, $previous);
    }

    /**
     * What the administrator reads, in one line.
     */
    public static function describe(?string $type): string
    {
        return match ($type) {
            self::INVALID_KEY => 'La clave de API no es válida o fue revocada.',
            self::PERMISSION => 'La clave no tiene permiso para usar este modelo.',
            self::BILLING => 'La cuenta no tiene saldo o crédito disponible.',
            self::RATE_LIMIT => 'Se alcanzó el límite de uso de la cuenta. Intenta en unos minutos.',
            self::OVERLOADED => 'El servicio está saturado. Intenta en unos minutos.',
            self::TIMEOUT => 'El servicio tardó demasiado en responder.',
            self::CONNECTION => 'No se pudo conectar con el servicio.',
            self::MODEL_NOT_FOUND => 'El modelo no existe o la cuenta no tiene acceso a él.',
            self::BAD_REQUEST => 'El servicio rechazó la solicitud.',
            default => 'Error desconocido del servicio.',
        };
    }
}

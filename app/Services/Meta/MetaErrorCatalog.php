<?php

namespace App\Services\Meta;

/**
 * Spanish explanations of the WhatsApp Cloud API and Graph API error codes an
 * agent or an administrator can act upon. Meta's own (English) description
 * is never shown to users: the code is kept in messages.error_code (meta_N)
 * and in the audit entry, and the raw detail only in the application log.
 */
final class MetaErrorCatalog
{
    /**
     * Throttling codes: Meta did not process the request; it may be repeated later.
     *
     * @var list<int>
     */
    public const THROTTLING = [4, 17, 32, 613, 80007, 130429, 131056];

    /**
     * Temporary failures reported by Meta itself (the request was not processed).
     *
     * @var list<int>
     */
    public const TEMPORARY = [1, 2, 131000, 131016];

    /**
     * Plain explanations: what happened and, when it helps, what to do.
     * Sources: WhatsApp Cloud API error codes and Graph API error handling.
     *
     * @var array<int, string>
     */
    private const MESSAGES = [
        0 => 'El token de acceso de la conexión dejó de ser válido. Un administrador debe generar uno nuevo en Meta y guardarlo en la conexión.',
        1 => 'Meta tuvo una falla temporal y no procesó la solicitud. Se puede intentar de nuevo en unos minutos.',
        2 => 'El servicio de Meta no está disponible temporalmente. Se puede intentar de nuevo en unos minutos.',
        3 => 'La app de Meta no tiene habilitada esta función. Un administrador debe revisar la configuración de la app.',
        4 => 'La app de Meta alcanzó su límite de solicitudes. Se reintentará más tarde.',
        10 => 'La conexión no tiene permiso para realizar esta acción en Meta. Un administrador debe revisar los permisos del token.',
        17 => 'Se alcanzó el límite de solicitudes de Meta para esta cuenta. Se reintentará más tarde.',
        32 => 'Se alcanzó el límite de solicitudes de Meta para esta página o cuenta. Se reintentará más tarde.',
        100 => 'Meta rechazó la solicitud porque un dato no es válido o no existe.',
        190 => 'El token de acceso de la conexión expiró o fue revocado. Un administrador debe generar uno nuevo en Meta y guardarlo en la conexión.',
        200 => 'La conexión no tiene permiso para realizar esta acción en Meta. Un administrador debe revisar los permisos del token.',
        368 => 'Meta bloqueó temporalmente la cuenta por incumplir sus políticas. Un administrador debe revisar el estado de la cuenta en WhatsApp Manager.',
        470 => 'Pasaron más de 24 horas desde el último mensaje del cliente; solo puedes escribirle con una plantilla aprobada.',
        613 => 'Se alcanzó el límite de solicitudes de Meta. Se reintentará más tarde.',
        80007 => 'La cuenta de WhatsApp Business alcanzó su límite de solicitudes. Se reintentará más tarde.',
        130429 => 'Se alcanzó el límite de envíos por segundo de este número. El mensaje se reintentará más tarde.',
        130472 => 'Meta no entregó el mensaje porque el número del cliente participa en un experimento de Meta.',
        131000 => 'Meta tuvo una falla desconocida al procesar el mensaje. Puedes intentar enviarlo de nuevo.',
        131005 => 'La conexión no tiene permiso para enviar mensajes con este número.',
        131008 => 'Falta un dato obligatorio del mensaje.',
        131009 => 'Un dato del mensaje no es válido.',
        131016 => 'El servicio de WhatsApp no está disponible temporalmente. Se puede intentar de nuevo en unos minutos.',
        131021 => 'El destinatario no puede ser el mismo número que envía.',
        131026 => 'WhatsApp no pudo entregar el mensaje: el número podría no tener WhatsApp, usar una versión antigua o no haber aceptado las condiciones del servicio.',
        131030 => 'El destinatario no está en la lista de números permitidos del número de prueba de Meta.',
        131031 => 'Meta bloqueó o restringió la cuenta de WhatsApp Business.',
        131042 => 'Hay un problema con el método de pago de la cuenta de WhatsApp Business.',
        131045 => 'El número de WhatsApp Business no tiene un certificado válido; un administrador debe volver a registrarlo en Meta.',
        131047 => 'Pasaron más de 24 horas desde el último mensaje del cliente; solo puedes escribirle con una plantilla aprobada.',
        131048 => 'Meta limitó los envíos de este número porque muchos mensajes fueron reportados o bloqueados.',
        131049 => 'Meta decidió no entregar este mensaje de marketing para cuidar la experiencia del cliente.',
        131050 => 'El cliente pidió dejar de recibir mensajes de marketing.',
        131051 => 'WhatsApp no admite este tipo de mensaje.',
        131052 => 'No fue posible descargar el archivo enviado por el cliente.',
        131053 => 'WhatsApp no pudo procesar el archivo adjunto: el formato o el tamaño no está admitido.',
        131056 => 'Se enviaron demasiados mensajes seguidos a este cliente. Espera un momento antes de volver a escribirle.',
        131057 => 'La cuenta de WhatsApp Business está en mantenimiento.',
        132000 => 'La cantidad de variables no coincide con la plantilla.',
        132001 => 'La plantilla no existe en Meta para ese idioma o aún no está aprobada.',
        132005 => 'El texto de la plantilla con sus variables es demasiado largo.',
        132007 => 'El contenido de la plantilla infringe las políticas de formato de Meta.',
        132012 => 'El formato de una variable de la plantilla no es válido.',
        132015 => 'La plantilla está pausada en Meta por su baja calidad.',
        132016 => 'La plantilla fue deshabilitada en Meta.',
        133010 => 'El número de WhatsApp Business no está registrado en Cloud API. Un administrador debe registrarlo en Meta.',
        135000 => 'Meta rechazó el mensaje por un error genérico.',
    ];

    /**
     * The explanation of a code; codes without their own entry get the text
     * of their family (template, message, token, permission, limit) so the
     * reader never sees Meta's English description.
     */
    public static function explain(?int $code, ?string $fallback = null): string
    {
        $message = self::messageFor($code);

        if ($message !== null) {
            return $message;
        }

        return match (true) {
            $code === null => $fallback ?? 'Meta rechazó la solicitud.',
            self::isThrottling($code) => 'Se alcanzó un límite de solicitudes de Meta. Se reintentará más tarde.',
            self::isTemporary($code) => self::MESSAGES[1],
            $code >= 132000 && $code < 133000 => 'Meta rechazó la plantilla. Revisa que esté aprobada y que las variables sean correctas.',
            $code >= 131000 && $code < 132000 => 'WhatsApp no pudo procesar el mensaje.',
            $code >= 133000 && $code < 134000 => 'Hay un problema con el registro del número de WhatsApp Business en Meta.',
            $code >= 200 && $code < 300 => self::MESSAGES[200],
            default => $fallback ?? 'Meta rechazó la solicitud.',
        };
    }

    /**
     * Split a stored error text into its plain explanation and the technical
     * part older entries carry (" (código N)" and " Detalle de Meta: …").
     *
     * @return array{0: string, 1: string|null}
     */
    public static function separateTechnicalDetail(string $text): array
    {
        if (preg_match('/^(.*?)\s*\(código (\d+)\)\.?\s*(?:Detalle de Meta:\s*(.*))?$/su', $text, $match) === 1) {
            $technical = 'Código '.$match[2].(isset($match[3]) && $match[3] !== '' ? ' · '.$match[3] : '');

            return [trim($match[1]), $technical];
        }

        if (preg_match('/^(.*?)\s*Detalle de Meta:\s*(.*)$/su', $text, $match) === 1) {
            return [trim($match[1]), $match[2]];
        }

        return [$text, null];
    }

    public static function messageFor(?int $code): ?string
    {
        return $code === null ? null : (self::MESSAGES[$code] ?? null);
    }

    public static function isThrottling(?int $code): bool
    {
        return $code !== null && in_array($code, self::THROTTLING, true);
    }

    public static function isTemporary(?int $code): bool
    {
        return $code !== null && in_array($code, self::TEMPORARY, true);
    }

    /**
     * Codes of errors about one message (not about the credentials).
     */
    public static function isMessageError(?int $code): bool
    {
        return $code !== null && ($code >= 130000 && $code < 140000 || in_array($code, [368, 470], true));
    }
}

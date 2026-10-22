<?php

namespace App\Services\Meta;

/**
 * Spanish explanations of the WhatsApp Cloud API error codes an agent or an
 * administrator can act upon. Unknown codes fall back to a generic text plus
 * Meta's (sanitized) detail.
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
     * @var array<int, string>
     */
    private const MESSAGES = [
        368 => 'La cuenta de WhatsApp Business está bloqueada temporalmente por infringir políticas de Meta.',
        470 => 'Pasaron más de 24 horas desde el último mensaje del cliente; solo puedes escribirle con una plantilla aprobada.',
        130429 => 'Se alcanzó el límite de envíos por segundo de Meta.',
        130472 => 'Meta no entregó el mensaje porque el número del cliente participa en un experimento de Meta.',
        131000 => 'Meta tuvo una falla desconocida al procesar el mensaje.',
        131005 => 'El token no tiene permiso para enviar mensajes con este número.',
        131008 => 'Falta un dato obligatorio del mensaje.',
        131009 => 'Un dato del mensaje no es válido.',
        131016 => 'El servicio de WhatsApp no está disponible temporalmente.',
        131021 => 'El destinatario no puede ser el mismo número que envía.',
        131026 => 'WhatsApp no pudo entregar el mensaje: el número podría no tener WhatsApp, usar una versión antigua o no haber aceptado las condiciones del servicio.',
        131030 => 'El destinatario no está en la lista de números permitidos del número de prueba de Meta.',
        131031 => 'La cuenta de WhatsApp Business fue bloqueada o restringida por Meta.',
        131042 => 'Hay un problema con el método de pago de la cuenta de WhatsApp Business.',
        131045 => 'El número de WhatsApp Business no tiene un certificado válido; vuelve a registrarlo en Meta.',
        131047 => 'Pasaron más de 24 horas desde el último mensaje del cliente; solo puedes escribirle con una plantilla aprobada.',
        131048 => 'Meta limitó los envíos de este número porque muchos mensajes fueron reportados o bloqueados.',
        131049 => 'Meta decidió no entregar este mensaje de marketing para cuidar la experiencia del cliente.',
        131050 => 'El cliente pidió dejar de recibir mensajes de marketing.',
        131051 => 'WhatsApp no admite este tipo de mensaje.',
        131052 => 'No fue posible descargar el archivo enviado por el cliente.',
        131053 => 'Meta no pudo procesar el archivo adjunto (formato o tamaño no admitido).',
        131056 => 'Se enviaron demasiados mensajes seguidos a este cliente.',
        131057 => 'La cuenta de WhatsApp Business está en mantenimiento.',
        132000 => 'La cantidad de variables no coincide con la plantilla.',
        132001 => 'La plantilla no existe en Meta para ese idioma o aún no está aprobada.',
        132005 => 'El texto de la plantilla con sus variables es demasiado largo.',
        132007 => 'El contenido de la plantilla infringe las políticas de formato de Meta.',
        132012 => 'El formato de una variable de la plantilla no es válido.',
        132015 => 'La plantilla está pausada en Meta por su baja calidad.',
        132016 => 'La plantilla fue deshabilitada en Meta.',
        133010 => 'El número de WhatsApp Business no está registrado en Cloud API.',
        135000 => 'Meta rechazó el mensaje por un error genérico del usuario.',
    ];

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

<?php

namespace App\Actions\Connections;

use App\Enums\AuditAction;
use App\Models\Connection;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\Meta\ConnectionMonitor;
use App\Services\Meta\MetaGraphClient;
use App\Services\Meta\MetaGraphException;
use Illuminate\Validation\ValidationException;
use SensitiveParameter;

/**
 * Registers the connection's WhatsApp number with the Cloud API
 * (POST /{phone-number-id}/register). It is the step that takes a number
 * from PENDING to CONNECTED after it was added to the WhatsApp Business
 * Account; without it Meta neither delivers nor accepts messages for it.
 *
 * The six-digit PIN is the number's two-step verification PIN: it is set by
 * this call when the number has none, and must match when it already has
 * one. It only travels in the request to Meta: it is never stored, logged
 * or written to the audit entry, which records only that the registration
 * happened.
 */
class RegisterPhoneNumber
{
    /**
     * Graph API codes of the registration endpoint, in plain language.
     *
     * @var array<int, string>
     */
    private const REGISTRATION_ERRORS = [
        133005 => 'El PIN no coincide con el PIN de verificación en dos pasos de este número. Escribe el PIN correcto o restablécelo en WhatsApp Manager (Números de teléfono → Verificación en dos pasos).',
        133006 => 'Meta pide verificar el número (código por SMS o llamada) antes de registrarlo. Complétalo en WhatsApp Manager y vuelve a intentarlo.',
        133008 => 'Demasiados intentos con un PIN incorrecto. Meta bloqueó el registro por un tiempo: espera antes de intentarlo de nuevo.',
        133009 => 'Demasiados intentos de registro seguidos. Espera unos minutos antes de volver a intentarlo.',
        133010 => 'El número no está dado de alta en la cuenta de WhatsApp Business. Agrégalo y verifícalo en WhatsApp Manager antes de registrarlo.',
        133015 => 'El número se dio de baja hace poco. Meta pide esperar unos minutos antes de registrarlo de nuevo.',
        133016 => 'Meta limitó los registros de este número (demasiados registros y bajas en poco tiempo). Inténtalo más tarde.',
        2388001 => 'El número sigue activo en la aplicación WhatsApp o WhatsApp Business (o con otro proveedor). Elimina esa cuenta o migra el número antes de registrarlo en la API.',
    ];

    public function __construct(
        private MetaGraphClient $graph,
        private AuditLogger $audit,
        private ConnectionMonitor $monitor,
        private VerifyConnectionCredentials $verifyCredentials,
    ) {}

    /**
     * @throws ValidationException|MetaGraphException
     */
    public function handle(Connection $connection, #[SensitiveParameter] string $pin, User $actor): void
    {
        if (blank($connection->phone_number_id) || blank($connection->access_token)) {
            throw ValidationException::withMessages([
                'connection' => 'Guarda el Phone number ID y el token de acceso antes de registrar el número.',
            ]);
        }

        try {
            $response = $this->graph->post(
                (string) $connection->access_token,
                rawurlencode((string) $connection->phone_number_id).'/register',
                ['messaging_product' => 'whatsapp', 'pin' => $pin],
                'el registro del número',
            );
        } catch (MetaGraphException $exception) {
            if ($exception->kind === MetaGraphException::TOKEN_INVALID) {
                $this->monitor->credentialsRejected($connection, $exception->getMessage());
            }

            $explained = self::REGISTRATION_ERRORS[$exception->metaCode ?? -1] ?? null;

            throw $explained === null
                ? $exception
                : new MetaGraphException($explained, $exception->rejected, $exception->metaCode, $exception->status, $exception->kind);
        }

        if (($response['success'] ?? false) !== true) {
            throw new MetaGraphException('Meta no confirmó el registro del número. Revisa su estado en WhatsApp Manager antes de intentarlo de nuevo.', rejected: false, kind: MetaGraphException::AMBIGUOUS);
        }

        // Only the fact is recorded: never the PIN.
        $this->audit->record(AuditAction::ConnectionNumberRegistered, $connection, [
            'phone_number_id' => (string) $connection->phone_number_id,
        ], $actor);

        // Refresh the status Meta reports for the number (CONNECTED once the
        // registration settles). A failure here does not undo the registration.
        try {
            $this->verifyCredentials->handle($connection, $actor);
        } catch (ValidationException) {
            // Nothing to refresh without saved credentials.
        }
    }
}

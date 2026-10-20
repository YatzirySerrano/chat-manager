<?php

namespace App\Actions\Connections;

use App\Enums\AuditAction;
use App\Models\Connection;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\Meta\MetaGraphClient;
use App\Services\Meta\MetaGraphException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Checks the saved Phone number ID and access token against the Graph API.
 *
 * Only an answer from Meta changes the recorded credential state: a rejection
 * marks the credentials invalid, while a timeout or a failure on Meta's side
 * is inconclusive and leaves the previous evidence untouched. The WhatsApp
 * Business Account ID is not validated by this call.
 */
class VerifyConnectionCredentials
{
    public function __construct(
        private MetaGraphClient $graph,
        private AuditLogger $audit,
    ) {}

    /**
     * @throws ValidationException
     */
    public function handle(Connection $connection, User $actor): CredentialVerification
    {
        $phoneNumberId = (string) $connection->phone_number_id;
        $accessToken = (string) $connection->access_token;

        if ($phoneNumberId === '' || $accessToken === '') {
            throw ValidationException::withMessages([
                'connection' => 'Guarda el Phone number ID y el token de acceso antes de verificar las credenciales.',
            ]);
        }

        try {
            $profile = $this->graph->phoneNumber($phoneNumberId, $accessToken);
        } catch (MetaGraphException $exception) {
            return $exception->rejected
                ? $this->recordRejection($connection, $phoneNumberId, $accessToken, $exception, $actor)
                : $this->recordInconclusive($connection, $exception, $actor);
        }

        return DB::transaction(function () use ($connection, $phoneNumberId, $accessToken, $profile, $actor): CredentialVerification {
            $this->lockUnchanged($connection, $phoneNumberId, $accessToken);

            $connection->forceFill([
                'credentials_verified_at' => now(),
                'credentials_error' => null,
                'verified_name' => $profile['verified_name'],
                'display_phone_number' => $profile['display_phone_number'] === null
                    ? $connection->display_phone_number
                    : Str::limit($profile['display_phone_number'], 32, ''),
                'quality_rating' => $this->limit($profile['quality_rating'], 20),
                'phone_status' => $this->limit($profile['status'], 40),
                'code_verification_status' => $this->limit($profile['code_verification_status'], 40),
                'platform_type' => $this->limit($profile['platform_type'], 40),
            ])->save();

            $this->audit->record(AuditAction::ConnectionCredentialsVerified, $connection, [
                'verification_result' => CredentialVerification::VERIFIED,
                'verified_name' => $connection->verified_name,
                'phone_status' => $connection->phone_status,
                'quality_rating' => $connection->quality_rating,
            ], $actor);

            $message = $connection->phone_status !== null && $connection->phone_status !== 'CONNECTED'
                ? "Meta aceptó las credenciales, pero reporta el número con estado {$connection->phone_status}. Debe estar CONNECTED para enviar y recibir mensajes."
                : 'Meta aceptó las credenciales.';

            return new CredentialVerification(CredentialVerification::VERIFIED, $message);
        });
    }

    private function recordRejection(Connection $connection, string $phoneNumberId, string $accessToken, MetaGraphException $exception, User $actor): CredentialVerification
    {
        return DB::transaction(function () use ($connection, $phoneNumberId, $accessToken, $exception, $actor): CredentialVerification {
            $this->lockUnchanged($connection, $phoneNumberId, $accessToken);

            $connection->forceFill([
                'credentials_verified_at' => null,
                'credentials_error' => Str::limit($exception->getMessage(), 500, ''),
            ])->save();

            $this->audit->record(AuditAction::ConnectionCredentialsFailed, $connection, [
                'verification_result' => CredentialVerification::REJECTED,
                'meta_code' => $exception->metaCode,
                'http_status' => $exception->status,
                'reason' => $connection->credentials_error,
            ], $actor);

            return new CredentialVerification(CredentialVerification::REJECTED, $exception->getMessage());
        });
    }

    private function recordInconclusive(Connection $connection, MetaGraphException $exception, User $actor): CredentialVerification
    {
        $this->audit->record(AuditAction::ConnectionCredentialsFailed, $connection, [
            'verification_result' => CredentialVerification::UNREACHABLE,
            'meta_code' => $exception->metaCode,
            'http_status' => $exception->status,
            'reason' => $exception->getMessage(),
        ], $actor);

        return new CredentialVerification(CredentialVerification::UNREACHABLE, $exception->getMessage());
    }

    /**
     * The HTTP call runs outside the transaction; if the credentials were
     * replaced meanwhile, the answer no longer describes them.
     *
     * @throws ValidationException
     */
    private function lockUnchanged(Connection $connection, string $phoneNumberId, string $accessToken): void
    {
        $current = Connection::query()->whereKey($connection->id)->lockForUpdate()->firstOrFail();

        if ((string) $current->phone_number_id !== $phoneNumberId || (string) $current->access_token !== $accessToken) {
            throw ValidationException::withMessages([
                'connection' => 'Las credenciales cambiaron mientras se verificaban. Vuelve a intentarlo.',
            ]);
        }
    }

    private function limit(?string $value, int $length): ?string
    {
        return $value === null ? null : Str::limit($value, $length, '');
    }
}

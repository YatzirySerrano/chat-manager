<?php

namespace App\Services\Meta;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Minimal client for the Meta Graph API (WhatsApp Cloud API). The access token
 * travels only in the Authorization header and is never logged, stored in an
 * exception or echoed back in an error message.
 */
class MetaGraphClient
{
    /**
     * Fields requested when checking a WhatsApp business phone number.
     *
     * @var list<string>
     */
    public const PHONE_NUMBER_FIELDS = [
        'display_phone_number',
        'verified_name',
        'quality_rating',
        'code_verification_status',
        'status',
        'platform_type',
    ];

    /**
     * Read the profile of a WhatsApp business phone number.
     *
     * @return array{id: string|null, display_phone_number: string|null, verified_name: string|null, quality_rating: string|null, code_verification_status: string|null, status: string|null, platform_type: string|null}
     *
     * @throws MetaGraphException
     */
    public function phoneNumber(string $phoneNumberId, string $accessToken): array
    {
        try {
            $response = $this->request($accessToken)->get(rawurlencode($phoneNumberId), [
                'fields' => implode(',', self::PHONE_NUMBER_FIELDS),
            ]);
        } catch (ConnectionException) {
            throw new MetaGraphException('No fue posible comunicarse con Meta (tiempo de espera agotado o sin conexión). Inténtalo de nuevo en unos minutos.', rejected: false);
        }

        if (! $response->successful()) {
            throw $this->failure($response, $accessToken);
        }

        $payload = $response->json();

        if (! is_array($payload)) {
            throw new MetaGraphException('Meta devolvió una respuesta inesperada. Inténtalo de nuevo más tarde.', rejected: false, status: $response->status());
        }

        return [
            'id' => $this->stringOrNull($payload['id'] ?? null),
            'display_phone_number' => $this->stringOrNull($payload['display_phone_number'] ?? null),
            'verified_name' => $this->stringOrNull($payload['verified_name'] ?? null),
            'quality_rating' => $this->stringOrNull($payload['quality_rating'] ?? null),
            'code_verification_status' => $this->stringOrNull($payload['code_verification_status'] ?? null),
            'status' => $this->stringOrNull($payload['status'] ?? null),
            'platform_type' => $this->stringOrNull($payload['platform_type'] ?? null),
        ];
    }

    private function request(string $accessToken): PendingRequest
    {
        $baseUrl = rtrim((string) config('services.meta.base_url'), '/').'/'.trim((string) config('services.meta.graph_version'), '/');
        $timeout = max(1, (int) config('services.meta.timeout'));

        return Http::baseUrl($baseUrl)
            ->withToken($accessToken)
            ->acceptJson()
            ->connectTimeout(min(5, $timeout))
            ->timeout($timeout);
    }

    /**
     * Translate a Graph API error into a safe, actionable message.
     */
    private function failure(Response $response, string $accessToken): MetaGraphException
    {
        $error = $response->json('error');
        $error = is_array($error) ? $error : [];
        $code = isset($error['code']) && is_numeric($error['code']) ? (int) $error['code'] : null;
        $detail = $this->sanitize(is_string($error['message'] ?? null) ? $error['message'] : '', $accessToken);

        if ($response->serverError() || $response->status() === 429 || in_array($code, [1, 2, 4, 17, 32, 613, 80007], true)) {
            return new MetaGraphException(
                $this->compose('Meta no pudo atender la verificación en este momento (límite de solicitudes o falla temporal). Inténtalo más tarde.', $code, $detail),
                rejected: false,
                metaCode: $code,
                status: $response->status(),
            );
        }

        $hint = match (true) {
            in_array($code, [0, 190], true) => 'El token de acceso es inválido o expiró. Genera un nuevo token de usuario del sistema.',
            in_array($code, [3, 10, 200], true) || ($code !== null && $code >= 200 && $code < 300) => 'El token no tiene los permisos necesarios (whatsapp_business_management y whatsapp_business_messaging) sobre este número.',
            $code === 100 || $code === 803 => 'El Phone number ID no existe o el token no tiene acceso a él.',
            default => 'Meta rechazó las credenciales.',
        };

        return new MetaGraphException($this->compose($hint, $code, $detail), rejected: true, metaCode: $code, status: $response->status());
    }

    private function compose(string $hint, ?int $code, string $detail): string
    {
        $message = $hint;

        if ($code !== null) {
            $message .= " (código {$code})";
        }

        if ($detail !== '') {
            $message .= ' Detalle de Meta: '.$detail;
        }

        return Str::limit($message, 480);
    }

    /**
     * Remove anything that could leak the token from a provider message.
     */
    private function sanitize(string $message, string $accessToken): string
    {
        if ($accessToken !== '') {
            $message = str_replace($accessToken, '[token]', $message);
        }

        $message = (string) preg_replace('/\bEA[A-Za-z0-9]{20,}\b/', '[token]', $message);
        $message = (string) preg_replace('/access_token=[^&\s]+/i', 'access_token=[token]', $message);

        return Str::limit(trim(strip_tags($message)), 300);
    }

    private function stringOrNull(mixed $value): ?string
    {
        return is_scalar($value) && (string) $value !== '' ? Str::limit((string) $value, 190, '') : null;
    }
}

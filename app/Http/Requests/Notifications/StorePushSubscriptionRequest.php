<?php

namespace App\Http\Requests\Notifications;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Registers the current browser for Web Push. Only the push service
 * endpoint is accepted (pushes carry no payload, so the browser's
 * encryption keys are not needed), and only on a known push service over
 * HTTPS: the server never calls a URL chosen by the client.
 */
class StorePushSubscriptionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'endpoint' => ['required', 'string', 'max:2048', 'url:https', function (string $attribute, mixed $value, Closure $fail): void {
                if (! is_string($value) || ! self::isAllowedPushService($value)) {
                    $fail('El navegador no usa un servicio de notificaciones push admitido.');
                }
            }],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['endpoint' => 'suscripción push'];
    }

    public static function isAllowedPushService(string $endpoint): bool
    {
        $host = strtolower((string) parse_url($endpoint, PHP_URL_HOST));

        if ($host === '' || parse_url($endpoint, PHP_URL_SCHEME) !== 'https' || parse_url($endpoint, PHP_URL_USER) !== null) {
            return false;
        }

        /** @var list<string> $allowed */
        $allowed = config('webpush.allowed_hosts', []);

        foreach ($allowed as $suffix) {
            $suffix = strtolower(ltrim($suffix, '.'));

            if ($suffix !== '' && ($host === $suffix || str_ends_with($host, '.'.$suffix))) {
                return true;
            }
        }

        return false;
    }
}

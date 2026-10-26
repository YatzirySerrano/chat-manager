<?php

namespace App\Http\Requests\Admin;

use App\Enums\ApiAbility;
use App\Models\ApiToken;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Issuing an integration API token: a name, at least one ability and one
 * connection (not deleted), optional departments (active) and IPs or CIDR
 * ranges, and an expiry date no later than integrations.tokens.max_days
 * (integrations.tokens.default_days when omitted).
 */
class ApiTokenRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', ApiToken::class) ?? false;
    }

    /**
     * Collapses the name and turns the IP allowlist (a list, or one entry
     * per line or comma) into a clean list.
     */
    protected function prepareForValidation(): void
    {
        if (is_string($this->input('name'))) {
            $this->merge(['name' => Str::squish($this->input('name'))]);
        }

        $ips = $this->input('allowed_ips');

        if (is_string($ips)) {
            $ips = preg_split('/[\s,]+/', $ips) ?: [];
        }

        if (is_array($ips)) {
            $this->merge(['allowed_ips' => array_values(array_filter(
                array_map(fn (mixed $ip): mixed => is_string($ip) ? trim($ip) : $ip, $ips),
                fn (mixed $ip): bool => $ip !== '' && $ip !== null,
            ))]);
        }
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $maxDays = (int) config('integrations.tokens.max_days', 365);

        return [
            'name' => ['required', 'string', 'max:100'],
            'abilities' => ['required', 'array', 'min:1'],
            'abilities.*' => ['required', 'string', 'distinct', Rule::enum(ApiAbility::class)],
            'connection_ids' => ['required', 'array', 'min:1', 'max:100'],
            'connection_ids.*' => ['required', 'integer', 'distinct', Rule::exists('connections', 'id')->whereNull('deleted_at')],
            'department_ids' => ['nullable', 'array', 'max:100'],
            'department_ids.*' => ['required', 'integer', 'distinct', Rule::exists('departments', 'id')->where('is_active', true)],
            'allowed_ips' => ['nullable', 'array', 'max:50'],
            'allowed_ips.*' => ['required', 'string', 'max:64', $this->ipOrRange(...)],
            'expires_at' => ['nullable', 'date_format:Y-m-d', 'after:today', 'before_or_equal:'.now()->addDays($maxDays)->toDateString()],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'abilities.required' => 'Elige al menos una capacidad.',
            'abilities.min' => 'Elige al menos una capacidad.',
            'connection_ids.required' => 'Elige al menos una conexión.',
            'connection_ids.min' => 'Elige al menos una conexión.',
            'connection_ids.*.exists' => 'Una de las conexiones no existe o fue eliminada.',
            'department_ids.*.exists' => 'Uno de los departamentos no existe o fue eliminado.',
            'expires_at.after' => 'La fecha de vencimiento debe ser posterior a hoy.',
            'expires_at.before_or_equal' => 'Una clave de acceso puede durar como máximo '.(int) config('integrations.tokens.max_days', 365).' días.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'name' => 'nombre',
            'abilities' => 'capacidades',
            'connection_ids' => 'conexiones',
            'department_ids' => 'departamentos',
            'allowed_ips' => 'IP permitidas',
            'allowed_ips.*' => 'IP permitida',
            'expires_at' => 'fecha de vencimiento',
        ];
    }

    /**
     * Validated attributes ready for IssueApiToken.
     *
     * @return array{name: string, abilities: list<string>, connection_ids: list<int>, department_ids: list<int>|null, allowed_ips: list<string>|null, expires_at: CarbonInterface}
     */
    public function payload(): array
    {
        $data = $this->validated();
        $expiresAt = isset($data['expires_at'])
            ? CarbonImmutable::parse((string) $data['expires_at'])->endOfDay()
            : now()->addDays((int) config('integrations.tokens.default_days', 90));

        return [
            'name' => (string) $data['name'],
            'abilities' => array_values(array_map('strval', $data['abilities'])),
            'connection_ids' => array_values(array_map('intval', $data['connection_ids'])),
            'department_ids' => filled($data['department_ids'] ?? null) ? array_values(array_map('intval', $data['department_ids'])) : null,
            'allowed_ips' => filled($data['allowed_ips'] ?? null) ? array_values(array_map('strval', $data['allowed_ips'])) : null,
            'expires_at' => $expiresAt,
        ];
    }

    /**
     * An IPv4 or IPv6 address, or a CIDR range of one.
     */
    private function ipOrRange(string $attribute, mixed $value, Closure $fail): void
    {
        $parts = explode('/', (string) $value, 2);
        $ip = $parts[0];
        $isV4 = filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false;
        $isV6 = filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false;
        $prefix = $parts[1] ?? null;

        $valid = ($isV4 || $isV6) && ($prefix === null
            || (ctype_digit($prefix) && (int) $prefix <= ($isV4 ? 32 : 128)));

        if (! $valid) {
            $fail('«'.$value.'» no es una IP ni un rango CIDR válido.');
        }
    }
}

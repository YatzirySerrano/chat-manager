<?php

namespace App\Services;

use App\Enums\AuditAction;
use App\Models\AuditLog;
use App\Models\Department;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

/**
 * Records administrative actions. Sensitive values (passwords, tokens and
 * secrets) are always stripped before persisting.
 */
class AuditLogger
{
    /**
     * Key fragments that are never written to the audit log.
     *
     * @var list<string>
     */
    private const SENSITIVE_KEY_FRAGMENTS = ['password', 'token', 'secret', 'recovery_code', 'api_key', 'credential'];

    /**
     * @param  array<string, mixed>  $properties
     */
    public function record(AuditAction $action, ?Model $subject = null, array $properties = [], ?User $actor = null): AuditLog
    {
        if ($actor === null) {
            $authenticated = Auth::user();
            $actor = $authenticated instanceof User ? $authenticated : null;
        }

        $request = app()->runningInConsole() ? null : request();

        return AuditLog::create([
            'user_id' => $actor?->id,
            'actor_name' => $actor === null ? 'Sistema' : $actor->name,
            'action' => $action,
            'auditable_type' => $subject?->getMorphClass(),
            'auditable_id' => $subject?->getKey(),
            'auditable_label' => $this->labelFor($subject),
            'properties' => $properties === [] ? null : $this->sanitize($properties),
            'ip_address' => $request?->ip(),
            'user_agent' => $request === null ? null : Str::limit((string) $request->userAgent(), 500, ''),
            'created_at' => now(),
        ]);
    }

    /**
     * Remove sensitive values recursively.
     *
     * @param  array<array-key, mixed>  $properties
     * @return array<array-key, mixed>
     */
    public function sanitize(array $properties): array
    {
        $clean = [];

        foreach ($properties as $key => $value) {
            if (is_string($key) && $this->isSensitiveKey($key)) {
                continue;
            }

            $clean[$key] = is_array($value) ? $this->sanitize($value) : $value;
        }

        return $clean;
    }

    private function isSensitiveKey(string $key): bool
    {
        return Str::contains(Str::lower($key), self::SENSITIVE_KEY_FRAGMENTS);
    }

    private function labelFor(?Model $subject): ?string
    {
        return match (true) {
            $subject instanceof User => "{$subject->name} <{$subject->email}>",
            $subject instanceof Department => $subject->name,
            $subject instanceof Role => $subject->label,
            default => null,
        };
    }
}

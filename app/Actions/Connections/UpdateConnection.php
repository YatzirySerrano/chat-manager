<?php

namespace App\Actions\Connections;

use App\Enums\AuditAction;
use App\Models\Connection;
use App\Models\Department;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Updates the configuration of a connection. Secrets left blank keep their
 * stored value. Changing the Phone number ID or the access token discards the
 * previous verification, because it no longer describes the saved credentials.
 */
class UpdateConnection
{
    /**
     * Data obtained from Meta that only remains valid for the verified credentials.
     *
     * @var list<string>
     */
    private const VERIFIED_PROFILE = ['verified_name', 'quality_rating', 'phone_status', 'code_verification_status', 'platform_type'];

    public function __construct(private AuditLogger $audit) {}

    /**
     * @param  array{attributes: array<string, mixed>, secrets: array<string, string>, department_ids: list<int>}  $data
     */
    public function handle(Connection $connection, array $data, User $actor): Connection
    {
        return DB::transaction(function () use ($connection, $data, $actor): Connection {
            $connection->fill($data['attributes']);
            $changes = $this->changes($connection);

            $replacedSecrets = [];

            foreach ($data['secrets'] as $field => $value) {
                if ($value !== $connection->getAttribute($field)) {
                    $connection->setAttribute($field, $value);
                    $replacedSecrets[] = $field;
                }
            }

            $credentialsChanged = $connection->isDirty('phone_number_id') || in_array('access_token', $replacedSecrets, true);

            if ($credentialsChanged && ($connection->credentials_verified_at !== null || $connection->credentials_error !== null)) {
                $connection->forceFill(['credentials_verified_at' => null, 'credentials_error' => null]);
                $connection->forceFill(array_fill_keys(self::VERIFIED_PROFILE, null));
            }

            try {
                $connection->save();
            } catch (UniqueConstraintViolationException) {
                throw ValidationException::withMessages(['phone_number_id' => 'Este Phone number ID ya está registrado en otra conexión.']);
            }

            $departmentChanges = $connection->departments()->sync($data['department_ids']);
            $added = $departmentChanges['attached'];
            $removed = $departmentChanges['detached'];

            if ($added !== [] || $removed !== []) {
                $changes['departments'] = [
                    'added' => Department::query()->whereKey($added)->orderBy('name')->pluck('name')->all(),
                    'removed' => Department::query()->whereKey($removed)->orderBy('name')->pluck('name')->all(),
                ];
            }

            if ($changes !== []) {
                $this->audit->record(AuditAction::ConnectionUpdated, $connection, [
                    'changes' => $changes,
                    'verification_reset' => $credentialsChanged,
                ], $actor);
            }

            if ($replacedSecrets !== []) {
                $this->audit->record(AuditAction::ConnectionCredentialsUpdated, $connection, [
                    'replaced_fields' => array_map(fn (string $field): string => ConnectionSecrets::label($field), $replacedSecrets),
                    'verification_reset' => $credentialsChanged,
                ], $actor);
            }

            return $connection;
        });
    }

    /**
     * Old and new values of the non-secret attributes being changed.
     *
     * @return array<string, array{old: mixed, new: mixed}>
     */
    private function changes(Connection $connection): array
    {
        $changes = [];

        foreach (array_keys($connection->getDirty()) as $attribute) {
            if (array_key_exists($attribute, ConnectionSecrets::FIELDS)) {
                continue;
            }

            $changes[$attribute] = [
                'old' => $connection->getOriginal($attribute),
                'new' => $connection->getAttribute($attribute),
            ];
        }

        return $changes;
    }
}

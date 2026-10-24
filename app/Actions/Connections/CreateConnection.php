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
 * Creates a connection. It always starts inactive: activation is a separate,
 * audited decision that requires the connection to be configured (the Meta
 * credentials for WhatsApp; for the web chat, its widget with at least one
 * authorized origin). A web chat connection gets its widget in the same
 * transaction, without origins, so it stays not configured until they are
 * saved.
 */
class CreateConnection
{
    public function __construct(private AuditLogger $audit) {}

    /**
     * @param  array{attributes: array<string, mixed>, secrets: array<string, string>, department_ids: list<int>}  $data
     */
    public function handle(array $data, User $actor): Connection
    {
        return DB::transaction(function () use ($data, $actor): Connection {
            $connection = new Connection([...$data['attributes'], ...$data['secrets']]);
            $connection->forceFill(['is_active' => false, 'created_by_user_id' => $actor->id]);

            try {
                $connection->save();
            } catch (UniqueConstraintViolationException $exception) {
                if ($connection->isWebChat()) {
                    throw $exception;
                }

                throw ValidationException::withMessages(['phone_number_id' => 'Este Phone number ID ya está registrado en otra conexión.']);
            }

            if ($connection->isWebChat()) {
                $connection->webChatWidget()->create([
                    'allowed_origins' => [],
                    'title' => mb_substr($connection->name, 0, 60),
                ]);
            }

            $connection->departments()->sync($data['department_ids']);

            $this->audit->record(AuditAction::ConnectionCreated, $connection, [
                'channel' => $connection->channel->value,
                'phone_number_id' => $connection->phone_number_id,
                'business_account_id' => $connection->business_account_id,
                'departments' => Department::query()->whereKey($data['department_ids'])->orderBy('name')->pluck('name')->all(),
                'default_department_id' => $connection->default_department_id,
                'saved_fields' => array_map(
                    fn (string $field): string => ConnectionSecrets::label($field),
                    array_keys($data['secrets']),
                ),
            ], $actor);

            return $connection;
        });
    }
}

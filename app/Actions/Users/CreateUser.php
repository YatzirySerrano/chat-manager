<?php

namespace App\Actions\Users;

use App\Enums\AuditAction;
use App\Models\Department;
use App\Models\Role;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CreateUser
{
    public function __construct(private AuditLogger $audit) {}

    /**
     * Create an employee account. Accounts created by an administrator are
     * considered verified because the administrator vouches for the address.
     *
     * @param  array{name: string, email: string, password: string, role: Role, department_ids?: list<int>}  $data
     */
    public function handle(array $data, ?User $actor = null): User
    {
        return DB::transaction(function () use ($data, $actor): User {
            $user = new User([
                'name' => $data['name'],
                'email' => $data['email'],
                'password' => $data['password'],
            ]);

            try {
                $user->forceFill(['email_verified_at' => now(), 'is_active' => true])->save();
            } catch (UniqueConstraintViolationException) {
                // Another request registered the same address after validation passed.
                throw ValidationException::withMessages(['email' => 'El valor de correo electrónico ya está en uso.']);
            }

            $user->syncRoles([$data['role']]);

            $departmentIds = $data['role']->scope->allowsDepartmentMembership() ? ($data['department_ids'] ?? []) : [];
            $user->departments()->sync($departmentIds);

            $this->audit->record(AuditAction::UserCreated, $user, [
                'role' => $data['role']->label,
                'departments' => Department::query()->whereKey($departmentIds)->orderBy('name')->pluck('name')->all(),
            ], $actor);

            return $user;
        });
    }
}

<?php

namespace App\Actions\Departments;

use App\Enums\AuditAction;
use App\Events\Users\UserAccessChanged;
use App\Models\Department;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AddDepartmentMember
{
    public function __construct(private AuditLogger $audit) {}

    public function handle(Department $department, User $member, User $actor): void
    {
        DB::transaction(function () use ($department, $member, $actor): void {
            $role = $member->role();

            if (! $department->is_active) {
                throw ValidationException::withMessages(['user_id' => 'No es posible agregar miembros a un departamento eliminado.']);
            }

            if (! $member->is_active) {
                throw ValidationException::withMessages(['user_id' => 'No es posible agregar un usuario eliminado.']);
            }

            if ($role === null || ! $role->is_active) {
                throw ValidationException::withMessages(['user_id' => 'El usuario no tiene un rol activo.']);
            }

            if (! $role->scope->allowsDepartmentMembership()) {
                throw ValidationException::withMessages(['user_id' => "El rol {$role->label} ya ve toda la organización; no necesita pertenecer a departamentos."]);
            }

            try {
                $attached = $department->members()->syncWithoutDetaching([$member->id])['attached'];
            } catch (UniqueConstraintViolationException) {
                // A concurrent request added the same member first.
                $attached = [];
            }

            if ($attached === []) {
                throw ValidationException::withMessages(['user_id' => 'El usuario ya pertenece a este departamento.']);
            }

            $this->audit->record(AuditAction::DepartmentMemberAdded, $department, [
                'member' => $member->name,
                'member_id' => $member->id,
                'member_role' => $role->label,
            ], $actor);

            UserAccessChanged::dispatch([$member->id], 'department_member_added');
        });
    }
}

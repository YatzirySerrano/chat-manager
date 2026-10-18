<?php

namespace App\Actions\Departments;

use App\Enums\AuditAction;
use App\Models\Department;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\PlatformSettings;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ChangeDepartmentStatus
{
    public function __construct(
        private AuditLogger $audit,
        private PlatformSettings $settings,
    ) {}

    public function handle(Department $department, bool $active, User $actor): Department
    {
        if ($department->is_active === $active) {
            return $department;
        }

        return DB::transaction(function () use ($department, $active, $actor): Department {
            if (! $active) {
                $this->ensureCanBeDeactivated($department);
            }

            $department->forceFill(['is_active' => $active])->save();

            $this->audit->record(
                $active ? AuditAction::DepartmentActivated : AuditAction::DepartmentDeactivated,
                $department,
                [],
                $actor,
            );

            return $department;
        });
    }

    /**
     * @throws ValidationException
     */
    private function ensureCanBeDeactivated(Department $department): void
    {
        if ((int) $this->settings->get('operations.default_department_id') === $department->id) {
            throw ValidationException::withMessages([
                'department' => 'Es el departamento predeterminado de la plataforma. Elige otro en Configuración antes de desactivarlo.',
            ]);
        }

        Department::query()->whereKey($department->id)->lockForUpdate()->first();

        if ($department->hasUnclosedConversations()) {
            throw ValidationException::withMessages([
                'department' => 'El departamento tiene conversaciones pendientes o activas. Ciérralas o transfiérelas antes de desactivarlo.',
            ]);
        }
    }
}

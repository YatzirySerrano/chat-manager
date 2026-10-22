<?php

namespace App\Actions\Departments;

use App\Enums\AuditAction;
use App\Models\Connection;
use App\Models\Department;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\PlatformSettings;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Logically deletes a department: it stops receiving and attending
 * conversations, its memberships are kept (inert while it is deleted) and
 * the history keeps showing it where it took part.
 */
class DeleteDepartment
{
    public function __construct(
        private AuditLogger $audit,
        private PlatformSettings $settings,
    ) {}

    /**
     * @throws ValidationException
     */
    public function handle(Department $department, User $actor): Department
    {
        return DB::transaction(function () use ($department, $actor): Department {
            /** @var Department $locked */
            $locked = Department::query()->lockForUpdate()->findOrFail($department->id);

            if ($locked->isDeleted()) {
                return $locked;
            }

            $this->ensureCanBeDeleted($locked);

            $locked->forceFill(['is_active' => false])->save();

            $this->audit->record(AuditAction::DepartmentDeleted, $locked, [
                'members_kept' => $locked->members()->count(),
            ], $actor);

            return $locked;
        });
    }

    /**
     * @throws ValidationException
     */
    private function ensureCanBeDeleted(Department $department): void
    {
        if ((int) $this->settings->get('operations.default_department_id') === $department->id) {
            throw ValidationException::withMessages([
                'department' => 'Es el departamento predeterminado de la plataforma. Elige otro en Configuración antes de eliminarlo.',
            ]);
        }

        $defaultOf = Connection::query()->notDeleted()->where('default_department_id', $department->id)->orderBy('name')->pluck('name');

        if ($defaultOf->isNotEmpty()) {
            throw ValidationException::withMessages([
                'department' => 'Es el departamento predeterminado de '.($defaultOf->count() === 1 ? 'la conexión ' : 'las conexiones ')
                    .$defaultOf->implode(', ').'. Elige otro departamento predeterminado en la conexión antes de eliminarlo.',
            ]);
        }

        if ($department->hasUnclosedConversations()) {
            throw ValidationException::withMessages([
                'department' => 'El departamento tiene conversaciones pendientes o activas. Ciérralas o transfiérelas antes de eliminarlo.',
            ]);
        }
    }
}

<?php

namespace App\Actions\Departments;

use App\Enums\AuditAction;
use App\Events\Users\UserAccessChanged;
use App\Models\Department;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;

/**
 * Restores a logically deleted department with the members it kept.
 */
class RestoreDepartment
{
    public function __construct(private AuditLogger $audit) {}

    public function handle(Department $department, User $actor): Department
    {
        return DB::transaction(function () use ($department, $actor): Department {
            /** @var Department $locked */
            $locked = Department::query()->lockForUpdate()->findOrFail($department->id);

            if (! $locked->isDeleted()) {
                return $locked;
            }

            $locked->forceFill(['is_active' => true])->save();

            $this->audit->record(AuditAction::DepartmentRestored, $locked, [], $actor);

            $members = array_values(array_map('intval', $locked->members()->pluck('users.id')->all()));

            if ($members !== []) {
                UserAccessChanged::dispatch($members, 'department_restored');
            }

            return $locked;
        });
    }
}

<?php

namespace App\Actions\Departments;

use App\Enums\AuditAction;
use App\Models\Department;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;

class UpdateDepartmentOperations
{
    public function __construct(private AuditLogger $audit) {}

    /**
     * Save the automatic messages of the department (welcome and out-of-hours)
     * and audit the before/after values. A key left out keeps its value.
     * Returns the changed attributes (empty when nothing changed).
     *
     * @param  array{welcome_message?: string|null, away_message?: string|null}  $data
     * @return array<string, array{old: mixed, new: mixed}>
     */
    public function handle(Department $department, array $data, User $actor): array
    {
        return DB::transaction(function () use ($department, $data, $actor): array {
            foreach (['welcome_message', 'away_message'] as $attribute) {
                if (array_key_exists($attribute, $data)) {
                    $department->{$attribute} = $data[$attribute];
                }
            }

            if (! $department->isDirty()) {
                return [];
            }

            $changes = [];

            foreach ($department->getDirty() as $attribute => $value) {
                $changes[$attribute] = ['old' => $department->getOriginal($attribute), 'new' => $value];
            }

            $department->save();

            $this->audit->record(AuditAction::DepartmentOperationsUpdated, $department, ['changes' => $changes], $actor);

            return $changes;
        });
    }
}

<?php

namespace App\Actions\Departments;

use App\Enums\AuditAction;
use App\Models\Department;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

class UpdateDepartmentHours
{
    public function __construct(private AuditLogger $audit) {}

    /**
     * Switch between the platform hours and a custom schedule. Returning to
     * the platform hours keeps the stored custom schedule so it can be
     * restored later; it is ignored while `uses_custom_hours` is false.
     *
     * The audit diff lists every changed day value by path, e.g.
     * `business_hours.days.monday.end`.
     *
     * @param  array{uses_custom_hours: bool, business_hours: array{days: array<string, array{open: bool, start: string, end: string}>}|null}  $data
     * @return array<string, array{old: mixed, new: mixed}>
     */
    public function handle(Department $department, array $data, User $actor): array
    {
        return DB::transaction(function () use ($department, $data, $actor): array {
            $changes = [];

            if ($department->uses_custom_hours !== $data['uses_custom_hours']) {
                $changes['uses_custom_hours'] = ['old' => $department->uses_custom_hours, 'new' => $data['uses_custom_hours']];
            }

            if ($data['business_hours'] !== null) {
                $previous = Arr::dot(['business_hours' => $department->business_hours ?? []]);
                $next = Arr::dot(['business_hours' => $data['business_hours']]);

                foreach (array_unique([...array_keys($previous), ...array_keys($next)]) as $path) {
                    $old = $previous[$path] ?? null;
                    $new = $next[$path] ?? null;

                    if ($old !== $new) {
                        $changes[$path] = ['old' => $old, 'new' => $new];
                    }
                }

                $department->business_hours = $data['business_hours'];
            }

            if ($changes === []) {
                return [];
            }

            $department->uses_custom_hours = $data['uses_custom_hours'];
            $department->save();

            $this->audit->record(AuditAction::DepartmentHoursUpdated, $department, ['changes' => $changes], $actor);

            return $changes;
        });
    }
}

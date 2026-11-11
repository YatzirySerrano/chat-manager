<?php

namespace App\Actions\Departments;

use App\Actions\Conversations\AssignAutomatically;
use App\Enums\AuditAction;
use App\Models\Department;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\ServiceRules;
use Illuminate\Support\Facades\DB;

/**
 * Replaces the service rules the department overrides. A rule left out of
 * `$overrides` goes back to inheriting the platform value. The change
 * applies to the decisions taken from now on: deadlines already frozen in
 * response cycles and requests already taken are never rewritten. When a
 * queue rule changes, the queue of an automatic department is offered again
 * after the commit.
 */
class UpdateDepartmentServiceRules
{
    public function __construct(
        private AuditLogger $audit,
        private ServiceRules $rules,
        private AssignAutomatically $assignAutomatically,
    ) {}

    /**
     * @param  array<string, int|bool>  $overrides  Flat map of dotted rule key → value (see ServiceRules::overridesFrom()).
     * @return array<string, array{old: int|bool|null, new: int|bool|null}> The changed rules (empty when nothing changed); null = inherits.
     */
    public function handle(Department $department, array $overrides, User $actor): array
    {
        return DB::transaction(function () use ($department, $overrides, $actor): array {
            $before = $this->rules->overridesOf(ServiceRules::LEVEL_DEPARTMENT, $department->service_rules);
            $after = $this->rules->overridesOf(ServiceRules::LEVEL_DEPARTMENT, $overrides);
            $changes = $this->rules->diff($before, $after);

            if ($changes === []) {
                return [];
            }

            $department->service_rules = $after === [] ? null : $after;
            $department->save();

            $this->audit->record(AuditAction::DepartmentServiceRulesUpdated, $department, ['changes' => $changes], $actor);

            $this->rules->flush();

            // The requests its rules stopped holding (confirmation turned
            // off, a shorter wait) are offered once the change is committed.
            if (AssignAutomatically::rulesMayFreeRequests($changes)) {
                $this->assignAutomatically->distributeDepartmentAfterCommit($department->id);
            }

            return $changes;
        });
    }
}

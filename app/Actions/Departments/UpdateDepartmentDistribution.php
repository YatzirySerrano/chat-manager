<?php

namespace App\Actions\Departments;

use App\Actions\Conversations\AssignAutomatically;
use App\Enums\AuditAction;
use App\Enums\DistributionMode;
use App\Models\Department;
use App\Models\User;
use App\Services\AuditLogger;

/**
 * Changes how the department distributes pending conversations. Switching to
 * automatic distribution offers the conversations already waiting to its
 * available agents; conversations already assigned are never moved.
 */
class UpdateDepartmentDistribution
{
    public function __construct(
        private AuditLogger $audit,
        private AssignAutomatically $assignAutomatically,
    ) {}

    /**
     * @return int|null Conversations assigned from the queue, or null when nothing changed.
     */
    public function handle(Department $department, DistributionMode $mode, User $actor): ?int
    {
        if ($department->distribution_mode === $mode) {
            return null;
        }

        $previous = $department->distribution_mode;
        $department->forceFill(['distribution_mode' => $mode])->save();

        $this->audit->record(AuditAction::DepartmentOperationsUpdated, $department, [
            'changes' => ['distribution_mode' => ['old' => $previous->value, 'new' => $mode->value]],
        ], $actor);

        return $this->assignAutomatically->distributeQueue($department);
    }
}

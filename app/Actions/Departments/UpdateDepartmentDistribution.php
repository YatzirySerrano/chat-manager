<?php

namespace App\Actions\Departments;

use App\Actions\Conversations\AssignAutomatically;
use App\Enums\AuditAction;
use App\Enums\DistributionMode;
use App\Events\Users\UserAccessChanged;
use App\Models\Department;
use App\Models\User;
use App\Services\AuditLogger;

/**
 * Changes how the department distributes pending conversations and, when
 * given, the per agent load limit of its conversations (null inherits the
 * platform-wide limit; an agent's own limit takes precedence over both).
 * Switching to automatic distribution, or raising or removing the limit,
 * offers the conversations already waiting to its available agents;
 * conversations already assigned are never moved, even when the limit goes
 * down.
 */
class UpdateDepartmentDistribution
{
    public function __construct(
        private AuditLogger $audit,
        private AssignAutomatically $assignAutomatically,
    ) {}

    /**
     * @param  array{max_open_conversations_per_agent?: int|null}  $capacity  A key left out keeps the current limit.
     * @return int|null Conversations assigned from the queue, or null when nothing changed.
     */
    public function handle(Department $department, DistributionMode $mode, User $actor, array $capacity = []): ?int
    {
        $previous = $department->distribution_mode;
        $department->distribution_mode = $mode;

        if (array_key_exists('max_open_conversations_per_agent', $capacity)) {
            $department->max_open_conversations_per_agent = $capacity['max_open_conversations_per_agent'];
        }

        if (! $department->isDirty()) {
            return null;
        }

        $changes = [];

        foreach (['distribution_mode', 'max_open_conversations_per_agent'] as $attribute) {
            if ($department->isDirty($attribute)) {
                $old = $department->getOriginal($attribute);
                $new = $department->getAttribute($attribute);
                $changes[$attribute] = [
                    'old' => $old instanceof DistributionMode ? $old->value : $old,
                    'new' => $new instanceof DistributionMode ? $new->value : $new,
                ];
            }
        }

        $department->save();

        $this->audit->record(AuditAction::DepartmentOperationsUpdated, $department, ['changes' => $changes], $actor);

        // Whether members may accept from the queue depends on the mode.
        if (($previous === DistributionMode::Manual) !== ($mode === DistributionMode::Manual)) {
            $members = array_values(array_map('intval', $department->members()->pluck('users.id')->all()));

            if ($members !== []) {
                UserAccessChanged::dispatch($members, 'department_distribution_changed');
            }
        }

        $limit = $changes['max_open_conversations_per_agent'] ?? null;

        if (isset($changes['distribution_mode']) || ($limit !== null && AssignAutomatically::overrideMayFreeRoom($limit['old'], $limit['new']))) {
            return $this->assignAutomatically->distributeQueue($department);
        }

        return 0;
    }
}

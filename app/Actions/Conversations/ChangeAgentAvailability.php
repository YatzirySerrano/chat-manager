<?php

namespace App\Actions\Conversations;

use App\Enums\AgentAvailability;
use App\Enums\AuditAction;
use App\Enums\DistributionMode;
use App\Events\Users\AgentAvailabilityChanged;
use App\Models\User;
use App\Services\AuditLogger;

/**
 * The person declares whether they can receive automatic assignments.
 * Becoming unavailable never takes away conversations already assigned;
 * becoming available offers them the waiting conversations of their
 * automatically distributed departments.
 */
class ChangeAgentAvailability
{
    public function __construct(
        private AuditLogger $audit,
        private AssignAutomatically $assignAutomatically,
    ) {}

    public function handle(User $user, AgentAvailability $availability): int
    {
        if ($user->availability === $availability) {
            return 0;
        }

        $previous = $user->availability;
        $user->forceFill(['availability' => $availability, 'availability_changed_at' => now()])->save();

        $this->audit->record(AuditAction::UserAvailabilityChanged, $user, [
            'from' => $previous->value,
            'to' => $availability->value,
        ], $user);

        AgentAvailabilityChanged::dispatch($user->id, $availability->value);

        if ($availability !== AgentAvailability::Available) {
            return 0;
        }

        $assigned = 0;

        foreach ($user->departments()->active()->where('distribution_mode', DistributionMode::Automatic)->get() as $department) {
            $assigned += $this->assignAutomatically->distributeQueue($department);
        }

        return $assigned;
    }
}

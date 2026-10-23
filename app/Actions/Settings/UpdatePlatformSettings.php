<?php

namespace App\Actions\Settings;

use App\Actions\Conversations\AssignAutomatically;
use App\Enums\AuditAction;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\PlatformSettings;
use Illuminate\Support\Facades\DB;

class UpdatePlatformSettings
{
    public function __construct(
        private PlatformSettings $settings,
        private AuditLogger $audit,
        private AssignAutomatically $assignAutomatically,
    ) {}

    /**
     * @param  array<string, array<string, mixed>>  $groups
     * @return array<string, array{old: mixed, new: mixed}>
     */
    public function handle(array $groups, User $actor, AuditAction $action = AuditAction::SettingsUpdated): array
    {
        return DB::transaction(function () use ($groups, $actor, $action): array {
            $changes = $this->settings->update($groups);

            if ($changes !== []) {
                $this->audit->record($action, null, ['changes' => $changes], $actor);
            }

            // A raised or removed load limit leaves room: the automatic queues
            // are offered again once the new value is committed.
            $limit = $changes['operations.max_open_conversations_per_agent'] ?? null;

            if ($limit !== null && AssignAutomatically::limitMayFreeRoom($limit['old'], $limit['new'])) {
                DB::afterCommit(fn () => rescue(fn (): int => $this->assignAutomatically->distributeAllQueues()));
            }

            return $changes;
        });
    }
}

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

            // Turning the control of pending conversations off, or giving it
            // more tolerance, may lift blocks: the queues are offered again.
            $block = $changes['operations.pending_block_enabled'] ?? null;
            $tolerance = $changes['operations.pending_block_after_minutes'] ?? null;
            $blockLifted = ($block !== null && $block['new'] === false)
                || ($tolerance !== null && is_int($tolerance['old']) && is_int($tolerance['new']) && $tolerance['new'] > $tolerance['old']);

            // A queue rule of the platform (confirmation, minimum wait) decides
            // which requests can be taken: the ones it stopped holding are
            // offered right away instead of waiting for the next scheduled pass.
            if (($limit !== null && AssignAutomatically::limitMayFreeRoom($limit['old'], $limit['new'])) || $blockLifted || AssignAutomatically::rulesMayFreeRequests($changes)) {
                $this->assignAutomatically->distributeAllQueuesAfterCommit();
            }

            return $changes;
        });
    }
}

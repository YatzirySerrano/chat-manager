<?php

namespace App\Actions\Settings;

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

            return $changes;
        });
    }
}

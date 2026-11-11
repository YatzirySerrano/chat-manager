<?php

namespace App\Actions\Categories;

use App\Actions\Conversations\AssignAutomatically;
use App\Enums\AuditAction;
use App\Models\ConversationCategory;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\ServiceRules;
use Illuminate\Support\Facades\DB;

/**
 * Replaces the service rules the category overrides. A rule left out of
 * `$overrides` goes back to inheriting (from the department of each
 * request, else from the platform). The change applies to the decisions
 * taken from now on and never rewrites history. When a queue rule changes,
 * the automatic queues are offered again after the commit.
 */
class UpdateConversationCategoryRules
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
    public function handle(ConversationCategory $category, array $overrides, User $actor): array
    {
        return DB::transaction(function () use ($category, $overrides, $actor): array {
            $before = $this->rules->overridesOf(ServiceRules::LEVEL_CATEGORY, $category->service_rules);
            $after = $this->rules->overridesOf(ServiceRules::LEVEL_CATEGORY, $overrides);
            $changes = $this->rules->diff($before, $after);

            if ($changes === []) {
                return [];
            }

            $category->service_rules = $after === [] ? null : $after;
            $category->save();

            $this->audit->record(AuditAction::ConversationCategoryRulesUpdated, $category, ['changes' => $changes], $actor);

            $this->rules->flush();

            // Its requests may wait in any department: the automatic queues
            // are offered once the change is committed.
            if (AssignAutomatically::rulesMayFreeRequests($changes)) {
                $this->assignAutomatically->distributeAllQueuesAfterCommit();
            }

            return $changes;
        });
    }
}

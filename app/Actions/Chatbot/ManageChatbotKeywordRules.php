<?php

namespace App\Actions\Chatbot;

use App\Enums\AuditAction;
use App\Models\ChatbotKeywordRule;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;

/**
 * The quick operations on keyword rules from their cards: turning one on or
 * off, changing their order (the first matching rule answers) and deleting
 * one. Each is audited like the full edit (SaveChatbotKeywordRule).
 */
class ManageChatbotKeywordRules
{
    public function __construct(private AuditLogger $audit) {}

    /**
     * @return bool Whether it changed.
     */
    public function setActive(ChatbotKeywordRule $rule, bool $active, User $actor): bool
    {
        return DB::transaction(function () use ($rule, $active, $actor): bool {
            $rule = ChatbotKeywordRule::query()->lockForUpdate()->findOrFail($rule->id);

            if ($rule->is_active === $active) {
                return false;
            }

            $rule->forceFill(['is_active' => $active, 'updated_by_user_id' => $actor->id])->save();
            $this->audit->record(AuditAction::ChatbotRuleUpdated, $rule, ['changes' => ['is_active' => ['old' => ! $active, 'new' => $active]]], $actor);

            return true;
        });
    }

    /**
     * Rules in the given order; rules left out keep their place after them.
     *
     * @param  list<int>  $ids
     * @return bool Whether the order changed.
     */
    public function reorder(array $ids, User $actor): bool
    {
        return DB::transaction(function () use ($ids, $actor): bool {
            $rules = ChatbotKeywordRule::query()->orderBy('position')->orderBy('id')->lockForUpdate()->get(['id', 'name', 'position']);
            $known = $rules->pluck('id')->map(fn ($id): int => (int) $id)->all();
            $ordered = array_values(array_unique(array_merge(array_values(array_intersect($ids, $known)), $known)));
            $before = $rules->pluck('name', 'id');

            if ($ordered === $known) {
                return false;
            }

            foreach ($ordered as $position => $id) {
                ChatbotKeywordRule::query()->whereKey($id)->update(['position' => $position]);
            }

            $this->audit->record(AuditAction::ChatbotRuleUpdated, null, [
                'changes' => ['order' => [
                    'old' => array_values($rules->pluck('name')->all()),
                    'new' => array_map(fn (int $id): string => (string) $before[$id], $ordered),
                ]],
            ], $actor);

            return true;
        });
    }

    public function delete(ChatbotKeywordRule $rule, User $actor): void
    {
        DB::transaction(function () use ($rule, $actor): void {
            $this->audit->record(AuditAction::ChatbotRuleDeleted, $rule, [
                'name' => $rule->name,
                'keywords' => $rule->keywords,
                'reply_message' => $rule->reply_message,
            ], $actor);

            $rule->delete();
        });
    }
}

<?php

namespace App\Actions\Categories;

use App\Enums\AuditAction;
use App\Models\ConversationCategory;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\ServiceRules;
use Illuminate\Support\Facades\DB;

/**
 * Logically deletes a category: it can no longer be chosen and its rules
 * stop applying to the decisions taken from now on, while the conversations
 * that already carry it keep it as history.
 */
class DeleteConversationCategory
{
    public function __construct(
        private AuditLogger $audit,
        private ServiceRules $rules,
    ) {}

    public function handle(ConversationCategory $category, User $actor): ConversationCategory
    {
        return DB::transaction(function () use ($category, $actor): ConversationCategory {
            /** @var ConversationCategory $locked */
            $locked = ConversationCategory::query()->lockForUpdate()->findOrFail($category->id);

            if ($locked->isDeleted()) {
                return $locked;
            }

            $locked->forceFill(['is_active' => false])->save();

            $this->audit->record(AuditAction::ConversationCategoryDeleted, $locked, [
                'conversations_kept' => $locked->conversations()->count(),
            ], $actor);

            $this->rules->flush();

            return $locked;
        });
    }
}

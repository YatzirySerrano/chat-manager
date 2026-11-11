<?php

namespace App\Actions\Categories;

use App\Enums\AuditAction;
use App\Models\ConversationCategory;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\ServiceRules;
use Illuminate\Support\Facades\DB;

/**
 * Restores a logically deleted category: it can be chosen again and its
 * rules apply again to the decisions taken from now on.
 */
class RestoreConversationCategory
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

            if (! $locked->isDeleted()) {
                return $locked;
            }

            $locked->forceFill(['is_active' => true])->save();

            $this->audit->record(AuditAction::ConversationCategoryRestored, $locked, [], $actor);

            $this->rules->flush();

            return $locked;
        });
    }
}

<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\ConversationCategory;
use App\Models\User;

/**
 * Request categories are a global catalog with service rules of their own:
 * consulting and managing it requires the global-only categories.manage.
 * (Other screens offer the active catalog through
 * ConversationCategory::options(), under their own authorization.)
 */
class ConversationCategoryPolicy
{
    public function viewAny(User $actor): bool
    {
        return $actor->holdsPermission(Permission::ManageCategories);
    }

    public function create(User $actor): bool
    {
        return $actor->holdsPermission(Permission::ManageCategories);
    }

    /**
     * A deleted category is read-only until it is restored.
     */
    public function update(User $actor, ConversationCategory $category): bool
    {
        return ! $category->isDeleted() && $actor->holdsPermission(Permission::ManageCategories);
    }

    /**
     * Logical deletion: the category stays on the conversations that carry it.
     */
    public function delete(User $actor, ConversationCategory $category): bool
    {
        return $this->update($actor, $category);
    }

    /**
     * Erasing for good a category that is already deleted
     * (permanent-deletion.categories, reserved to the root authority).
     */
    public function deletePermanently(User $actor, ConversationCategory $category): bool
    {
        return $category->isDeleted()
            && $actor->holdsPermission(Permission::PurgeCategories)
            && $actor->canViewDeletedRecords();
    }

    public function restore(User $actor, ConversationCategory $category): bool
    {
        return $category->isDeleted()
            && $actor->holdsPermission(Permission::ManageCategories)
            && $actor->canViewDeletedRecords();
    }
}

<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\Contact;
use App\Models\Tag;
use App\Models\User;

/**
 * Tags are a global classification catalog: consulting it requires
 * tags.view, managing it requires the global-only tags.manage. Applying tags
 * to a contact also requires that the contact is within the actor's scope.
 */
class TagPolicy
{
    public function viewAny(User $actor): bool
    {
        return $actor->holdsPermission(Permission::ViewTags)
            || $actor->holdsPermission(Permission::ManageTags);
    }

    public function create(User $actor): bool
    {
        return $actor->holdsPermission(Permission::ManageTags);
    }

    /**
     * A deleted tag is read-only until it is restored.
     */
    public function update(User $actor, Tag $tag): bool
    {
        return ! $tag->isDeleted() && $actor->holdsPermission(Permission::ManageTags);
    }

    /**
     * Logical deletion: the tag stays where it was applied.
     */
    public function delete(User $actor, Tag $tag): bool
    {
        return $this->update($actor, $tag);
    }

    public function restore(User $actor, Tag $tag): bool
    {
        return $tag->isDeleted()
            && $actor->holdsPermission(Permission::ManageTags)
            && $actor->canViewDeletedRecords();
    }

    /**
     * Whether the actor can change the tags of the contact. Visibility of the
     * contact is checked first by the contact policy (outside the scope the
     * contact is reported as not found).
     */
    public function assign(User $actor, Contact $contact): bool
    {
        return $actor->holdsPermission(Permission::AssignTags)
            && Contact::query()->visibleTo($actor)->whereKey($contact->id)->exists();
    }
}

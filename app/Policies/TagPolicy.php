<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\Contact;
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

    public function update(User $actor): bool
    {
        return $actor->holdsPermission(Permission::ManageTags);
    }

    public function changeStatus(User $actor): bool
    {
        return $actor->holdsPermission(Permission::ManageTags);
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

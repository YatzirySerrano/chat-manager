<?php

namespace App\Policies;

use App\Enums\DataScope;
use App\Enums\Permission;
use App\Models\Contact;
use App\Models\User;
use Illuminate\Auth\Access\Response;

/**
 * Each check combines the permission (what) with the contact data scope
 * (which contacts, see Contact::visibleTo). A contact outside the actor's
 * scope is reported as not found, so guessing identifiers reveals nothing;
 * a reachable contact without the permission is forbidden.
 */
class ContactPolicy
{
    /**
     * Possible duplicates compare contacts of any department, so only people
     * who can already edit every contact review them.
     */
    public function reviewDuplicates(User $actor): bool
    {
        return $actor->holdsPermission(Permission::ViewContacts)
            && $actor->holdsPermission(Permission::UpdateContacts)
            && $actor->dataScope() === DataScope::Global;
    }

    public function viewAny(User $actor): bool
    {
        return $actor->holdsPermission(Permission::ViewContacts);
    }

    public function view(User $actor, Contact $contact): Response
    {
        return $this->allowWithinScope($actor, $contact, Permission::ViewContacts);
    }

    public function create(User $actor): bool
    {
        return $actor->holdsPermission(Permission::ViewContacts)
            && $actor->holdsPermission(Permission::CreateContacts);
    }

    public function update(User $actor, Contact $contact): Response
    {
        return $this->allowWithinScope($actor, $contact, Permission::UpdateContacts);
    }

    public function archive(User $actor, Contact $contact): Response
    {
        return $this->allowWithinScope($actor, $contact, Permission::ArchiveContacts);
    }

    /**
     * Read and add private notes.
     */
    public function manageNotes(User $actor, Contact $contact): Response
    {
        return $this->allowWithinScope($actor, $contact, Permission::ManageContactNotes);
    }

    public function classifyOrigin(User $actor, Contact $contact): Response
    {
        return $this->allowWithinScope($actor, $contact, Permission::ClassifyContactOrigin);
    }

    private function allowWithinScope(User $actor, Contact $contact, Permission $permission): Response
    {
        if (! $actor->holdsPermission(Permission::ViewContacts)) {
            return Response::deny();
        }

        if (! $this->reaches($actor, $contact)) {
            return Response::denyAsNotFound();
        }

        return $actor->holdsPermission($permission) ? Response::allow() : Response::deny();
    }

    /**
     * Whether the contact is inside the actor's data scope.
     */
    private function reaches(User $actor, Contact $contact): bool
    {
        return Contact::query()->visibleTo($actor)->whereKey($contact->id)->exists();
    }
}

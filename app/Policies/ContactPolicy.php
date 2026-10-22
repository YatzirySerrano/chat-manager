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

    /**
     * A deleted contact is read-only until it is restored.
     */
    public function update(User $actor, Contact $contact): Response
    {
        return $this->allowOnLiveContact($actor, $contact, Permission::UpdateContacts);
    }

    /**
     * Logical deletion (archiving).
     */
    public function delete(User $actor, Contact $contact): Response
    {
        return $this->allowOnLiveContact($actor, $contact, Permission::DeleteContacts);
    }

    /**
     * Restoring requires seeing deleted records (otherwise the contact is
     * reported as not found) besides the delete permission.
     */
    public function restore(User $actor, Contact $contact): Response
    {
        $response = $this->allowWithinScope($actor, $contact, Permission::DeleteContacts);

        return $response->allowed() && ! $contact->isDeleted() ? Response::deny() : $response;
    }

    /**
     * Read and add private notes.
     */
    public function manageNotes(User $actor, Contact $contact): Response
    {
        return $this->allowWithinScope($actor, $contact, Permission::ManageContactNotes);
    }

    /**
     * Declaring the origin by hand is an exceptional administrative action:
     * besides the permission it requires the organization-wide scope, so
     * agents and supervisors never record origins from their daily work.
     */
    public function classifyOrigin(User $actor, Contact $contact): Response
    {
        $response = $this->allowWithinScope($actor, $contact, Permission::ClassifyContactOrigin);

        if ($response->denied()) {
            return $response;
        }

        return $actor->dataScope() === DataScope::Global
            ? Response::allow()
            : Response::deny('Solo un administrador con alcance global puede registrar la procedencia declarada.');
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

    private function allowOnLiveContact(User $actor, Contact $contact, Permission $permission): Response
    {
        $response = $this->allowWithinScope($actor, $contact, $permission);

        return $response->allowed() && $contact->isDeleted() ? Response::deny() : $response;
    }

    /**
     * Whether the contact is inside the actor's data scope. Deleted contacts
     * are outside it for people who cannot consult deleted records, so their
     * identifiers reveal nothing.
     */
    private function reaches(User $actor, Contact $contact): bool
    {
        if ($contact->isDeleted() && ! $actor->canViewDeletedRecords()) {
            return false;
        }

        if ($actor->dataScope() === DataScope::Global) {
            return true;
        }

        return Contact::query()->visibleTo($actor)->whereKey($contact->id)->exists();
    }
}

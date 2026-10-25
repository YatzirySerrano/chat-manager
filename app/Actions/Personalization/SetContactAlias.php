<?php

namespace App\Actions\Personalization;

use App\Models\Contact;
use App\Models\ContactAlias;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use LogicException;

/**
 * Sets or removes the private alias a user gives a contact. Only the
 * contact_aliases row of that user changes: the contact's name, phone,
 * identities and message recipient stay exactly as they are, and nothing is
 * merged. An empty alias removes it. A double submit racing on the
 * unique (user, contact) key ends as an update, never an error. Authorization happens before (the
 * user must be able to see the conversation or, as a supervisor, the owner).
 */
class SetContactAlias
{
    public const MAX_LENGTH = 80;

    public function handle(User $owner, Contact $contact, ?string $alias): ?ContactAlias
    {
        $alias = $alias === null ? '' : trim($alias);

        if ($alias === '') {
            ContactAlias::query()->where('user_id', $owner->id)->where('contact_id', $contact->id)->delete();

            return null;
        }

        $existing = $this->find($owner, $contact);
        $record = $existing ?? (new ContactAlias)->forceFill(['user_id' => $owner->id, 'contact_id' => $contact->id]);
        $record->alias = $alias;

        try {
            $record->save();
        } catch (UniqueConstraintViolationException) {
            // A concurrent request (double submit) created the row between
            // the lookup and the insert: the last write wins as an update.
            $record = $this->find($owner, $contact) ?? throw new LogicException('The alias row vanished after a unique violation.');
            $record->alias = $alias;
            $record->save();
        }

        return $record;
    }

    private function find(User $owner, Contact $contact): ?ContactAlias
    {
        return ContactAlias::query()->where('user_id', $owner->id)->where('contact_id', $contact->id)->first();
    }
}

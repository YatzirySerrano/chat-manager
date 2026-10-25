<?php

namespace App\Actions\Contacts;

use App\Enums\AuditAction;
use App\Events\Conversations\ConversationChanged;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Saves a provisional contact (an unknown sender registered automatically
 * from an inbound message) into the shared directory. From then on it is
 * listed in Contactos, counted by the contacts and tags reports and exported.
 *
 * The name and email typed while saving update the official record (the same
 * fields and audit as UpdateContact); the phone and the technical identities
 * never change, so receiving and replying keep working exactly as before.
 * A private alias (visible only to its author) is a different thing and is
 * never touched here.
 *
 * The contact row is locked and the actor authorized again under the lock,
 * so two people saving at once save it once.
 */
class SaveContactToDirectory
{
    public function __construct(
        private AuditLogger $audit,
        private UpdateContact $updateContact,
        private AddContactNote $addNote,
    ) {}

    /**
     * @param  array{name: string, email: string|null, note: string|null}  $data
     *
     * @throws ValidationException When the contact is already saved.
     */
    public function handle(Contact $contact, array $data, User $actor, ?Conversation $conversation = null, bool $confirmedDuplicate = false): Contact
    {
        return DB::transaction(function () use ($contact, $data, $actor, $conversation, $confirmedDuplicate): Contact {
            $contact = Contact::query()->lockForUpdate()->findOrFail($contact->id);

            Gate::forUser($actor)->authorize('saveToDirectory', $contact);

            if ($contact->isSaved()) {
                throw ValidationException::withMessages([
                    'contact' => 'Este contacto ya está guardado en el directorio.',
                ]);
            }

            $changed = $this->updateContact->handle($contact, [
                'name' => $data['name'],
                'phone_e164' => $contact->phone_e164,
                'email' => $data['email'],
                'custom_fields' => $contact->custom_fields,
            ], $actor, $confirmedDuplicate);

            $this->markSaved($contact, $actor, array_filter([
                'via' => $conversation === null ? 'contact' : 'conversation',
                'conversation_id' => $conversation?->id,
                'details_changed' => $changed ?: null,
            ], fn (mixed $value): bool => $value !== null));

            if ($data['note'] !== null && $data['note'] !== '') {
                $this->addNote->handle($contact, $data['note'], $actor);
            }

            return $contact;
        });
    }

    /**
     * Save a provisional contact without touching its data (used by the
     * contacts importer when a row matches it). Saved contacts are left as
     * they are.
     *
     * @return bool Whether the contact was provisional and is saved now.
     */
    public function promote(Contact $contact, User $actor, string $via): bool
    {
        return DB::transaction(function () use ($contact, $actor, $via): bool {
            $locked = Contact::query()->lockForUpdate()->findOrFail($contact->id);

            if ($locked->isSaved()) {
                return false;
            }

            $this->markSaved($locked, $actor, ['via' => $via]);
            $contact->setRawAttributes($locked->getAttributes(), true);

            return true;
        });
    }

    /**
     * @param  array<string, mixed>  $properties
     */
    private function markSaved(Contact $contact, User $actor, array $properties): void
    {
        $contact->forceFill([
            'saved_at' => now(),
            'saved_by_user_id' => $actor->id,
        ])->save();

        $this->audit->record(AuditAction::ContactSaved, $contact, $properties, $actor);

        // Open conversations of the contact show its saved state at once.
        $contact->conversations()
            ->notClosed()
            ->pluck('id')
            ->each(fn (mixed $id) => ConversationChanged::dispatch((int) $id, 'contact', $actor->id));
    }
}

<?php

namespace App\Services\Migration\History;

use App\Enums\AuditAction;
use App\Enums\ContactSource;
use App\Enums\ContactStatus;
use App\Enums\HistoryImportDecision;
use App\Models\Contact;
use App\Models\DataTransfer;
use App\Models\HistoryImportConversation;
use App\Models\HistoryImportSourceKey;
use App\Models\User;
use App\Services\AuditLogger;
use App\Support\PhoneNumber;
use Carbon\CarbonInterface;

/**
 * The contact of an imported conversation. Contacts are never merged by
 * name: a conversation is linked to the contact this Whaticket contact was
 * linked to before (import_source_keys), else to the only active contact
 * with the same normalized number (PhoneNumber: "521" becomes "52"), else
 * a new provisional contact (outside the directory, saved_at NULL) is
 * created, unless the admin chose to save it. No ContactIdentity is ever
 * created and no wa_id / Meta id is touched: the first real message of the
 * customer links the identity through the normal inbound flow. Several
 * active contacts with the number, or only a deleted one, stop the
 * conversation with a reason instead of guessing.
 */
class HistoryContactResolver
{
    public function __construct(private AuditLogger $audit) {}

    /**
     * @return array{contact: Contact, created: bool, saved: bool}
     *
     * @throws HistoryImportRowFailed With a reason for the admin when the contact is ambiguous.
     */
    public function resolve(DataTransfer $batch, HistoryImportConversation $row, User $actor): array
    {
        $instance = HistoryImportBatches::sourceInstance($batch);
        $sourceId = $row->is_group ? null : $this->sourceId($row);
        $contact = $sourceId === null ? null : $this->fromSourceKey($instance, $sourceId);
        $created = false;

        if ($contact === null && $row->contact_phone !== null && ! $row->is_group) {
            $contact = $this->byPhone($row->contact_phone);
        }

        if ($contact === null) {
            $contact = new Contact([
                'name' => $this->nameFor($row),
                'phone_e164' => $row->is_group ? null : $row->contact_phone,
            ]);
            $operational = $row->decision === HistoryImportDecision::Import;
            $contact->forceFill([
                'status' => ContactStatus::Active,
                'source' => ContactSource::Import,
                'created_by_user_id' => $actor->id,
                'saved_at' => null,
                'saved_by_user_id' => null,
                'first_interaction_at' => $operational ? $row->first_message_at : null,
                'last_interaction_at' => $operational ? $row->last_message_at : null,
            ])->save();
            $created = true;
        } elseif ($row->decision === HistoryImportDecision::Import) {
            // Interaction dates only move outwards; archived history never touches them.
            $contact->forceFill([
                'first_interaction_at' => $this->earliest($contact->first_interaction_at, $row->first_message_at),
                'last_interaction_at' => $this->latest($contact->last_interaction_at, $row->last_message_at),
            ]);

            if ($contact->isDirty()) {
                $contact->save();
            }
        }

        if ($sourceId !== null) {
            HistoryImportSourceKey::query()->insertOrIgnore([[
                'source_system' => HistoryImportSourceKey::SYSTEM_WHATICKET,
                'source_instance' => $instance,
                'source_kind' => HistoryImportSourceKey::KIND_CONTACT,
                'source_id' => $sourceId,
                'target_type' => $contact->getMorphClass(),
                'target_id' => $contact->id,
                'data_transfer_id' => $batch->id,
                // Retention: no names or numbers kept with the key (the source id is the normalized number already).
                'details' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]]);
        }

        $saved = $row->save_contact && ! $contact->isSaved() && $this->save($contact, $actor);

        return ['contact' => $contact, 'created' => $created, 'saved' => $saved];
    }

    /**
     * Save the contact to the directory (the admin chose it) without any
     * event: an import never touches live conversations, not even to
     * refresh how they show the contact.
     */
    private function save(Contact $contact, User $actor): bool
    {
        $contact->forceFill(['saved_at' => now(), 'saved_by_user_id' => $actor->id])->save();
        $this->audit->record(AuditAction::ContactSaved, $contact, ['via' => 'history_import'], $actor);

        return true;
    }

    /**
     * Identifier of the Whaticket contact: its normalized number, else the
     * identifier as written (lowercase).
     */
    private function sourceId(HistoryImportConversation $row): ?string
    {
        if ($row->contact_phone !== null) {
            return ltrim($row->contact_phone, '+');
        }

        return $row->contact_identifier === null ? null : mb_substr(mb_strtolower($row->contact_identifier), 0, 128);
    }

    private function fromSourceKey(string $instance, string $sourceId): ?Contact
    {
        $key = HistoryImportSourceKey::query()
            ->where('source_system', HistoryImportSourceKey::SYSTEM_WHATICKET)
            ->where('source_instance', $instance)
            ->where('source_kind', HistoryImportSourceKey::KIND_CONTACT)
            ->where('source_id', $sourceId)
            ->first();

        $contact = $key === null ? null : Contact::query()->find($key->target_id);

        return $contact !== null && ! $contact->isDeleted() ? $contact : null;
    }

    /**
     * @throws HistoryImportRowFailed
     */
    private function byPhone(string $phone): ?Contact
    {
        $matches = Contact::query()->where('phone_e164', $phone)->orderBy('id')->get();
        $active = $matches->reject(fn (Contact $contact): bool => $contact->isDeleted());

        if ($active->count() === 1) {
            return $active->first();
        }

        if ($active->count() > 1) {
            $saved = $active->filter(fn (Contact $contact): bool => $contact->isSaved());

            if ($saved->count() === 1) {
                return $saved->first();
            }

            throw new HistoryImportRowFailed('Hay varios contactos con el número de esta conversación; únelos o corrígelos antes de importarla.');
        }

        if ($matches->isNotEmpty()) {
            throw new HistoryImportRowFailed('El contacto con el número de esta conversación está eliminado. Restáuralo o marca esta conversación como «No importar».');
        }

        return null;
    }

    private function nameFor(HistoryImportConversation $row): string
    {
        $name = $row->contact_name ?? PhoneNumber::format($row->contact_phone) ?? $row->contact_identifier;

        return mb_substr($name ?? ($row->is_group ? 'Grupo de WhatsApp (Whaticket)' : 'Contacto de Whaticket'), 0, 160);
    }

    private function earliest(?CarbonInterface $current, ?CarbonInterface $candidate): ?CarbonInterface
    {
        return $current === null || ($candidate !== null && $candidate->lessThan($current)) ? ($candidate ?? $current) : $current;
    }

    private function latest(?CarbonInterface $current, ?CarbonInterface $candidate): ?CarbonInterface
    {
        return $current === null || ($candidate !== null && $candidate->greaterThan($current)) ? ($candidate ?? $current) : $current;
    }
}

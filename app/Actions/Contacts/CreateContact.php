<?php

namespace App\Actions\Contacts;

use App\Enums\AuditAction;
use App\Enums\ContactSource;
use App\Enums\ContactStatus;
use App\Models\Contact;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;

class CreateContact
{
    public function __construct(private AuditLogger $audit) {}

    /**
     * Register a contact by hand (or through the contacts importer, which
     * passes ContactSource::Import). Duplicates must be resolved by the caller
     * beforehand (see FindDuplicateContacts); they are never merged here.
     *
     * @param  array{name: string, phone_e164: string|null, email: string|null, custom_fields: array<string, string>|null}  $data
     */
    public function handle(array $data, User $actor, bool $confirmedDuplicate = false, ContactSource $source = ContactSource::Manual): Contact
    {
        return DB::transaction(function () use ($data, $actor, $confirmedDuplicate, $source): Contact {
            $contact = new Contact($data);
            $contact->forceFill([
                'status' => ContactStatus::Active,
                'source' => $source,
                'created_by_user_id' => $actor->id,
            ])->save();

            $this->audit->record(AuditAction::ContactCreated, $contact, array_filter([
                'source' => $source->value,
                'has_phone' => $contact->phone_e164 !== null,
                'has_email' => $contact->email !== null,
                'confirmed_duplicate' => $confirmedDuplicate ?: null,
            ], fn (mixed $value): bool => $value !== null), $actor);

            return $contact;
        });
    }
}

<?php

namespace App\Actions\Contacts;

use App\Enums\AuditAction;
use App\Enums\ContactStatus;
use App\Models\Contact;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;

/**
 * Logically deletes a contact by archiving it. Its conversations, notes,
 * attributions and identities are kept: if the person writes again, the
 * same contact receives the message (no duplicate is created).
 */
class DeleteContact
{
    public function __construct(private AuditLogger $audit) {}

    public function handle(Contact $contact, User $actor): Contact
    {
        return DB::transaction(function () use ($contact, $actor): Contact {
            /** @var Contact $locked */
            $locked = Contact::query()->lockForUpdate()->findOrFail($contact->id);

            if ($locked->isDeleted()) {
                return $locked;
            }

            $locked->forceFill(['status' => ContactStatus::Archived, 'archived_at' => now()])->save();

            $this->audit->record(AuditAction::ContactDeleted, $locked, [], $actor);

            return $locked;
        });
    }
}

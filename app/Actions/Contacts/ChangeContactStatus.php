<?php

namespace App\Actions\Contacts;

use App\Enums\AuditAction;
use App\Enums\ContactStatus;
use App\Models\Contact;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;

class ChangeContactStatus
{
    public function __construct(private AuditLogger $audit) {}

    /**
     * Archive or restore a contact. Its history (conversations, notes,
     * attributions) is always kept.
     */
    public function handle(Contact $contact, ContactStatus $status, User $actor): Contact
    {
        if ($contact->status === $status) {
            return $contact;
        }

        return DB::transaction(function () use ($contact, $status, $actor): Contact {
            $contact->forceFill([
                'status' => $status,
                'archived_at' => $status === ContactStatus::Archived ? now() : null,
            ])->save();

            $this->audit->record(
                $status === ContactStatus::Archived ? AuditAction::ContactArchived : AuditAction::ContactRestored,
                $contact,
                [],
                $actor,
            );

            return $contact;
        });
    }
}

<?php

namespace App\Actions\Contacts;

use App\Enums\AuditAction;
use App\Enums\ContactStatus;
use App\Models\Contact;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;

/**
 * Restores a logically deleted contact with all of its history.
 */
class RestoreContact
{
    public function __construct(private AuditLogger $audit) {}

    public function handle(Contact $contact, User $actor): Contact
    {
        return DB::transaction(function () use ($contact, $actor): Contact {
            /** @var Contact $locked */
            $locked = Contact::query()->lockForUpdate()->findOrFail($contact->id);

            if (! $locked->isDeleted()) {
                return $locked;
            }

            $locked->forceFill(['status' => ContactStatus::Active, 'archived_at' => null])->save();

            $this->audit->record(AuditAction::ContactRestored, $locked, [], $actor);

            return $locked;
        });
    }
}

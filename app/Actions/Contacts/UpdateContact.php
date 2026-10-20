<?php

namespace App\Actions\Contacts;

use App\Enums\AuditAction;
use App\Models\Contact;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;

class UpdateContact
{
    public function __construct(private AuditLogger $audit) {}

    /**
     * @param  array{name: string, phone_e164: string|null, email: string|null, custom_fields: array<string, string>|null}  $data
     */
    public function handle(Contact $contact, array $data, User $actor, bool $confirmedDuplicate = false): Contact
    {
        return DB::transaction(function () use ($contact, $data, $actor, $confirmedDuplicate): Contact {
            $contact->fill($data);

            if (! $contact->isDirty()) {
                return $contact;
            }

            $changes = [];

            foreach (array_keys($contact->getDirty()) as $attribute) {
                $changes[$attribute] = [
                    'old' => $contact->getOriginal($attribute),
                    'new' => $contact->getAttribute($attribute),
                ];
            }

            $contact->save();

            $this->audit->record(AuditAction::ContactUpdated, $contact, array_filter([
                'changes' => $changes,
                'confirmed_duplicate' => $confirmedDuplicate ?: null,
            ], fn (mixed $value): bool => $value !== null), $actor);

            return $contact;
        });
    }
}

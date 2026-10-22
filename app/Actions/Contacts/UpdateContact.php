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
     * The data arrives normalized (E.164 phone, lowercase email, squished
     * text), so a submission that only differs in formatting writes nothing.
     *
     * @param  array{name: string, phone_e164: string|null, email: string|null, custom_fields: array<string, string>|null}  $data
     * @return bool Whether anything changed.
     */
    public function handle(Contact $contact, array $data, User $actor, bool $confirmedDuplicate = false): bool
    {
        return DB::transaction(function () use ($contact, $data, $actor, $confirmedDuplicate): bool {
            $contact->fill($data);

            if (! $contact->isDirty()) {
                return false;
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

            return true;
        });
    }
}

<?php

namespace App\Actions\Contacts;

use App\Enums\AuditAction;
use App\Models\Contact;
use App\Models\ContactNote;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;

class AddContactNote
{
    public function __construct(private AuditLogger $audit) {}

    /**
     * The note body is private: the audit entry only records its length.
     */
    public function handle(Contact $contact, string $body, User $actor): ContactNote
    {
        return DB::transaction(function () use ($contact, $body, $actor): ContactNote {
            $note = $contact->notes()->create([
                'author_user_id' => $actor->id,
                'body' => $body,
            ]);

            $this->audit->record(AuditAction::ContactNoteAdded, $contact, [
                'note_id' => $note->id,
                'length' => mb_strlen($body),
            ], $actor);

            return $note;
        });
    }
}

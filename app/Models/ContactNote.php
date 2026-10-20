<?php

namespace App\Models;

use Database\Factories\ContactNoteFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Private plain-text note about a contact. Only users who can see the contact
 * AND hold the contacts.notes permission may read or add notes.
 *
 * @property int $id
 * @property int $contact_id
 * @property int|null $author_user_id
 * @property string $body
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['contact_id', 'author_user_id', 'body'])]
class ContactNote extends Model
{
    /** @use HasFactory<ContactNoteFactory> */
    use HasFactory;

    /**
     * @return BelongsTo<Contact, $this>
     */
    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_user_id');
    }
}

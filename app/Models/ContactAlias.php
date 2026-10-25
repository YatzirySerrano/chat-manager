<?php

namespace App\Models;

use Database\Factories\ContactAliasFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Private name a user gives a contact, only ever shown to that user. It is
 * scoped to the contact (it follows the person across conversations and
 * identity links) and never changes the contact's official name, phone,
 * identities or the message recipient. One alias per user and contact.
 *
 * @property int $id
 * @property int $user_id
 * @property int $contact_id
 * @property string $alias Up to 80 characters.
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['alias'])]
class ContactAlias extends Model
{
    /** @use HasFactory<ContactAliasFactory> */
    use HasFactory;

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<Contact, $this>
     */
    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }
}

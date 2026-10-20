<?php

namespace App\Actions\Contacts;

use App\Enums\ContactReviewReason;
use App\Models\Contact;
use App\Models\ContactReviewCase;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * After a contact is saved by hand, send it to administrative review when its
 * phone or email matches contacts the employee cannot see. The employee gets
 * exactly the same answer as when nothing matches, so the form cannot be used
 * to find out whether a phone belongs to another department's customer; an
 * administrator with organization-wide scope decides instead.
 */
class FlagHiddenDuplicates
{
    public function __construct(private OpenContactReview $openReview) {}

    public function handle(Contact $contact, User $actor, ?string $phone, ?string $email): ?ContactReviewCase
    {
        $email = $email === null ? null : mb_strtolower($email);

        if ($phone === null && $email === null) {
            return null;
        }

        $matches = Contact::query()
            ->whereKeyNot($contact->id)
            ->where(fn (Builder $query) => $query
                ->when($phone !== null, fn (Builder $byPhone) => $byPhone->orWhere('phone_e164', $phone))
                ->when($email !== null, fn (Builder $byEmail) => $byEmail->orWhere('email', $email)))
            ->orderBy('id')
            ->limit(50)
            ->pluck('id')
            ->all();

        if ($matches === []) {
            return null;
        }

        $visible = Contact::query()->visibleTo($actor)->whereKey($matches)->pluck('id')->all();
        $hidden = array_values(array_diff($matches, $visible));

        if ($hidden === []) {
            return null;
        }

        return $this->openReview->handle($contact, ContactReviewReason::PossibleDuplicate, $hidden, array_filter([
            'phone' => $phone !== null ?: null,
            'email' => $email !== null ?: null,
            'registered_by_user_id' => $actor->id,
        ]));
    }
}

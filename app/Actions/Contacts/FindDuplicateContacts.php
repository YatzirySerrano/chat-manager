<?php

namespace App\Actions\Contacts;

use App\Models\Contact;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * Looks for other contacts with the same normalized phone or the same email
 * among the contacts the actor can see. Contacts outside the actor's scope
 * are never considered, so the check cannot be used to learn whether a phone
 * or email exists elsewhere in the company. Email matching is
 * case-insensitive: emails are stored lowercased and the column uses a
 * case-insensitive collation (so its index is used). Contacts are never
 * merged. Deleted contacts are only shown to people who can consult deleted
 * records; for everybody else they count as hidden duplicates (see
 * FlagHiddenDuplicates).
 */
class FindDuplicateContacts
{
    /**
     * @return list<array{id: int, name: string, phone_e164: string|null, email: string|null, status: string, matches: list<'phone'|'email'>}>
     */
    public function handle(?string $phone, ?string $email, User $actor, ?Contact $ignore = null): array
    {
        $email = $email === null ? null : mb_strtolower($email);

        if ($phone === null && $email === null) {
            return [];
        }

        $matches = Contact::query()
            ->visibleTo($actor)
            ->hidingDeletedFrom($actor)
            ->where(fn (Builder $query) => $query
                ->when($phone !== null, fn (Builder $byPhone) => $byPhone->orWhere('phone_e164', $phone))
                ->when($email !== null, fn (Builder $byEmail) => $byEmail->orWhere('email', $email)))
            ->when($ignore !== null, fn (Builder $query) => $query->whereKeyNot($ignore->id))
            ->orderBy('id')
            ->limit(50)
            ->get(['id', 'name', 'phone_e164', 'email', 'status']);

        $duplicates = [];

        foreach ($matches as $match) {
            $reasons = [];

            if ($phone !== null && $match->phone_e164 === $phone) {
                $reasons[] = 'phone';
            }

            if ($email !== null && $match->email !== null && mb_strtolower($match->email) === $email) {
                $reasons[] = 'email';
            }

            $duplicates[] = [
                'id' => $match->id,
                'name' => $match->name,
                'phone_e164' => $match->phone_e164,
                'email' => $match->email,
                'status' => $match->status->value,
                'matches' => $reasons,
            ];
        }

        return $duplicates;
    }
}

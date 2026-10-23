<?php

namespace App\Actions\Contacts;

use App\Enums\ConnectionChannel;
use App\Models\Contact;
use App\Support\FullTextTerms;
use App\Support\PhoneNumber;
use Illuminate\Database\Eloquent\Builder;

/**
 * How a search box finds contacts, shared by the contacts list and the
 * conversations inbox so both always find the same people. Every branch is
 * index backed:
 *
 * - a phone number (only digits and phone punctuation, at least 3 digits):
 *   the exact normalized E.164 number, the beginning of the number as
 *   typed or with the default country code ("55 1234" → "+52551234…"), and
 *   the beginning of the WhatsApp identity (wa_id);
 * - an email (one word with an "@"): the beginning of the address;
 * - anything else: the name or WhatsApp profile name through the FULLTEXT
 *   index, every word as a word prefix ("gonz ana" finds "Ana González"),
 *   or the beginning of the name when no word has 3 characters ("Al").
 *
 * A match in the middle of a word ("nzál") or at the end of a number (its
 * last digits) is not found: neither can use an index.
 *
 * Callers keep the actor's scope on the outer query; this only narrows it.
 */
class ContactSearch
{
    /**
     * @param  Builder<Contact>  $contacts
     */
    public function constrain(Builder $contacts, string $search): void
    {
        $search = trim($search);
        $prefixes = PhoneNumber::searchPrefixes($search);

        if ($prefixes !== []) {
            // "5215512345678" (a wa_id) has no "+" but is international.
            $exact = PhoneNumber::normalize($search) ?? PhoneNumber::normalize('+'.$prefixes[0]);

            $contacts->where(function (Builder $match) use ($prefixes, $exact): void {
                if ($exact !== null) {
                    $match->orWhere('contacts.phone_e164', $exact);
                }

                foreach ($prefixes as $prefix) {
                    $match->orWhere('contacts.phone_e164', 'like', "+{$prefix}%");
                }

                $match->orWhereHas('identities', fn (Builder $identities) => $identities
                    ->where('channel', ConnectionChannel::Whatsapp)
                    ->where(function (Builder $byIdentity) use ($prefixes): void {
                        foreach ($prefixes as $prefix) {
                            $byIdentity->orWhere('external_id', 'like', "{$prefix}%");
                        }
                    }));
            });

            return;
        }

        $startsWith = addcslashes($search, '\\%_').'%';

        if (preg_match('/^[^\s@]*@[^\s@]*$/u', $search) === 1) {
            $contacts->where('contacts.email', 'like', $startsWith);

            return;
        }

        $words = FullTextTerms::booleanPrefixQuery($search);

        if ($words !== null) {
            $contacts->whereFullText(['contacts.name', 'contacts.profile_name'], $words, ['mode' => 'boolean']);

            return;
        }

        $contacts->where(fn (Builder $match) => $match
            ->where('contacts.name', 'like', $startsWith)
            ->orWhere('contacts.profile_name', 'like', $startsWith));
    }
}

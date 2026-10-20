<?php

namespace App\Services;

use App\Enums\AttributionReliability;
use App\Models\Contact;
use App\Models\ContactAttribution;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Collection;

/**
 * Decides which attribution describes how a contact reached the company.
 *
 * Provider confirmed evidence always wins over a manual declaration; within
 * the same reliability the most recent capture wins. A contact without any
 * attribution has an unidentified origin (it is never assumed "organic").
 */
class ResolveContactOrigin
{
    /**
     * Rank of an attribution row; bound with {@see rankBindings()}.
     */
    private const string BETTER_RANK_SQL = '(CASE WHEN better.reliability = ? THEN ? ELSE ? END)';

    private const string CURRENT_RANK_SQL = '(CASE WHEN current.reliability = ? THEN ? ELSE ? END)';

    /**
     * The attribution currently displayed for the contact, or null when the
     * origin is unidentified.
     */
    public function handle(Contact $contact): ?ContactAttribution
    {
        return $this->best($contact->relationLoaded('attributions')
            ? $contact->attributions
            : $contact->attributions()->get());
    }

    /**
     * @param  Collection<int, ContactAttribution>  $attributions
     */
    public function best(Collection $attributions): ?ContactAttribution
    {
        return $attributions
            ->sort(fn (ContactAttribution $a, ContactAttribution $b): int => [
                $b->reliability->rank(), $b->captured_at->getTimestamp(), $b->id,
            ] <=> [
                $a->reliability->rank(), $a->captured_at->getTimestamp(), $a->id,
            ])
            ->first();
    }

    /**
     * Restrict a contact query to those whose current origin has the given
     * type. With a null type it keeps contacts with an unidentified origin
     * (no attribution at all, or the winning one is "unknown").
     *
     * @param  Builder<Contact>  $query
     */
    public function constrainToOrigin(Builder $query, ?string $originType): void
    {
        $winning = function (QueryBuilder $attributions) use ($originType): void {
            $attributions->from('contact_attributions as current')
                ->whereColumn('current.contact_id', 'contacts.id')
                ->where('current.origin_type', $originType ?? 'unknown')
                ->whereNotExists(fn (QueryBuilder $better) => $better
                    ->from('contact_attributions as better')
                    ->whereColumn('better.contact_id', 'current.contact_id')
                    ->whereRaw(
                        '('.self::BETTER_RANK_SQL.' > '.self::CURRENT_RANK_SQL
                        .' OR ('.self::BETTER_RANK_SQL.' = '.self::CURRENT_RANK_SQL
                        .' AND (better.captured_at > current.captured_at'
                        .' OR (better.captured_at = current.captured_at AND better.id > current.id))))',
                        [...$this->rankBindings(), ...$this->rankBindings(), ...$this->rankBindings(), ...$this->rankBindings()],
                    ));
        };

        if ($originType !== null) {
            $query->whereExists($winning);

            return;
        }

        $query->where(fn (Builder $unidentified) => $unidentified
            ->whereDoesntHave('attributions')
            ->orWhereExists($winning));
    }

    /**
     * Values bound into one rank CASE expression: the confirmed reliability,
     * its rank, and the rank of every other reliability.
     *
     * @return array{string, int, int}
     */
    private function rankBindings(): array
    {
        $confirmed = AttributionReliability::ProviderConfirmed;

        return [$confirmed->value, $confirmed->rank(), AttributionReliability::ManuallyDeclared->rank()];
    }
}

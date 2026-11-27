<?php

namespace App\Support\RequestFields;

/**
 * Tolerant comparison of the words people type in a hurry on WhatsApp:
 * «Desenbolso», «Monto autorisado», «Solisitud de cred», «Reactibacion».
 *
 * Text is compared once normalized (RequestFieldCatalog::normalize(): no
 * markers, emojis, bullets, accents, case nor repeated spaces), then word by
 * word: a word matches a target word when it is the same, has a few typos
 * for the length of the target word (none below five letters, one up to
 * seven, two from eight: «autorizo» ↔ «autoriza», «desembolzo» ↔
 * «desembolso») or, where
 * allowed, is a truncation of four letters or more («cred» → «credito»,
 * «refinanciam» → «refinanciamiento»). Short words («de», «a», «tel»)
 * only match as written, so they never drift into another word.
 *
 * Pure functions: no database, no container.
 */
final class FuzzyText
{
    /** Shortest word that may have a typo. */
    private const MIN_TYPO_LENGTH = 5;

    /** Length from which a word may have two typos. */
    private const TWO_TYPOS_LENGTH = 8;

    /** Shortest truncation accepted («cred», «cant»). */
    private const MIN_PREFIX_LENGTH = 4;

    /**
     * Typos tolerated in a word of this length.
     */
    public static function tolerance(int $length): int
    {
        return match (true) {
            $length < self::MIN_TYPO_LENGTH => 0,
            $length < self::TWO_TYPOS_LENGTH => 1,
            default => 2,
        };
    }

    /**
     * How far a written word is from a target word (both normalized): 0 the
     * same, the number of typos, 1 for a truncation (with $prefixes), or
     * null when it is another word.
     */
    public static function wordDistance(string $written, string $target, bool $prefixes = false): ?int
    {
        if ($written === $target) {
            return 0;
        }

        if ($written === '' || $target === '') {
            return null;
        }

        if ($prefixes && strlen($written) >= self::MIN_PREFIX_LENGTH && strlen($written) < strlen($target) && str_starts_with($target, $written)) {
            return 1;
        }

        $tolerance = self::tolerance(strlen($target));

        if ($tolerance === 0 || abs(strlen($written) - strlen($target)) > $tolerance) {
            return null;
        }

        $distance = levenshtein($written, $target);

        return $distance <= $tolerance ? $distance : null;
    }

    /**
     * How far a written phrase is from a target phrase (both normalized),
     * word by word: the sum of the word distances, or null when they do
     * not have the same words.
     */
    public static function phraseDistance(string $written, string $target, bool $prefixes = false): ?int
    {
        if ($written === $target) {
            return 0;
        }

        $writtenWords = self::words($written);
        $targetWords = self::words($target);

        if ($writtenWords === [] || count($writtenWords) !== count($targetWords)) {
            return null;
        }

        $total = 0;

        foreach ($writtenWords as $index => $word) {
            $distance = self::wordDistance($word, $targetWords[$index], $prefixes);

            if ($distance === null) {
                return null;
            }

            $total += $distance;
        }

        return $total;
    }

    /**
     * The normalized text with every word that reads as one of the
     * vocabulary replaced by it («se autorisa reactibacion de cred» →
     * «se autoriza reactivacion de credito»). Explicit aliases (written
     * word => vocabulary word) win; then the closest word, the first one
     * listed on a tie. Words that read as none are kept as written.
     *
     * @param  list<string>  $vocabulary
     * @param  array<string, string>  $aliases
     */
    public static function canonical(string $normalized, array $vocabulary, array $aliases = []): string
    {
        $words = [];

        foreach (self::words($normalized) as $word) {
            $words[] = $aliases[$word] ?? self::closest($word, $vocabulary) ?? $word;
        }

        return implode(' ', $words);
    }

    /**
     * The vocabulary word the written word reads as, or null.
     *
     * @param  list<string>  $vocabulary
     */
    public static function closest(string $word, array $vocabulary): ?string
    {
        if (in_array($word, $vocabulary, true)) {
            return $word;
        }

        $best = null;
        $bestDistance = PHP_INT_MAX;

        foreach ($vocabulary as $candidate) {
            $distance = self::wordDistance($word, $candidate, prefixes: true);

            if ($distance !== null && $distance < $bestDistance) {
                $best = $candidate;
                $bestDistance = $distance;
            }
        }

        return $best;
    }

    /**
     * @return list<string>
     */
    public static function words(string $normalized): array
    {
        return $normalized === '' ? [] : explode(' ', $normalized);
    }
}

<?php

namespace App\Support;

/**
 * Turns what a person types in a search box into a MariaDB InnoDB FULLTEXT
 * query in boolean mode where every word must appear, as a word or as the
 * beginning of one ("gonz" finds "González"; the utf8mb4 "_ci" collations
 * ignore case and accents).
 *
 * InnoDB indexes words of at least innodb_ft_min_token_size (3) characters
 * and skips its default stopwords, so shorter words and stopwords are left
 * out of the query: they would make every search fail. Operators typed by
 * the person (+ - < > ( ) ~ * " @) are never passed through, since only
 * letters and digits survive.
 */
class FullTextTerms
{
    /** Shortest word InnoDB indexes (innodb_ft_min_token_size). */
    public const MIN_WORD_LENGTH = 3;

    /** Words used at most, so a pasted paragraph does not become a huge query. */
    private const MAX_WORDS = 8;

    /**
     * InnoDB default stopwords (INFORMATION_SCHEMA.INNODB_FT_DEFAULT_STOPWORD)
     * long enough to be indexed otherwise.
     *
     * @var list<string>
     */
    private const STOPWORDS = ['about', 'are', 'com', 'for', 'from', 'how', 'that', 'the', 'this', 'was', 'what', 'when', 'where', 'who', 'will', 'with', 'und', 'www'];

    /**
     * The boolean mode query ("+gonz* +ana*"), or null when the term has no
     * word the index can find.
     */
    public static function booleanPrefixQuery(string $term): ?string
    {
        $words = array_filter(
            preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($term), -1, PREG_SPLIT_NO_EMPTY) ?: [],
            fn (string $word): bool => mb_strlen($word) >= self::MIN_WORD_LENGTH && ! in_array($word, self::STOPWORDS, true),
        );

        $words = array_slice(array_values(array_unique($words)), 0, self::MAX_WORDS);

        return $words === [] ? null : implode(' ', array_map(fn (string $word): string => "+{$word}*", $words));
    }
}

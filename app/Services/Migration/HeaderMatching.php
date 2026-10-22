<?php

namespace App\Services\Migration;

use App\Services\Imports\ContactImportAnalyzer;
use Illuminate\Support\Str;

/**
 * Header comparison shared by the source adapters: lowercase, without
 * accents, collapsed spaces and without decoration (":", "*").
 *
 * @phpstan-import-type ImportMapping from ContactImportAnalyzer
 */
trait HeaderMatching
{
    public static function normalizeHeader(string $header): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', mb_strtolower(Str::ascii($header))), " :*\t");
    }

    /**
     * First unused column whose header is one of the hints of each field.
     *
     * @param  list<string>  $headers
     * @param  array<string, list<string>>  $hints
     * @return ImportMapping
     */
    protected function mappingFromHints(array $headers, array $hints): array
    {
        $mapping = array_fill_keys(array_keys(ContactImportAnalyzer::FIELDS), null);
        $taken = [];

        foreach ($hints as $field => $names) {
            if (! array_key_exists($field, $mapping)) {
                continue;
            }

            foreach ($headers as $index => $header) {
                if (! in_array($index, $taken, true) && in_array(self::normalizeHeader($header), $names, true)) {
                    $mapping[$field] = $index;
                    $taken[] = $index;

                    break;
                }
            }
        }

        return $mapping;
    }

    /**
     * Index of the first header equal to one of the names, if any.
     *
     * @param  list<string>  $headers
     * @param  list<string>  $names
     */
    protected function columnNamed(array $headers, array $names): ?int
    {
        foreach ($headers as $index => $header) {
            if (in_array(self::normalizeHeader($header), $names, true)) {
                return $index;
            }
        }

        return null;
    }
}

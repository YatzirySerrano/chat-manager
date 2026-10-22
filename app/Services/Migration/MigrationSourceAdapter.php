<?php

namespace App\Services\Migration;

use App\Services\Imports\ContactImportAnalyzer;

/**
 * Describes where an import file comes from and how its columns map to
 * contact fields. An adapter never reads the database of another system,
 * never runs SQL and never imports anything but the contact fields of
 * ContactImportAnalyzer::FIELDS: it only suggests a mapping (which the user
 * can always change), scores how much a file looks like its source and
 * normalizes the mapped values before the importer validates them with the
 * contact rules.
 *
 * @phpstan-import-type ImportMapping from ContactImportAnalyzer
 *
 * @phpstan-type SourceDetection array{score: int, matched: list<string>, signals: list<string>}
 * @phpstan-type NormalizedRow array{values: array<string, string>, warnings: list<string>, errors: list<string>}
 */
interface MigrationSourceAdapter
{
    /**
     * Stable identifier stored with the import (options.source).
     */
    public function key(): string;

    public function label(): string;

    public function description(): string;

    /**
     * Whether the expected layout was checked against real files of the
     * source. When false the adapter is a heuristic and the UI labels it
     * "pendiente de validación".
     */
    public function isValidated(): bool;

    /**
     * What is known and what still has to be checked about the source format.
     */
    public function validationNote(): ?string;

    /**
     * Header names (lowercase, without accents) suggesting each field.
     *
     * @return array<string, list<string>>
     */
    public function expectedColumns(): array;

    /**
     * How much the headers look like this source (0 to 100), the fields it
     * recognized and the extra signals found. A heuristic, never a proof.
     *
     * @param  list<string>  $headers
     * @return SourceDetection
     */
    public function detect(array $headers): array;

    /**
     * Suggested column per field.
     *
     * @param  list<string>  $headers
     * @return ImportMapping
     */
    public function suggestMapping(array $headers): array;

    /**
     * Normalize the mapped values of a row (field => raw text) before
     * validation. The original cells and headers are given for source
     * specific signals (e.g. a column telling a row is a group chat).
     *
     * @param  array<string, string>  $values
     * @param  list<string>  $cells
     * @param  list<string>  $headers
     * @return NormalizedRow
     */
    public function normalizeRow(array $values, array $cells, array $headers): array;
}

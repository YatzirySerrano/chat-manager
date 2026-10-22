<?php

namespace App\Services\Migration;

use App\Services\Imports\ContactImportAnalyzer;

/**
 * Any spreadsheet of contacts (the importer template or a file prepared by
 * hand): the mapping is suggested from common Spanish and English header
 * names and the values are validated as they are. This is the behaviour the
 * contacts importer always had.
 */
class GenericContactsAdapter implements MigrationSourceAdapter
{
    use HeaderMatching;

    public const KEY = 'generic';

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return 'Archivo de contactos (genérico)';
    }

    public function description(): string
    {
        return 'Cualquier Excel o CSV con una fila de encabezados, como la plantilla de Chat Manager. Tú asignas qué columna es cada campo.';
    }

    public function isValidated(): bool
    {
        return true;
    }

    public function validationNote(): ?string
    {
        return null;
    }

    public function expectedColumns(): array
    {
        return ContactImportAnalyzer::HEADER_HINTS;
    }

    public function detect(array $headers): array
    {
        $matched = array_keys(array_filter($this->suggestMapping($headers), fn (?int $column): bool => $column !== null));

        return ['score' => min(50, 10 * count($matched)), 'matched' => $matched, 'signals' => []];
    }

    public function suggestMapping(array $headers): array
    {
        return $this->mappingFromHints($headers, $this->expectedColumns());
    }

    public function normalizeRow(array $values, array $cells, array $headers): array
    {
        return ['values' => $values, 'warnings' => [], 'errors' => []];
    }
}

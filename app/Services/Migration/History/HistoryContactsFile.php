<?php

namespace App\Services\Migration\History;

use App\Models\HistoryImportFile;
use App\Services\Imports\ContactImportAnalyzer;
use App\Services\Migration\WhaticketContactsAdapter;
use Generator;

/**
 * Rows of a Whaticket contacts CSV read through the existing contacts
 * adapter (WhaticketContactsAdapter: strips "@c.us", adds the "+", refuses
 * groups). The history importer only takes names for the numbers of its
 * inventory from it; it never creates contacts from this file.
 */
class HistoryContactsFile
{
    public function __construct(
        private TabularFileReader $reader,
        private WhaticketContactsAdapter $adapter,
        private HistoryValues $values,
    ) {}

    /**
     * Name and E.164 number of every row (null when it has no valid number).
     *
     * @return Generator<int, array{name: string|null, phone: string}|null>
     */
    public function rows(HistoryImportFile $file, string $path): Generator
    {
        $mapping = array_merge(array_fill_keys(array_keys(ContactImportAnalyzer::FIELDS), null), (array) ($file->column_mapping ?? []));
        $headers = array_map('strval', $file->headers ?? []);

        foreach ($this->reader->rows($path, $file->extension) as $line => $cells) {
            $values = [];

            foreach ($mapping as $field => $index) {
                $values[$field] = is_int($index) ? (string) ($cells[$index] ?? '') : '';
            }

            $normalized = $this->adapter->normalizeRow($values, $cells, $headers);
            $phone = $normalized['errors'] === [] ? $this->values->phone($normalized['values']['phone'] ?? null) : null;

            yield $line => $phone === null ? null : ['name' => $this->values->label($normalized['values']['name'] ?? null), 'phone' => $phone];
        }
    }
}

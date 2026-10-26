<?php

namespace App\Services\Migration\History;

use Generator;
use Illuminate\Validation\ValidationException;
use XMLReader;
use ZipArchive;

/**
 * Reads large Whaticket exports row by row without loading them in memory:
 *
 * - CSV is streamed with fgetcsv (UTF-8 with or without BOM, or
 *   Windows-1252 converted per cell; comma, semicolon or tab detected
 *   from the header line). A file that is not plain text (a ZIP renamed to
 *   .csv, UTF-16 with NUL bytes) is refused;
 * - XLSX is checked like any uploaded ZIP (SafeZip: slip, symlinks, bombs),
 *   macro-enabled packages are refused, and the first worksheet is streamed
 *   with XMLReader (formulas never evaluated: "=HYPERLINK(...)" arrives as
 *   that literal text). No external entity or network access (LIBXML_NONET).
 *
 * Rows are yielded with their record number (the header is record 1) as
 * lists of plain strings; blank rows are skipped. Limits come from
 * config/history-import.php.
 */
class TabularFileReader
{
    public function __construct(private SafeZip $zip) {}

    /**
     * Header row and its record number.
     *
     * @return array{line: int, cells: list<string>}
     *
     * @throws ValidationException
     */
    public function header(string $path, string $extension): array
    {
        foreach ($this->records($path, $extension) as $line => $cells) {
            return ['line' => $line, 'cells' => $this->trimHeader($cells)];
        }

        $this->fail('El archivo está vacío.');
    }

    /**
     * Data rows after the header, keyed by record number.
     *
     * @return Generator<int, list<string>>
     *
     * @throws ValidationException
     */
    public function rows(string $path, string $extension): Generator
    {
        $maxRows = max(1, (int) config('history-import.max_rows', 460000));
        $seenHeader = false;
        $count = 0;

        foreach ($this->records($path, $extension) as $line => $cells) {
            if (! $seenHeader) {
                $seenHeader = true;

                continue;
            }

            if (++$count > $maxRows) {
                $this->fail('El archivo tiene más de '.number_format($maxRows).' filas. Exporta el periodo en partes más pequeñas.');
            }

            yield $line => $cells;
        }
    }

    /**
     * Check the content of a CSV before storing it.
     *
     * @throws ValidationException
     */
    public function assertCsv(string $path): void
    {
        $handle = fopen($path, 'rb');
        $sample = $handle === false ? '' : (string) fread($handle, 65536);

        if ($handle !== false) {
            fclose($handle);
        }

        if ($sample === '') {
            $this->fail('El archivo está vacío.');
        }

        if (str_starts_with($sample, "PK\x03\x04") || str_contains($sample, "\0")) {
            $this->fail('El archivo .csv no es texto plano. Si es un libro de Excel, súbelo con extensión .xlsx; si está en UTF-16, guárdalo como CSV UTF-8.');
        }
    }

    /**
     * Check an .xlsx package before reading it.
     *
     * @throws ValidationException
     */
    public function assertXlsx(string $path): void
    {
        $entries = $this->zip->entries($path);
        $names = array_map(fn (array $entry): string => strtolower($entry['name']), $entries);

        foreach ($names as $name) {
            if (str_ends_with($name, 'vbaproject.bin') || str_contains($name, 'activex')) {
                $this->fail('El archivo contiene macros o controles ActiveX y no puede importarse. Guárdalo como libro de Excel (.xlsx) sin macros.');
            }
        }

        if (! in_array('xl/workbook.xml', $names, true) || ! in_array('[content_types].xml', $names, true)) {
            $this->fail('El archivo no es un libro de Excel (.xlsx) válido.');
        }

        $zip = new ZipArchive;

        if ($zip->open($path, ZipArchive::RDONLY) === true) {
            $contentTypes = (string) $zip->getFromName('[Content_Types].xml');
            $zip->close();

            if (stripos($contentTypes, 'macroEnabled') !== false || stripos($contentTypes, 'vbaProject') !== false) {
                $this->fail('El archivo contiene macros y no puede importarse. Guárdalo como libro de Excel (.xlsx) sin macros.');
            }
        }
    }

    /**
     * Every non-blank record, header included.
     *
     * @return Generator<int, list<string>>
     */
    private function records(string $path, string $extension): Generator
    {
        return match (strtolower($extension)) {
            'csv', 'txt' => $this->csvRecords($path),
            'xlsx' => $this->xlsxRecords($path),
            default => $this->fail('Solo se aceptan archivos .csv o .xlsx.'),
        };
    }

    /**
     * @return Generator<int, list<string>>
     */
    private function csvRecords(string $path): Generator
    {
        $this->assertCsv($path);

        $handle = fopen($path, 'rb');

        if ($handle === false) {
            $this->fail('No se pudo leer el archivo .csv.');
        }

        try {
            $sample = (string) fread($handle, 65536);
            $hasBom = str_starts_with($sample, "\xEF\xBB\xBF");
            // A cut multi-byte character at the end of the sample is not an encoding error.
            $isUtf8 = mb_check_encoding(substr($hasBom ? substr($sample, 3) : $sample, 0, max(0, strlen($sample) - 4)), 'UTF-8');
            $firstLine = (string) strtok($hasBom ? substr($sample, 3) : $sample, "\r\n");
            $delimiter = collect([',', ';', "\t"])->sortByDesc(fn (string $candidate): int => substr_count($firstLine, $candidate))->first() ?? ',';
            $maxColumns = max(1, (int) config('history-import.max_columns', 80));

            rewind($handle);

            if ($hasBom) {
                fread($handle, 3);
            }

            $record = 0;

            // Bounded record length: an unterminated quoted field can never pull the whole file into memory.
            $maxRecord = max(4096, (int) config('history-import.max_record_bytes', 1024 * 1024));

            while (($row = fgetcsv($handle, $maxRecord, $delimiter, '"', '')) !== false) {
                $record++;
                $cells = array_map(fn (mixed $value): string => $this->text((string) $value, $isUtf8), array_slice($row, 0, $maxColumns));

                if ($this->isBlank($cells)) {
                    continue;
                }

                yield $record => $cells;
            }
        } finally {
            fclose($handle);
        }
    }

    /**
     * The first worksheet streamed with XMLReader (shared strings read
     * once, rows yielded as they are parsed): memory does not grow with the
     * number of rows and the workbook is never loaded again per chunk.
     * Formulas are returned as their literal text ("=..."), never evaluated.
     *
     * @return Generator<int, list<string>>
     */
    private function xlsxRecords(string $path): Generator
    {
        $this->assertXlsx($path);

        $maxColumns = max(1, (int) config('history-import.max_columns', 80));
        $sheet = $this->firstSheetPath($path);
        $strings = $this->sharedStrings($path);
        $reader = new XMLReader;

        if (! @$reader->open('zip://'.$path.'#'.$sheet, null, LIBXML_NONET | LIBXML_COMPACT)) {
            $this->fail('No se pudo leer el archivo de Excel. Verifica que no esté dañado ni protegido con contraseña.');
        }

        try {
            $line = 0;
            $cells = null;

            while (@$reader->read()) {
                if ($reader->nodeType === XMLReader::ELEMENT && $reader->localName === 'row') {
                    $line = (int) ($reader->getAttribute('r') ?? $line + 1);
                    $cells = [];

                    if ($reader->isEmptyElement) {
                        $cells = null;
                    }

                    continue;
                }

                if ($cells !== null && $reader->nodeType === XMLReader::ELEMENT && $reader->localName === 'c') {
                    $column = $this->columnIndex((string) $reader->getAttribute('r'), count($cells));
                    $value = $this->cellValue($reader, (string) $reader->getAttribute('t'), $strings);

                    if ($column < $maxColumns) {
                        $cells[$column] = $value;
                    }

                    continue;
                }

                if ($cells !== null && $reader->nodeType === XMLReader::END_ELEMENT && $reader->localName === 'row') {
                    $row = $cells === [] ? [] : array_values(array_replace(array_fill(0, max(array_keys($cells)) + 1, ''), $cells));
                    $cells = null;

                    if (! $this->isBlank($row)) {
                        yield $line => $row;
                    }
                }
            }
        } finally {
            $reader->close();
        }
    }

    /**
     * Path inside the package of the first worksheet (workbook order).
     */
    private function firstSheetPath(string $path): string
    {
        $zip = new ZipArchive;

        if ($zip->open($path, ZipArchive::RDONLY) !== true) {
            $this->fail('El archivo no es un libro de Excel (.xlsx) válido.');
        }

        try {
            $workbook = (string) $zip->getFromName('xl/workbook.xml');
            $relations = (string) $zip->getFromName('xl/_rels/workbook.xml.rels');
        } finally {
            $zip->close();
        }

        if (preg_match('/<(?:\w+:)?sheet\b[^>]*\br:id="([^"]+)"/', $workbook, $sheet) === 1
            && preg_match('/<Relationship\b[^>]*\bId="'.preg_quote($sheet[1], '/').'"[^>]*\bTarget="([^"]+)"/', $relations, $target) === 1) {
            $target = ltrim(str_replace('\\', '/', $target[1]), '/');
            $target = str_starts_with($target, 'xl/') ? $target : 'xl/'.$target;

            if (! str_contains($target, '..')) {
                return $target;
            }
        }

        return 'xl/worksheets/sheet1.xml';
    }

    /**
     * @return list<string>
     */
    private function sharedStrings(string $path): array
    {
        $zip = new ZipArchive;
        $exists = false;

        if ($zip->open($path, ZipArchive::RDONLY) === true) {
            $exists = $zip->locateName('xl/sharedStrings.xml') !== false;
            $zip->close();
        }

        if (! $exists) {
            return [];
        }

        $reader = new XMLReader;
        $strings = [];

        if (! @$reader->open('zip://'.$path.'#xl/sharedStrings.xml', null, LIBXML_NONET | LIBXML_COMPACT)) {
            return [];
        }

        try {
            $current = null;

            while (@$reader->read()) {
                if ($reader->nodeType === XMLReader::ELEMENT && $reader->localName === 'si') {
                    $current = '';
                } elseif ($current !== null && $reader->nodeType === XMLReader::ELEMENT && $reader->localName === 't') {
                    $current .= $reader->readString();
                } elseif ($current !== null && $reader->nodeType === XMLReader::ELEMENT && $reader->localName === 'rPh') {
                    $reader->next();
                } elseif ($reader->nodeType === XMLReader::END_ELEMENT && $reader->localName === 'si') {
                    $strings[] = (string) $current;
                    $current = null;
                }
            }
        } finally {
            $reader->close();
        }

        return $strings;
    }

    /**
     * Plain text of one <c> element (the reader is on it).
     *
     * @param  list<string>  $strings
     */
    private function cellValue(XMLReader $reader, string $type, array $strings): string
    {
        if ($reader->isEmptyElement) {
            return '';
        }

        $depth = $reader->depth;
        $value = '';
        $formula = null;
        $inline = '';

        while (@$reader->read() && ! ($reader->nodeType === XMLReader::END_ELEMENT && $reader->localName === 'c' && $reader->depth === $depth)) {
            if ($reader->nodeType !== XMLReader::ELEMENT) {
                continue;
            }

            match ($reader->localName) {
                'v' => $value = $reader->readString(),
                'f' => $formula = $reader->readString(),
                't' => $inline .= $reader->readString(),
                default => null,
            };
        }

        if ($formula !== null && $formula !== '') {
            return '='.$formula;
        }

        return match ($type) {
            's' => $strings[(int) $value] ?? '',
            'inlineStr' => $inline,
            'b' => $value === '1' ? 'true' : 'false',
            default => $this->numberText($value),
        };
    }

    /**
     * Whole numbers (phones typed as numbers) never get a decimal part or
     * scientific notation.
     */
    private function numberText(string $value): string
    {
        if (is_numeric($value) && str_contains(strtolower($value), 'e')) {
            $float = (float) $value;

            return floor($float) === $float && abs($float) < 1e15 ? sprintf('%.0f', $float) : $value;
        }

        return $value;
    }

    /**
     * Zero-based column of a cell reference ("C12" → 2).
     */
    private function columnIndex(string $reference, int $fallback): int
    {
        if (preg_match('/^([A-Z]{1,3})\d+$/', strtoupper($reference), $matches) !== 1) {
            return $fallback;
        }

        $index = 0;

        foreach (str_split($matches[1]) as $letter) {
            $index = $index * 26 + (ord($letter) - 64);
        }

        return $index - 1;
    }

    /**
     * @param  list<string>  $cells
     * @return list<string>
     */
    private function trimHeader(array $cells): array
    {
        while ($cells !== [] && trim((string) end($cells)) === '') {
            array_pop($cells);
        }

        return array_map(function (string $header, int $index): string {
            $header = trim((string) preg_replace('/\s+/u', ' ', $header));

            return $header === '' ? 'Columna '.($index + 1) : mb_substr($header, 0, 80);
        }, $cells, array_keys($cells));
    }

    private function text(string $value, bool $isUtf8): string
    {
        return $isUtf8 || mb_check_encoding($value, 'UTF-8') ? $value : (string) mb_convert_encoding($value, 'UTF-8', 'Windows-1252');
    }

    /**
     * @param  list<string>  $cells
     */
    private function isBlank(array $cells): bool
    {
        return trim(implode('', $cells)) === '';
    }

    /**
     * @throws ValidationException
     */
    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['file' => $message]);
    }
}

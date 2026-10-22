<?php

namespace App\Services\Imports;

use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\Reader\IReadFilter;
use PhpOffice\PhpSpreadsheet\Reader\Xlsx as XlsxReader;
use PhpOffice\PhpSpreadsheet\RichText\RichText;
use Throwable;
use ZipArchive;

/**
 * Reads the first worksheet of an .xlsx file or a .csv file as plain text,
 * defensively:
 *
 * - the content must match the extension (an .xlsx is an OOXML package with
 *   [Content_Types].xml and xl/workbook.xml; a .csv is text without NUL
 *   bytes, UTF-8 or Windows-1252);
 * - macro-enabled workbooks (vbaProject.bin, macroEnabled content types,
 *   ActiveX) are refused;
 * - zip bombs are refused before loading: entries, total uncompressed size
 *   and compression ratio are checked in the central directory;
 * - only the first rows and columns allowed by config/imports.php are read
 *   (PhpSpreadsheet read filter, data only), so memory stays bounded;
 * - formulas are never evaluated: the raw cell content is returned, so
 *   "=HYPERLINK(...)" arrives as that literal text.
 *
 * @phpstan-type ImportSheet array{headers: list<string>, rows: list<array{line: int, cells: list<string>}>}
 */
class ImportFileReader
{
    /**
     * @return ImportSheet
     *
     * @throws ValidationException When the file is unsafe, unreadable or exceeds the limits.
     */
    public function read(string $path, string $extension): array
    {
        $table = match (strtolower($extension)) {
            'xlsx' => $this->readXlsx($path),
            'csv' => $this->readCsv($path),
            default => $this->fail('Solo se aceptan archivos .xlsx o .csv.'),
        };

        return $this->toSheet($table);
    }

    /**
     * @return list<list<string>>
     */
    private function readXlsx(string $path): array
    {
        $this->inspectPackage($path);

        $maxRows = $this->maxRows();
        $maxColumns = $this->maxColumns();

        try {
            $reader = new XlsxReader;
            $reader->setReadDataOnly(true);
            $reader->setReadEmptyCells(false);
            $reader->setIncludeCharts(false);

            $info = $reader->listWorksheetInfo($path)[0] ?? null;

            if ($info === null) {
                $this->fail('El archivo no contiene hojas de cálculo.');
            }

            // Header + allowed rows + a margin for blank rows; anything beyond is too large.
            $rowLimit = $maxRows + 1 + 50;

            if ((int) $info['totalRows'] > $rowLimit) {
                $this->fail('El archivo tiene más de '.number_format($maxRows).' filas. Divide el archivo e impórtalo por partes.');
            }

            $reader->setLoadSheetsOnly([(string) $info['worksheetName']]);
            $reader->setReadFilter(new class($rowLimit, $maxColumns + 1) implements IReadFilter
            {
                public function __construct(private int $rows, private int $columns) {}

                public function readCell(string $columnAddress, int $row, string $worksheetName = ''): bool
                {
                    $index = 0;

                    foreach (str_split(strtoupper($columnAddress)) as $letter) {
                        $index = $index * 26 + (ord($letter) - 64);
                    }

                    return $row <= $this->rows && $index <= $this->columns;
                }
            });

            $workbook = $reader->load($path);
            // Raw values: no formula calculation, no number formatting.
            $raw = $workbook->getSheet(0)->toArray(null, false, false, false);
            $workbook->disconnectWorksheets();
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (Throwable) {
            $this->fail('No se pudo leer el archivo de Excel. Verifica que no esté dañado ni protegido con contraseña.');
        }

        return array_map(fn (array $row): array => array_map(fn (mixed $value): string => $this->cellText($value), array_values($row)), array_values($raw));
    }

    /**
     * Refuse anything that is not a plain, reasonably sized workbook.
     */
    private function inspectPackage(string $path): void
    {
        $zip = new ZipArchive;

        if ($zip->open($path, ZipArchive::RDONLY) !== true) {
            $this->fail('El archivo no es un libro de Excel (.xlsx) válido.');
        }

        try {
            if ($zip->numFiles > (int) config('imports.max_zip_entries', 200)) {
                $this->fail('El archivo de Excel tiene una estructura demasiado grande para importarse.');
            }

            $total = 0;
            $hasWorkbook = false;
            $hasContentTypes = false;

            for ($index = 0; $index < $zip->numFiles; $index++) {
                $entry = $zip->statIndex($index);

                if ($entry === false) {
                    $this->fail('El archivo de Excel está dañado.');
                }

                $name = strtolower((string) $entry['name']);
                $size = (int) $entry['size'];
                $compressed = max(1, (int) $entry['comp_size']);
                $total += $size;

                if (str_ends_with($name, 'vbaproject.bin') || str_contains($name, 'activex')) {
                    $this->fail('El archivo contiene macros o controles ActiveX y no puede importarse. Guárdalo como libro de Excel (.xlsx) sin macros.');
                }

                if ($size > 1024 * 1024 && $size / $compressed > (int) config('imports.max_compression_ratio', 200)) {
                    $this->fail('El archivo de Excel tiene un contenido comprimido sospechoso y no puede importarse.');
                }

                $hasWorkbook = $hasWorkbook || $name === 'xl/workbook.xml';
                $hasContentTypes = $hasContentTypes || $name === '[content_types].xml';
            }

            if ($total > (int) config('imports.max_uncompressed_bytes', 50 * 1024 * 1024)) {
                $this->fail('El contenido del archivo de Excel es demasiado grande. Divide el archivo e impórtalo por partes.');
            }

            if (! $hasWorkbook || ! $hasContentTypes) {
                $this->fail('El archivo no es un libro de Excel (.xlsx) válido.');
            }

            $contentTypes = (string) $zip->getFromName('[Content_Types].xml');

            if (stripos($contentTypes, 'macroEnabled') !== false || stripos($contentTypes, 'vbaProject') !== false) {
                $this->fail('El archivo contiene macros y no puede importarse. Guárdalo como libro de Excel (.xlsx) sin macros.');
            }
        } finally {
            $zip->close();
        }
    }

    /**
     * @return list<list<string>>
     */
    private function readCsv(string $path): array
    {
        $content = (string) file_get_contents($path);

        if (str_starts_with($content, "PK\x03\x04") || str_contains($content, "\0")) {
            $this->fail('El archivo .csv no es texto plano. Si es un libro de Excel, súbelo con extensión .xlsx.');
        }

        if (str_starts_with($content, "\xEF\xBB\xBF")) {
            $content = substr($content, 3);
        }

        if (! mb_check_encoding($content, 'UTF-8')) {
            $content = (string) mb_convert_encoding($content, 'UTF-8', 'Windows-1252');
        }

        $firstLine = strtok($content, "\r\n") ?: '';
        $delimiter = collect([',', ';', "\t"])->sortByDesc(fn (string $candidate): int => substr_count($firstLine, $candidate))->first() ?? ',';

        $stream = fopen('php://temp', 'r+');

        if ($stream === false) {
            $this->fail('No se pudo leer el archivo .csv.');
        }

        fwrite($stream, $content);
        rewind($stream);

        $rows = [];
        $limit = $this->maxRows() + 1 + 50;

        while (($row = fgetcsv($stream, null, $delimiter, '"', '')) !== false) {
            if (count($rows) >= $limit) {
                fclose($stream);
                $this->fail('El archivo tiene más de '.number_format($this->maxRows()).' filas. Divide el archivo e impórtalo por partes.');
            }

            $rows[] = array_map(fn (mixed $value): string => $this->cellText($value), array_slice($row, 0, $this->maxColumns() + 1));
        }

        fclose($stream);

        return $rows;
    }

    /**
     * @param  list<list<string>>  $table
     * @return ImportSheet
     */
    private function toSheet(array $table): array
    {
        $isBlank = fn (array $cells): bool => trim(implode('', $cells)) === '';
        $headerIndex = null;

        foreach ($table as $index => $cells) {
            if (! $isBlank($cells)) {
                $headerIndex = $index;

                break;
            }
        }

        if ($headerIndex === null) {
            $this->fail('El archivo está vacío.');
        }

        $width = 0;

        foreach ($table as $cells) {
            for ($column = count($cells) - 1; $column >= 0; $column--) {
                if (trim($cells[$column]) !== '') {
                    $width = max($width, $column + 1);

                    break;
                }
            }
        }

        if ($width > $this->maxColumns()) {
            $this->fail('El archivo tiene más de '.$this->maxColumns().' columnas. Deja solo las columnas que quieres importar.');
        }

        $headers = [];

        foreach (array_slice(array_pad($table[$headerIndex], $width, ''), 0, $width) as $column => $header) {
            $header = trim((string) preg_replace('/\s+/u', ' ', $header));
            $headers[] = $header === '' ? 'Columna '.($column + 1) : mb_substr($header, 0, 80);
        }

        $rows = [];

        foreach (array_slice($table, $headerIndex + 1, null, true) as $index => $cells) {
            if ($isBlank($cells)) {
                continue;
            }

            $rows[] = ['line' => $index + 1, 'cells' => array_slice(array_pad($cells, $width, ''), 0, $width)];
        }

        if ($rows === []) {
            $this->fail('El archivo no tiene filas con datos debajo de los encabezados.');
        }

        if (count($rows) > $this->maxRows()) {
            $this->fail('El archivo tiene '.number_format(count($rows)).' filas y el máximo es '.number_format($this->maxRows()).'. Divide el archivo e impórtalo por partes.');
        }

        return ['headers' => $headers, 'rows' => $rows];
    }

    /**
     * Plain text of a raw cell value. Whole numbers (phones typed as numbers)
     * never get a decimal part or scientific notation.
     */
    private function cellText(mixed $value): string
    {
        return match (true) {
            $value === null => '',
            $value instanceof RichText => $value->getPlainText(),
            is_bool($value) => $value ? 'VERDADERO' : 'FALSO',
            is_int($value) => (string) $value,
            is_float($value) => floor($value) === $value && abs($value) < 1e15 ? sprintf('%.0f', $value) : (string) $value,
            is_scalar($value) => (string) $value,
            default => '',
        };
    }

    private function maxRows(): int
    {
        return max(1, (int) config('imports.max_rows', 5000));
    }

    private function maxColumns(): int
    {
        return max(1, (int) config('imports.max_columns', 30));
    }

    /**
     * @throws ValidationException
     */
    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['file' => $message]);
    }
}

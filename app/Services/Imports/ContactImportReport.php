<?php

namespace App\Services\Imports;

use App\Models\DataTransfer;
use App\Reports\Rendering\ReportPalette;
use App\Services\PlatformSettings;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use RuntimeException;

/**
 * Workbooks of the contacts importer: the downloadable template and the
 * result report of a processed import. Every value is written as an
 * explicit string, so imported text that looks like a formula is never
 * evaluated when the workbook is opened.
 */
class ContactImportReport
{
    /**
     * Result labels of a processed row.
     *
     * @var array<string, string>
     */
    public const RESULTS = [
        'created' => 'Creado',
        'updated' => 'Completado',
        'skipped' => 'Omitido',
        'failed' => 'Con error',
    ];

    /**
     * Write the result workbook to the private disk.
     *
     * @param  list<array{line: int, result: 'created'|'updated'|'skipped'|'failed', name: string, phone: string|null, email: string|null, contact_id: int|null, reason: string|null}>  $outcomes
     * @param  array{created: int, updated: int, skipped: int, failed: int}  $counts
     * @return string Path on the transfer's disk.
     */
    public function write(DataTransfer $transfer, array $outcomes, array $counts): string
    {
        $spreadsheet = new Spreadsheet;
        $summary = $spreadsheet->getActiveSheet()->setTitle('Resumen');

        $this->rows($summary, ['Indicador', 'Valor'], [
            ['Archivo', (string) ($transfer->options['original_name'] ?? '')],
            ['Filas procesadas', (string) count($outcomes)],
            ['Contactos creados', (string) $counts['created']],
            ['Contactos completados (solo campos vacíos)', (string) $counts['updated']],
            ['Filas omitidas', (string) $counts['skipped']],
            ['Filas con error', (string) $counts['failed']],
            ['Decisión para duplicados', ContactImporter::DECISIONS[$transfer->options['decision'] ?? ContactImporter::DECISION_SKIP] ?? ''],
            ['Procesado el', now($this->timezone())->format('d/m/Y H:i').' ('.$this->timezone().')'],
        ], [46, 60]);

        $detail = new Worksheet($spreadsheet, 'Detalle');
        $spreadsheet->addSheet($detail);
        $this->rows($detail, ['Fila', 'Resultado', 'Nombre', 'Teléfono', 'Correo electrónico', 'Contacto', 'Motivo'], array_map(fn (array $outcome): array => [
            (string) $outcome['line'],
            self::RESULTS[$outcome['result']],
            $outcome['name'],
            (string) $outcome['phone'],
            (string) $outcome['email'],
            $outcome['contact_id'] === null ? '' : '#'.$outcome['contact_id'],
            (string) $outcome['reason'],
        ], $outcomes), [8, 13, 30, 18, 30, 11, 70]);
        $detail->setAutoFilter($detail->calculateWorksheetDimension());

        $spreadsheet->setActiveSheetIndex(0);

        $path = trim((string) config('imports.directory', 'imports'), '/')."/{$transfer->user_id}/resultado-".Str::uuid()->toString().'.xlsx';
        $temporary = tempnam(sys_get_temp_dir(), 'import-report');

        if ($temporary === false) {
            throw new RuntimeException('Could not create a temporary file for the import report.');
        }

        try {
            (new Xlsx($spreadsheet))->save($temporary);
            $spreadsheet->disconnectWorksheets();
            Storage::disk((string) $transfer->disk)->put($path, (string) file_get_contents($temporary));
        } finally {
            @unlink($temporary);
        }

        return $path;
    }

    /**
     * The template offered for download: headers, one example row and an
     * instructions sheet.
     */
    public function template(string $path): void
    {
        $spreadsheet = new Spreadsheet;
        $contacts = $spreadsheet->getActiveSheet()->setTitle('Contactos');
        $this->rows($contacts, array_values(ContactImportAnalyzer::FIELDS), [
            ['María López', '55 1234 5678', 'maria@ejemplo.com', 'Cliente, Mayorista', 'Prefiere que la contacten por la tarde.'],
        ], [28, 18, 30, 28, 50]);

        $instructions = new Worksheet($spreadsheet, 'Instrucciones');
        $spreadsheet->addSheet($instructions);
        $this->rows($instructions, ['Columna', 'Instrucciones'], [
            ['Nombre', 'Nombre del contacto (máximo '.ContactImportAnalyzer::MAX_NAME.' caracteres). Si falta, se usa el teléfono.'],
            ['Teléfono', '10 dígitos para México (ej. 55 1234 5678) o el número completo con lada internacional (ej. +1 415 555 2671).'],
            ['Correo electrónico', 'Correo válido. Cada contacto necesita al menos un teléfono o un correo.'],
            ['Etiquetas', 'Nombres de etiquetas que ya existen y están activas, separados por comas. No se crean etiquetas nuevas.'],
            ['Notas', 'Nota privada que se agrega al contacto (máximo 2000 caracteres).'],
            ['Duplicados', 'Los contactos que ya existen (mismo teléfono o correo) se omiten, o solo se completan sus campos vacíos si así lo eliges. Nunca se sobrescriben datos.'],
            ['Formato', 'Usa la primera hoja, con los encabezados en la primera fila. Máximo '.number_format((int) config('imports.max_rows', 5000)).' filas y '.((int) config('imports.max_kilobytes', 5120) / 1024).' MB. Las fórmulas se importan como texto.'],
            ['No se importa', 'Usuarios, contraseñas, credenciales ni mensajes.'],
        ], [22, 110]);

        $spreadsheet->setActiveSheetIndex(0);
        (new Xlsx($spreadsheet))->save($path);
        $spreadsheet->disconnectWorksheets();
    }

    private function timezone(): string
    {
        return (string) app(PlatformSettings::class)->get('general.timezone', config('app.timezone'));
    }

    /**
     * A header row and plain-text rows.
     *
     * @param  list<string>  $headers
     * @param  list<list<string>>  $rows
     * @param  list<int>  $widths
     */
    private function rows(Worksheet $sheet, array $headers, array $rows, array $widths): void
    {
        foreach ([$headers, ...$rows] as $rowIndex => $values) {
            foreach ($values as $columnIndex => $value) {
                $coordinate = Coordinate::stringFromColumnIndex($columnIndex + 1).($rowIndex + 1);
                $sheet->setCellValueExplicit($coordinate, $value, DataType::TYPE_STRING);

                if ($value !== '' && in_array($value[0], ['=', '+', '-', '@', "\t", "\r"], true)) {
                    $sheet->getStyle($coordinate)->setQuotePrefix(true);
                }
            }
        }

        foreach ($widths as $index => $width) {
            $sheet->getColumnDimension(Coordinate::stringFromColumnIndex($index + 1))->setWidth($width);
        }

        $last = Coordinate::stringFromColumnIndex(count($headers));
        $sheet->getStyle("A1:{$last}1")->applyFromArray([
            'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => ReportPalette::rgb(ReportPalette::ACCENT)]],
        ]);
        $sheet->getStyle("A2:{$last}".(count($rows) + 1))->getAlignment()->setWrapText(true)->setVertical('top');
        $sheet->freezePane('A2');
    }
}

<?php

namespace App\Reports;

use App\Enums\Permission;
use App\Models\User;
use Carbon\CarbonInterface;
use Generator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Contract every exportable module implements once; the engine renders it
 * to XLSX and PDF. A definition owns its authorization, its filters (the
 * same semantics as the module's list screen), its query (scoped exactly
 * like that screen) and the fixed set of columns it exports.
 *
 * Mapped rows only hold scalar values or dates: never passwords, hashes,
 * tokens or secrets.
 *
 * @template TModel of Model
 */
abstract class ReportDefinition
{
    /**
     * Stable identifier used in the URL and the catalog (config/exports.php).
     */
    abstract public function key(): string;

    abstract public function title(): string;

    public function subtitle(): ?string
    {
        return null;
    }

    /**
     * Base of the downloaded file name, e.g. "etiquetas".
     */
    abstract public function fileName(): string;

    public function orientation(): Orientation
    {
        return Orientation::Portrait;
    }

    /**
     * Module authorization (view permission and scope). The engine also
     * requires the generic reports.export permission.
     */
    abstract public function authorize(User $actor): bool;

    /**
     * Generic export permission required on top of authorize(): reports.export
     * for module reports. A report may require a more specific one instead
     * (the audit log requires audit.export).
     */
    public function exportPermission(): Permission
    {
        return Permission::ExportReports;
    }

    /**
     * Authorization of the specific record(s) selected by the validated
     * filters, for reports about one record (e.g. a conversation dossier).
     * A refusal is answered as "not found", like the record's own page.
     *
     * @param  array<string, mixed>  $filters  Validated and normalized filters.
     */
    public function authorizeRecord(User $actor, array $filters): bool
    {
        return true;
    }

    /**
     * Validation rules of the filters accepted from the query string.
     * Unknown parameters are ignored.
     *
     * @return array<string, mixed>
     */
    abstract public function rules(): array;

    /**
     * Normalize validated input into the filters the query understands.
     * The result is stored with queued exports, so it must be JSON-safe.
     *
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    public function normalizeFilters(array $validated): array
    {
        $filters = [];

        foreach ($validated as $key => $value) {
            $value = is_string($value) ? trim($value) : $value;

            if ($value !== null && $value !== '' && $value !== []) {
                $filters[$key] = $value;
            }
        }

        return $filters;
    }

    /**
     * Human readable description of the applied filters, for the report
     * header and the audit log. An empty list means "no filters".
     *
     * @return list<string>
     */
    abstract public function describeFilters(ReportContext $context): array;

    /**
     * Records of the report, scoped exactly like the module's screen and in
     * the order they must be exported.
     *
     * @return Builder<TModel>
     */
    abstract public function query(ReportContext $context): Builder;

    public function count(ReportContext $context): int
    {
        return $this->query($context)->count();
    }

    /**
     * @return list<Column>
     */
    abstract public function columns(): array;

    /**
     * Values of one record keyed by column key.
     *
     * @param  TModel  $record
     * @return array<string, string|int|float|bool|CarbonInterface|null>
     */
    abstract public function map(Model $record, ReportContext $context): array;

    /**
     * @return list<Metric>
     */
    public function metrics(ReportContext $context): array
    {
        return [];
    }

    /**
     * Label/value blocks describing the subject of the report, shown before
     * the metrics.
     *
     * @return list<ReportPanel>
     */
    public function panels(ReportContext $context): array
    {
        return [];
    }

    /**
     * @return list<Chart>
     */
    public function charts(ReportContext $context): array
    {
        return [];
    }

    /**
     * Column whose consecutive equal values form a group in the PDF
     * (a group heading and subtotals). The query must order by it.
     */
    public function groupBy(): ?string
    {
        return null;
    }

    /**
     * Additional worksheets of the workbook.
     *
     * @return list<ReportSheet>
     */
    public function extraSheets(ReportContext $context): array
    {
        return [];
    }

    public function pdfOptions(): PdfOptions
    {
        return new PdfOptions;
    }

    /**
     * Records read from the database per round trip.
     */
    public function chunkSize(): int
    {
        return 500;
    }

    /**
     * Mapped rows, read lazily so large exports never load every record.
     *
     * @return Generator<int, array<string, string|int|float|bool|CarbonInterface|null>>
     */
    final public function rows(ReportContext $context): Generator
    {
        foreach ($this->query($context)->lazy($this->chunkSize()) as $record) {
            yield $this->map($record, $context);
        }
    }
}

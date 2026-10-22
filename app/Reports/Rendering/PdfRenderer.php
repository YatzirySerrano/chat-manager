<?php

namespace App\Reports\Rendering;

use App\Reports\Chart;
use App\Reports\Column;
use App\Reports\ColumnTotal;
use App\Reports\ColumnType;
use App\Reports\Orientation;
use App\Reports\ReportContext;
use App\Reports\ReportDefinition;
use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;
use Spatie\Browsershot\Browsershot;

/**
 * Renders a report definition as an A4 PDF: Blade builds a self-contained
 * HTML document (inline CSS and SVG, no remote resources) and headless
 * Chrome prints it through Browsershot.
 *
 * @phpstan-type TableEntry array{kind: string, cells: list<string>, label?: string}
 */
class PdfRenderer implements ReportRenderer
{
    public function __construct(
        private ViewFactory $view,
        private ChartGeometry $geometry,
    ) {}

    /**
     * @param  ReportDefinition<Model>  $definition
     */
    public function render(ReportDefinition $definition, ReportContext $context, string $path, int $maxRows): int
    {
        $document = $this->document($definition, $context, $maxRows);

        $this->browsershot((string) $document['html'], $definition, $context)->save($path);

        return $document['rows'];
    }

    /**
     * The HTML of the report, without involving Chrome.
     *
     * @param  ReportDefinition<Model>  $definition
     */
    public function html(ReportDefinition $definition, ReportContext $context, int $maxRows = PHP_INT_MAX): string
    {
        return $this->document($definition, $context, $maxRows)['html'];
    }

    /**
     * A configured Browsershot instance for the HTML.
     *
     * @param  ReportDefinition<Model>  $definition
     */
    public function browsershot(string $html, ReportDefinition $definition, ReportContext $context): Browsershot
    {
        $config = (array) config('exports.pdf');

        $browsershot = Browsershot::html($html)
            ->setNodeBinary((string) $config['node_binary'])
            ->setNpmBinary((string) $config['npm_binary'])
            ->setNodeModulePath((string) $config['node_modules_path'])
            ->format('A4')
            ->landscape($definition->orientation() === Orientation::Landscape)
            ->margins(14, 12, 16, 12)
            ->showBackground()
            ->showBrowserHeaderAndFooter()
            ->headerHtml('<span></span>')
            ->footerHtml($this->view->make('reports.partials.page-footer', [
                'platformName' => $context->platformName,
                'title' => $definition->title(),
            ])->render())
            ->timeout((int) $config['timeout']);

        if (filled($config['chrome_path'] ?? null)) {
            $browsershot->setChromePath((string) $config['chrome_path']);
        }

        if ((bool) ($config['no_sandbox'] ?? false)) {
            $browsershot->noSandbox();
        }

        return $browsershot;
    }

    /**
     * @param  ReportDefinition<Model>  $definition
     * @return array{html: string, rows: int}
     */
    private function document(ReportDefinition $definition, ReportContext $context, int $maxRows): array
    {
        $options = $definition->pdfOptions();
        $formatter = new ValueFormatter($context->timezone);
        $columns = $definition->columns();
        $table = $options->includeTable
            ? $this->table($definition, $context, $columns, $formatter, $maxRows)
            : ['entries' => [], 'totals' => null, 'rows' => $definition->count($context)];

        $charts = $options->includeCharts
            ? array_values(array_filter($definition->charts($context), fn (Chart $chart): bool => $chart->hasData()))
            : [];

        $html = $this->view->make('reports.document', [
            'definition' => $definition,
            'context' => $context,
            'title' => $definition->title(),
            'subtitle' => $definition->subtitle(),
            'orientation' => $definition->orientation(),
            'generatedAt' => $context->generatedAt->setTimezone($context->timezone)->format('d/m/Y H:i'),
            'filters' => $definition->describeFilters($context),
            'metrics' => $options->includeMetrics ? $definition->metrics($context) : [],
            'metricValue' => fn ($metric): string => $metric->value === null ? 'Sin datos' : (is_string($metric->value) ? $metric->value : $formatter->formatAs($metric->value, $metric->type)),
            'charts' => array_map(fn (Chart $chart): array => [
                'chart' => $chart,
                'geometry' => match ($chart->type->value) {
                    'bar' => $this->geometry->bar($chart),
                    'donut' => $this->geometry->donut($chart),
                    default => $this->geometry->line($chart),
                },
            ], $charts),
            'panels' => $definition->panels($context),
            'sectionsBefore' => $this->sections($definition, $context, $formatter, $maxRows, true),
            'sectionsAfter' => $this->sections($definition, $context, $formatter, $maxRows, false),
            'includeTable' => $options->includeTable,
            'tableTitle' => $options->tableTitle,
            'columns' => $columns,
            'widths' => $this->widths($columns, $definition->orientation()),
            'entries' => $table['entries'],
            'totals' => $table['totals'],
            'rowCount' => $table['rows'],
            'logo' => null,
        ])->render();

        return ['html' => $html, 'rows' => $table['rows']];
    }

    /**
     * Extra sheets printed in the PDF, formatted like the main table.
     *
     * @param  ReportDefinition<Model>  $definition
     * @return list<array{title: string, description: string|null, newPage: bool, columns: list<Column>, widths: list<float>, rows: list<list<string>>}>
     */
    private function sections(ReportDefinition $definition, ReportContext $context, ValueFormatter $formatter, int $maxRows, bool $beforeTable): array
    {
        $sections = [];

        foreach ($definition->extraSheets($context) as $sheet) {
            if (! $sheet->inPdf || $sheet->beforeTable !== $beforeTable) {
                continue;
            }

            $rows = [];

            foreach ($sheet->rows as $row) {
                if (count($rows) >= $maxRows) {
                    throw ValidationException::withMessages(['export' => 'El reporte superó el máximo de filas permitido mientras se generaba.']);
                }

                $rows[] = array_map(fn (Column $column): string => $formatter->format($row[$column->key] ?? null, $column), $sheet->columns);
            }

            $sections[] = [
                'title' => $sheet->title,
                'description' => $sheet->description,
                'newPage' => $sheet->newPage,
                'columns' => $sheet->columns,
                'widths' => $this->widths($sheet->columns, $definition->orientation()),
                'rows' => $rows,
            ];
        }

        return $sections;
    }

    /**
     * Formatted rows with group headings, subtotals and the totals row.
     *
     * @param  ReportDefinition<Model>  $definition
     * @param  list<Column>  $columns
     * @return array{entries: list<TableEntry>, totals: list<string>|null, rows: int}
     */
    private function table(ReportDefinition $definition, ReportContext $context, array $columns, ValueFormatter $formatter, int $maxRows): array
    {
        $groupKey = $definition->groupBy();
        $groupColumn = collect($columns)->first(fn (Column $column): bool => $column->key === $groupKey);
        $hasTotals = collect($columns)->contains(fn (Column $column): bool => $column->total !== ColumnTotal::None);

        $entries = [];
        $totals = $this->emptyTotals($columns);
        $groupTotals = $this->emptyTotals($columns);
        $currentGroup = null;
        $rows = 0;

        foreach ($definition->rows($context) as $row) {
            if ($rows >= $maxRows) {
                throw ValidationException::withMessages(['export' => 'El reporte superó el máximo de filas permitido mientras se generaba.']);
            }

            $rows++;

            if ($groupColumn !== null) {
                $group = $formatter->format($row[$groupColumn->key] ?? null, $groupColumn);

                if ($group !== $currentGroup) {
                    if ($currentGroup !== null && $hasTotals) {
                        $entries[] = ['kind' => 'subtotal', 'label' => "Subtotal {$currentGroup}", 'cells' => $this->formatTotals($columns, $groupTotals, $formatter)];
                    }

                    $entries[] = ['kind' => 'group', 'label' => "{$groupColumn->label}: {$group}", 'cells' => []];
                    $currentGroup = $group;
                    $groupTotals = $this->emptyTotals($columns);
                }
            }

            $cells = [];

            foreach ($columns as $index => $column) {
                $value = $row[$column->key] ?? null;
                $cells[] = $formatter->format($value, $column);
                $totals[$index] = $this->accumulate($totals[$index], $value, $column);
                $groupTotals[$index] = $this->accumulate($groupTotals[$index], $value, $column);
            }

            $entries[] = ['kind' => 'row', 'cells' => $cells];
        }

        if ($groupColumn !== null && $currentGroup !== null && $hasTotals) {
            $entries[] = ['kind' => 'subtotal', 'label' => "Subtotal {$currentGroup}", 'cells' => $this->formatTotals($columns, $groupTotals, $formatter)];
        }

        return [
            'entries' => $entries,
            'totals' => $hasTotals && $rows > 0 ? $this->formatTotals($columns, $totals, $formatter) : null,
            'rows' => $rows,
        ];
    }

    /**
     * @param  list<Column>  $columns
     * @return array<int, float>
     */
    private function emptyTotals(array $columns): array
    {
        return array_fill(0, count($columns), 0.0);
    }

    private function accumulate(float $total, mixed $value, Column $column): float
    {
        return match ($column->total) {
            ColumnTotal::Sum => $total + (is_numeric($value) ? (float) $value : 0.0),
            ColumnTotal::Count => $total + ($value === null || $value === '' ? 0 : 1),
            ColumnTotal::None => $total,
        };
    }

    /**
     * @param  list<Column>  $columns
     * @param  array<int, float>  $totals
     * @return list<string>
     */
    private function formatTotals(array $columns, array $totals, ValueFormatter $formatter): array
    {
        return array_map(fn (Column $column, float $total): string => match ($column->total) {
            ColumnTotal::Sum => $formatter->format($total, $column),
            ColumnTotal::Count => number_format($total, 0, '.', ','),
            ColumnTotal::None => '',
        }, $columns, $totals);
    }

    /**
     * Column widths as percentages of the table, from the Excel widths.
     * A date is never split, so date columns are widened to fit
     * "dd/mm/yyyy" on the page (the time may wrap below it) and the other
     * columns shrink.
     *
     * @param  list<Column>  $columns
     * @return list<float>
     */
    private function widths(array $columns, Orientation $orientation): array
    {
        $total = array_sum(array_map(fn (Column $column): float => $column->excelWidth(), $columns));

        if ($total <= 0) {
            return array_fill(0, count($columns), 0.0);
        }

        // Printable width of an A4 page with the margins of browsershot().
        $pageWidth = $orientation === Orientation::Landscape ? 273.0 : 186.0;
        $widths = array_map(fn (Column $column): float => $column->excelWidth() / $total * 100, $columns);
        $minimums = array_map(fn (Column $column): float => max($column->unbreakable ?? 0.0, match ($column->type) {
            // "dd/mm/yyyy" must fit on a line; the time may wrap below it.
            ColumnType::DateTime, ColumnType::Date => 22.0,
            default => 0.0,
        }) / $pageWidth * 100, $columns);

        $fixed = 0.0;
        $flexible = 0.0;

        foreach ($widths as $index => $width) {
            if ($width < $minimums[$index]) {
                $fixed += $minimums[$index];
            } else {
                $flexible += $width;
            }
        }

        $scale = $flexible > 0 ? max(0.0, 100 - $fixed) / $flexible : 1.0;

        return array_map(
            fn (float $width, float $minimum): float => round($width < $minimum ? $minimum : $width * $scale, 2),
            $widths,
            $minimums,
        );
    }
}

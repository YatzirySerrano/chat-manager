<?php

namespace App\Reports\Rendering;

use App\Reports\Chart;
use App\Reports\ChartType;
use App\Reports\Column;
use App\Reports\ColumnTotal;
use App\Reports\ColumnType;
use App\Reports\Metric;
use App\Reports\ReportContext;
use App\Reports\ReportDefinition;
use App\Reports\ReportPanel;
use App\Reports\ReportSheet;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Chart\Chart as ExcelChart;
use PhpOffice\PhpSpreadsheet\Chart\DataSeries;
use PhpOffice\PhpSpreadsheet\Chart\DataSeriesValues;
use PhpOffice\PhpSpreadsheet\Chart\Layout;
use PhpOffice\PhpSpreadsheet\Chart\Legend;
use PhpOffice\PhpSpreadsheet\Chart\PlotArea;
use PhpOffice\PhpSpreadsheet\Chart\Title;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Conditional;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;
use PhpOffice\PhpSpreadsheet\Worksheet\PageSetup;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * Renders a report definition as a styled workbook: an optional "Resumen"
 * sheet (metrics and native charts), the data sheet and any extra sheets.
 *
 * Every string is written as an explicit string, so values starting with
 * "=", "+", "-", "@", tab or carriage return are never evaluated as
 * formulas (spreadsheet formula injection).
 */
class XlsxRenderer implements ReportRenderer
{
    /**
     * First row of the table header on every sheet (rows above hold the
     * title block).
     */
    public const HEADER_ROW = 6;

    private const SUMMARY_SHEET = 'Resumen';

    private ValueFormatter $formatter;

    private string $timezone = 'UTC';

    /**
     * @param  ReportDefinition<Model>  $definition
     */
    public function render(ReportDefinition $definition, ReportContext $context, string $path, int $maxRows): int
    {
        $this->timezone = $context->timezone;
        $this->formatter = new ValueFormatter($context->timezone);

        $spreadsheet = new Spreadsheet;
        $spreadsheet->getDefaultStyle()->getFont()->setName('Calibri')->setSize(10)->getColor()->setRGB(ReportPalette::rgb(ReportPalette::INK));
        $this->applyProperties($spreadsheet, $definition, $context);

        $panels = $definition->panels($context);
        $metrics = $definition->metrics($context);
        $charts = array_values(array_filter($definition->charts($context), fn (Chart $chart): bool => $chart->hasData()));

        $dataSheet = $spreadsheet->getActiveSheet();
        $dataSheet->setTitle($this->sheetTitle($definition->title()));
        $rows = $this->writeTableSheet($dataSheet, $definition->title(), $definition->columns(), $definition->rows($context), $definition, $context, $maxRows);

        foreach ($definition->extraSheets($context) as $sheet) {
            $this->writeExtraSheet($spreadsheet, $sheet, $definition, $context);
        }

        if ($panels !== [] || $metrics !== [] || $charts !== []) {
            $summary = new Worksheet($spreadsheet, self::SUMMARY_SHEET);
            $spreadsheet->addSheet($summary, 0);
            $this->writeSummary($summary, $definition, $context, $panels, $metrics, $charts);
        }

        $spreadsheet->setActiveSheetIndex(0);

        $writer = new Xlsx($spreadsheet);
        $writer->setIncludeCharts(true);
        $writer->save($path);
        $spreadsheet->disconnectWorksheets();

        return $rows;
    }

    /**
     * @param  ReportDefinition<Model>  $definition
     */
    private function applyProperties(Spreadsheet $spreadsheet, ReportDefinition $definition, ReportContext $context): void
    {
        $spreadsheet->getProperties()
            ->setCreator($context->platformName)
            ->setLastModifiedBy($context->platformName)
            ->setCompany($context->platformName)
            ->setTitle($definition->title())
            ->setSubject($definition->subtitle() ?? $definition->title())
            ->setDescription("Generado por {$context->actor->name} el {$this->generatedAt($context)}.")
            ->setCategory('Reporte');
    }

    /**
     * Title block, header, rows, totals, filters and print setup of a table.
     *
     * @param  list<Column>  $columns
     * @param  iterable<array<string, mixed>>  $rows
     * @param  ReportDefinition<Model>  $definition
     * @return int Rows written.
     */
    private function writeTableSheet(Worksheet $sheet, string $title, array $columns, iterable $rows, ReportDefinition $definition, ReportContext $context, ?int $maxRows = null): int
    {
        $lastColumn = Coordinate::stringFromColumnIndex(max(1, count($columns)));
        $this->writeTitleBlock($sheet, $title, $definition, $context, $lastColumn);

        $header = self::HEADER_ROW;

        foreach ($columns as $index => $column) {
            $letter = Coordinate::stringFromColumnIndex($index + 1);
            $sheet->setCellValueExplicit("{$letter}{$header}", $column->label, DataType::TYPE_STRING);
            $sheet->getColumnDimension($letter)->setWidth($column->excelWidth());
        }

        $sheet->getStyle("A{$header}:{$lastColumn}{$header}")->applyFromArray([
            'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => ReportPalette::rgb(ReportPalette::ACCENT)]],
            'alignment' => ['vertical' => Alignment::VERTICAL_CENTER, 'wrapText' => true],
            'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => ReportPalette::rgb(ReportPalette::ACCENT_DARK)]]],
        ]);
        $sheet->getRowDimension($header)->setRowHeight(22);

        $rowNumber = $header;
        $written = 0;

        foreach ($rows as $row) {
            if ($maxRows !== null && $written >= $maxRows) {
                throw ValidationException::withMessages(['export' => 'El reporte superó el máximo de filas permitido mientras se generaba.']);
            }

            $rowNumber++;
            $written++;

            foreach ($columns as $index => $column) {
                $this->writeCell($sheet, Coordinate::stringFromColumnIndex($index + 1).$rowNumber, $row[$column->key] ?? null, $column);
            }
        }

        $firstData = $header + 1;
        $lastData = max($rowNumber, $firstData);

        foreach ($columns as $index => $column) {
            $letter = Coordinate::stringFromColumnIndex($index + 1);
            $range = "{$letter}{$firstData}:{$letter}{$lastData}";
            $sheet->getStyle($range)->getAlignment()->setHorizontal($column->alignment()->value)->setVertical(Alignment::VERTICAL_TOP);
            $sheet->getStyle($range)->getNumberFormat()->setFormatCode($this->numberFormat($column));

            if ($column->type === ColumnType::Text && $column->excelWidth() >= 30) {
                $sheet->getStyle($range)->getAlignment()->setWrapText(true);
            }
        }

        $body = "A{$firstData}:{$lastColumn}{$lastData}";

        if ($written === 0) {
            $sheet->setCellValueExplicit("A{$firstData}", 'Sin registros para los filtros aplicados.', DataType::TYPE_STRING);
            $sheet->mergeCells($body);
            $sheet->getStyle($body)->getFont()->setItalic(true)->getColor()->setRGB(ReportPalette::rgb(ReportPalette::MUTED));
        }

        $sheet->getStyle($body)->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN)->getColor()->setRGB(ReportPalette::rgb(ReportPalette::BORDER));
        $this->applyZebra($sheet, $body);

        if ($written > 0) {
            $this->writeTotals($sheet, $columns, $firstData, $lastData, $lastColumn);
        }

        $sheet->setAutoFilter("A{$header}:{$lastColumn}{$lastData}");
        $sheet->freezePane('A'.($header + 1));
        $sheet->setSelectedCell('A'.($header + 1));
        $this->applyPrintSetup($sheet, $definition, $context, $header);

        return $written;
    }

    /**
     * @param  ReportDefinition<Model>  $definition
     */
    private function writeTitleBlock(Worksheet $sheet, string $title, ReportDefinition $definition, ReportContext $context, string $lastColumn): void
    {
        $filters = $definition->describeFilters($context);
        $lines = [
            1 => mb_strtoupper($context->platformName),
            2 => $title,
            3 => trim(($definition->subtitle() !== null ? $definition->subtitle().' · ' : '')."Generado el {$this->generatedAt($context)} por {$context->actor->name}"),
            4 => 'Filtros: '.($filters === [] ? 'Sin filtros (todos los registros visibles para ti)' : implode(' · ', $filters)),
        ];

        foreach ($lines as $row => $text) {
            $sheet->setCellValueExplicit("A{$row}", $text, DataType::TYPE_STRING);
            $sheet->mergeCells("A{$row}:{$lastColumn}{$row}");
        }

        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(9)->getColor()->setRGB(ReportPalette::rgb(ReportPalette::ACCENT));
        $sheet->getStyle('A2')->getFont()->setBold(true)->setSize(16);
        $sheet->getStyle('A3:A4')->getFont()->setSize(9)->getColor()->setRGB(ReportPalette::rgb(ReportPalette::MUTED));
        $sheet->getRowDimension(2)->setRowHeight(24);
        $sheet->getStyle("A4:{$lastColumn}4")->getBorders()->getBottom()->setBorderStyle(Border::BORDER_MEDIUM)->getColor()->setRGB(ReportPalette::rgb(ReportPalette::ACCENT));
    }

    private function writeCell(Worksheet $sheet, string $coordinate, mixed $value, Column $column): void
    {
        if ($value === null || $value === '') {
            return;
        }

        if ($value instanceof CarbonInterface) {
            // Excel has no time zones: store the wall-clock time of the platform.
            $sheet->setCellValueExplicit($coordinate, ExcelDate::dateTimeToExcel($value->copy()->setTimezone($this->timezone)), DataType::TYPE_NUMERIC);

            return;
        }

        if (is_bool($value)) {
            $sheet->setCellValueExplicit($coordinate, $value ? 'Sí' : 'No', DataType::TYPE_STRING);

            return;
        }

        if ($column->type->isNumeric() && (is_int($value) || is_float($value))) {
            $sheet->setCellValueExplicit($coordinate, $value, DataType::TYPE_NUMERIC);

            return;
        }

        $text = $this->formatter->format($value, $column);
        $sheet->setCellValueExplicit($coordinate, $text, DataType::TYPE_STRING);

        if ($this->looksLikeFormula($text)) {
            $sheet->getStyle($coordinate)->setQuotePrefix(true);
        }
    }

    /**
     * Characters that make spreadsheet applications treat input as a formula.
     */
    private function looksLikeFormula(string $text): bool
    {
        return $text !== '' && in_array($text[0], ['=', '+', '-', '@', "\t", "\r"], true);
    }

    private function numberFormat(Column $column): string
    {
        $decimals = $column->decimalPlaces();
        $fraction = $decimals > 0 ? '.'.str_repeat('0', $decimals) : '';

        return match ($column->type) {
            ColumnType::Number => "#,##0{$fraction}",
            ColumnType::Currency => "\"$\"#,##0{$fraction}",
            ColumnType::Percent => "0{$fraction}%",
            ColumnType::Date => 'dd/mm/yyyy',
            ColumnType::DateTime => 'dd/mm/yyyy hh:mm',
            default => NumberFormat::FORMAT_GENERAL,
        };
    }

    /**
     * Alternate row shading as a single conditional format, so it costs the
     * same for ten rows or fifty thousand.
     */
    private function applyZebra(Worksheet $sheet, string $range): void
    {
        $zebra = new Conditional;
        $zebra->setConditionType(Conditional::CONDITION_EXPRESSION)->addCondition('MOD(ROW(),2)=0');
        $zebra->getStyle()->getFill()->setFillType(Fill::FILL_SOLID)->getEndColor()->setRGB(ReportPalette::rgb(ReportPalette::ZEBRA));
        $sheet->getStyle($range)->setConditionalStyles([$zebra]);
    }

    /**
     * Totals use SUBTOTAL so they follow the rows left visible by the filters.
     *
     * @param  list<Column>  $columns
     */
    private function writeTotals(Worksheet $sheet, array $columns, int $firstData, int $lastData, string $lastColumn): void
    {
        $withTotals = array_filter($columns, fn (Column $column): bool => $column->total !== ColumnTotal::None);

        if ($withTotals === []) {
            return;
        }

        $totalRow = $lastData + 1;
        $sheet->setCellValueExplicit("A{$totalRow}", 'Total', DataType::TYPE_STRING);

        foreach ($columns as $index => $column) {
            if ($column->total === ColumnTotal::None) {
                continue;
            }

            $letter = Coordinate::stringFromColumnIndex($index + 1);
            $function = $column->total === ColumnTotal::Sum ? 109 : 103;
            $sheet->setCellValue("{$letter}{$totalRow}", "=SUBTOTAL({$function},{$letter}{$firstData}:{$letter}{$lastData})");
            $sheet->getStyle("{$letter}{$totalRow}")->getNumberFormat()->setFormatCode(
                $column->total === ColumnTotal::Sum ? $this->numberFormat($column) : '#,##0',
            );
            $sheet->getStyle("{$letter}{$totalRow}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
        }

        $sheet->getStyle("A{$totalRow}:{$lastColumn}{$totalRow}")->applyFromArray([
            'font' => ['bold' => true],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => ReportPalette::rgb(ReportPalette::ACCENT_SOFT)]],
            'borders' => [
                'top' => ['borderStyle' => Border::BORDER_MEDIUM, 'color' => ['rgb' => ReportPalette::rgb(ReportPalette::ACCENT)]],
                'bottom' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => ReportPalette::rgb(ReportPalette::BORDER)]],
            ],
        ]);
    }

    /**
     * @param  ReportDefinition<Model>  $definition
     */
    private function applyPrintSetup(Worksheet $sheet, ReportDefinition $definition, ReportContext $context, int $header): void
    {
        $sheet->getPageSetup()
            ->setOrientation($definition->orientation()->value === 'landscape' ? PageSetup::ORIENTATION_LANDSCAPE : PageSetup::ORIENTATION_PORTRAIT)
            ->setPaperSize(PageSetup::PAPERSIZE_A4)
            ->setFitToWidth(1)
            ->setFitToHeight(0)
            ->setRowsToRepeatAtTopByStartAndEnd($header, $header);
        $sheet->getPageMargins()->setTop(0.6)->setBottom(0.6)->setLeft(0.4)->setRight(0.4);
        $name = $this->headerFooterText($context->platformName);
        $title = $this->headerFooterText($definition->title());
        $sheet->getHeaderFooter()
            ->setOddFooter("&L&8{$name} · {$title}&R&8Página &P de &N");
        $sheet->setShowGridlines(false);
    }

    /**
     * Header/footer codes start with "&"; literal ampersands are doubled.
     */
    private function headerFooterText(string $text): string
    {
        return str_replace('&', '&&', $text);
    }

    /**
     * @param  ReportDefinition<Model>  $definition
     */
    private function writeExtraSheet(Spreadsheet $spreadsheet, ReportSheet $extra, ReportDefinition $definition, ReportContext $context): void
    {
        $sheet = new Worksheet($spreadsheet, $this->sheetTitle($extra->title, $spreadsheet));
        $spreadsheet->addSheet($sheet);
        $this->writeTableSheet($sheet, $extra->title, $extra->columns, $extra->rows, $definition, $context);
    }

    /**
     * @param  ReportDefinition<Model>  $definition
     * @param  list<ReportPanel>  $panels
     * @param  list<Metric>  $metrics
     * @param  list<Chart>  $charts
     */
    private function writeSummary(Worksheet $sheet, ReportDefinition $definition, ReportContext $context, array $panels, array $metrics, array $charts): void
    {
        $this->writeTitleBlock($sheet, "{$definition->title()} · Resumen", $definition, $context, 'H');
        $sheet->setShowGridlines(false);
        $sheet->getColumnDimension('A')->setWidth(34);
        $sheet->getColumnDimension('B')->setWidth(18);
        $sheet->getColumnDimension('C')->setWidth(34);

        $row = self::HEADER_ROW;

        foreach ($panels as $panel) {
            $row = $this->writePanel($sheet, $panel, $row);
        }

        if ($metrics !== []) {
            $this->writeSummaryHeader($sheet, $row, ['Indicador', 'Valor', 'Detalle']);

            foreach ($metrics as $metric) {
                $row++;
                $sheet->setCellValueExplicit("A{$row}", $metric->label, DataType::TYPE_STRING);
                $this->writeCell($sheet, "B{$row}", $metric->value ?? 'Sin datos', new Column('value', $metric->label, is_string($metric->value) ? ColumnType::Text : $metric->type));
                $sheet->getStyle("B{$row}")->getNumberFormat()->setFormatCode($this->numberFormat(new Column('value', '', $metric->type)));
                $sheet->getStyle("B{$row}")->getFont()->setBold(true);
                $sheet->getStyle("B{$row}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);

                if ($metric->hint !== null) {
                    $sheet->setCellValueExplicit("C{$row}", $metric->hint, DataType::TYPE_STRING);
                    $sheet->getStyle("C{$row}")->getFont()->getColor()->setRGB(ReportPalette::rgb(ReportPalette::MUTED));
                }
            }

            $sheet->getStyle('A'.(self::HEADER_ROW + 1).":C{$row}")->getBorders()->getAllBorders()
                ->setBorderStyle(Border::BORDER_THIN)->getColor()->setRGB(ReportPalette::rgb(ReportPalette::BORDER));
            $row += 2;
        }

        $chartTop = self::HEADER_ROW;

        foreach ($charts as $chartIndex => $chart) {
            $row = $this->writeChartData($sheet, $chart, $row, $chartIndex, $chartTop);
            $chartTop += 18;
        }
    }

    /**
     * A label/value block: the title as header, one row per item (the value
     * spans columns B and C).
     *
     * @return int Next free row.
     */
    private function writePanel(Worksheet $sheet, ReportPanel $panel, int $row): int
    {
        $this->writeSummaryHeader($sheet, $row, [$panel->title, '', '']);
        $sheet->mergeCells("A{$row}:C{$row}");
        $first = $row + 1;

        foreach ($panel->items as $label => $value) {
            $row++;
            $sheet->setCellValueExplicit("A{$row}", (string) $label, DataType::TYPE_STRING);
            $sheet->getStyle("A{$row}")->getFont()->getColor()->setRGB(ReportPalette::rgb(ReportPalette::MUTED));
            $this->writeCell($sheet, "B{$row}", $value ?? '—', new Column('value', (string) $label));
            $sheet->mergeCells("B{$row}:C{$row}");
            $sheet->getStyle("B{$row}")->getFont()->setBold(true);
            $sheet->getStyle("B{$row}")->getAlignment()->setWrapText(true)->setVertical(Alignment::VERTICAL_TOP);
        }

        if ($panel->note !== null) {
            $row++;
            $sheet->setCellValueExplicit("A{$row}", $panel->note, DataType::TYPE_STRING);
            $sheet->mergeCells("A{$row}:C{$row}");
            $sheet->getStyle("A{$row}")->getFont()->setItalic(true)->setSize(9)->getColor()->setRGB(ReportPalette::rgb(ReportPalette::MUTED));
            $sheet->getStyle("A{$row}")->getAlignment()->setWrapText(true);
        }

        if ($row >= $first) {
            $sheet->getStyle("A{$first}:C{$row}")->getBorders()->getAllBorders()
                ->setBorderStyle(Border::BORDER_THIN)->getColor()->setRGB(ReportPalette::rgb(ReportPalette::BORDER));
        }

        return $row + 2;
    }

    /**
     * @param  list<string>  $labels
     */
    private function writeSummaryHeader(Worksheet $sheet, int $row, array $labels): void
    {
        $last = Coordinate::stringFromColumnIndex(count($labels));

        foreach ($labels as $index => $label) {
            $sheet->setCellValueExplicit(Coordinate::stringFromColumnIndex($index + 1).$row, $label, DataType::TYPE_STRING);
        }

        $sheet->getStyle("A{$row}:{$last}{$row}")->applyFromArray([
            'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => ReportPalette::rgb(ReportPalette::ACCENT)]],
        ]);
    }

    /**
     * Writes the chart's source table and a native chart drawn from it.
     *
     * @return int Next free row.
     */
    private function writeChartData(Worksheet $sheet, Chart $chart, int $row, int $chartIndex, int $chartTop): int
    {
        $sheet->setCellValueExplicit("A{$row}", $chart->title, DataType::TYPE_STRING);
        $sheet->getStyle("A{$row}")->getFont()->setBold(true)->setSize(11);
        $row++;

        $headers = ['Categoría', ...array_map(fn ($series): string => $series->name, $chart->series)];
        $this->writeSummaryHeader($sheet, $row, $headers);
        $headerRow = $row;

        foreach ($chart->labels as $index => $label) {
            $row++;
            $sheet->setCellValueExplicit("A{$row}", $label, DataType::TYPE_STRING);

            foreach ($chart->series as $seriesIndex => $series) {
                $letter = Coordinate::stringFromColumnIndex($seriesIndex + 2);
                $sheet->setCellValueExplicit("{$letter}{$row}", $series->values[$index] ?? 0, DataType::TYPE_NUMERIC);
                $sheet->getStyle("{$letter}{$row}")->getNumberFormat()->setFormatCode('#,##0');
            }
        }

        $first = $headerRow + 1;
        $last = $row;
        $sheetRef = "'".self::SUMMARY_SHEET."'";
        $categories = [new DataSeriesValues(DataSeriesValues::DATASERIES_TYPE_STRING, "{$sheetRef}!\$A\${$first}:\$A\${$last}", null, count($chart->labels))];
        $values = [];
        $names = [];

        foreach ($chart->series as $seriesIndex => $series) {
            $letter = Coordinate::stringFromColumnIndex($seriesIndex + 2);
            $names[] = new DataSeriesValues(DataSeriesValues::DATASERIES_TYPE_STRING, "{$sheetRef}!\${$letter}\${$headerRow}", null, 1);
            $color = match ($chart->type) {
                ChartType::Donut => array_map(fn (int $index): string => ReportPalette::rgb($this->donutColor($index, count($chart->labels))), array_keys($chart->labels)),
                default => ReportPalette::rgb(count($chart->series) === 1 ? ReportPalette::ACCENT : ReportPalette::series($seriesIndex)),
            };
            $values[] = new DataSeriesValues(DataSeriesValues::DATASERIES_TYPE_NUMBER, "{$sheetRef}!\${$letter}\${$first}:\${$letter}\${$last}", null, count($chart->labels), null, null, $color);

            if ($chart->type === ChartType::Donut) {
                break;
            }
        }

        $series = new DataSeries(
            match ($chart->type) {
                ChartType::Bar => DataSeries::TYPE_BARCHART,
                ChartType::Donut => DataSeries::TYPE_DONUTCHART,
                ChartType::Line => DataSeries::TYPE_LINECHART,
            },
            $chart->type === ChartType::Donut ? null : DataSeries::GROUPING_CLUSTERED,
            array_keys($values),
            $names,
            $categories,
            $values,
        );

        if ($chart->type === ChartType::Bar) {
            $series->setPlotDirection(DataSeries::DIRECTION_BAR);
        }

        $layout = new Layout;

        if ($chart->type === ChartType::Donut) {
            $layout->setShowPercent(true);
        } else {
            $layout->setShowVal(true);
        }

        $excelChart = new ExcelChart(
            "chart{$chartIndex}",
            new Title($chart->title),
            $chart->type === ChartType::Donut || count($chart->series) > 1 ? new Legend(Legend::POSITION_RIGHT, null, false) : null,
            new PlotArea($layout, [$series]),
        );
        $excelChart->setTopLeftPosition("E{$chartTop}");
        $excelChart->setBottomRightPosition('L'.($chartTop + 16));

        if ($chart->type === ChartType::Bar) {
            // Largest first, top to bottom, as in the PDF ranking.
            $excelChart->getChartAxisX()->setAxisOption('orientation', 'maxMin');
        }

        $sheet->addChart($excelChart);

        return $row + 2;
    }

    private function donutColor(int $index, int $count): string
    {
        return $count === 2 && $index === 1 ? ReportPalette::NEUTRAL_MARK : ($count === 2 ? ReportPalette::ACCENT : ReportPalette::series($index));
    }

    /**
     * Worksheet names: at most 31 characters, without []:*?/\ and unique.
     */
    private function sheetTitle(string $title, ?Spreadsheet $spreadsheet = null): string
    {
        $clean = mb_substr(trim((string) preg_replace('/[\[\]:*?\/\\\\]/u', ' ', $title)), 0, 31);
        $clean = $clean === '' ? 'Datos' : $clean;

        if ($spreadsheet === null || $spreadsheet->getSheetByName($clean) === null) {
            return $clean;
        }

        $suffix = 2;

        while ($spreadsheet->getSheetByName(mb_substr($clean, 0, 28)." {$suffix}") !== null) {
            $suffix++;
        }

        return mb_substr($clean, 0, 28)." {$suffix}";
    }

    private function generatedAt(ReportContext $context): string
    {
        return $context->generatedAt->setTimezone($context->timezone)->format('d/m/Y H:i');
    }
}

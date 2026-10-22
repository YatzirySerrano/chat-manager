<?php

namespace App\Services\Exports;

use App\Enums\AuditAction;
use App\Enums\DataTransferStatus;
use App\Enums\DataTransferType;
use App\Enums\ExportFormat;
use App\Jobs\GenerateReport;
use App\Models\DataTransfer;
use App\Models\User;
use App\Reports\Rendering\PdfRenderer;
use App\Reports\Rendering\ReportRenderer;
use App\Reports\Rendering\XlsxRenderer;
use App\Reports\ReportContext;
use App\Reports\ReportDefinition;
use App\Reports\ReportRegistry;
use App\Services\AuditLogger;
use App\Services\PlatformSettings;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Container\Container;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Throwable;

/**
 * Single entry point of every export: authorizes, validates the filters,
 * enforces the row limits, generates small exports immediately and queues
 * the larger ones. Every export is audited (never with sensitive data).
 */
class ReportExporter
{
    public function __construct(
        private Container $container,
        private PlatformSettings $settings,
        private AuditLogger $audit,
        private ReportRegistry $registry,
    ) {}

    /**
     * Authorize the actor and validate the filters of the request.
     *
     * @param  ReportDefinition<Model>  $definition
     * @param  array<string, mixed>  $input  Raw query string.
     *
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function prepare(ReportDefinition $definition, User $actor, array $input): ReportContext
    {
        $this->authorize($definition, $actor);

        $validated = Validator::make($input, $definition->rules())->validate();
        $filters = $definition->normalizeFilters($validated);

        if (! $definition->authorizeRecord($actor, $filters)) {
            throw new NotFoundHttpException;
        }

        return $this->context($actor, $filters);
    }

    /**
     * Every export needs the report's export permission (reports.export, or
     * audit.export for the audit log) and the module's own authorization
     * (view permission and data scope).
     *
     * @param  ReportDefinition<Model>  $definition
     *
     * @throws AuthorizationException
     */
    public function authorize(ReportDefinition $definition, User $actor): void
    {
        if (! $this->allows($definition, $actor)) {
            throw new AuthorizationException('No tienes permiso para exportar esta información.');
        }
    }

    /**
     * @param  ReportDefinition<Model>  $definition
     */
    public function allows(ReportDefinition $definition, User $actor): bool
    {
        return $actor->holdsPermission($definition->exportPermission()) && $definition->authorize($actor);
    }

    /**
     * Whether the actor may export the report registered under the key, for
     * the "Exportar" menu of each screen (the server decides, not the client).
     */
    public function allowsReport(string $key, User $actor): bool
    {
        $definition = $this->registry->find($key);

        return $definition !== null && $this->allows($definition, $actor);
    }

    /**
     * Authorization of a stored export (queued): the module, the export
     * permission and, for single-record reports, the record itself.
     *
     * @param  ReportDefinition<Model>  $definition
     * @param  array<string, mixed>  $filters
     */
    public function allowsStored(ReportDefinition $definition, User $actor, array $filters): bool
    {
        return $this->allows($definition, $actor) && $definition->authorizeRecord($actor, $filters);
    }

    /**
     * @param  array<string, mixed>  $filters  Already validated and normalized.
     */
    public function context(User $actor, array $filters): ReportContext
    {
        $timezone = (string) $this->settings->get('general.timezone', config('app.timezone'));

        return new ReportContext(
            actor: $actor,
            filters: $filters,
            generatedAt: CarbonImmutable::now($timezone),
            platformName: (string) $this->settings->get('general.platform_name', config('app.name')),
            timezone: $timezone,
        );
    }

    /**
     * Generate the file now (download response) or queue it (transfer).
     *
     * @param  ReportDefinition<Model>  $definition
     *
     * @throws ValidationException When the report exceeds the hard limit.
     */
    public function export(ReportDefinition $definition, ReportContext $context, ExportFormat $format): BinaryFileResponse|DataTransfer
    {
        $rows = $definition->count($context);
        $this->ensureWithinLimit($rows, $format);

        if ($rows > $format->syncThreshold()) {
            return $this->queue($definition, $context, $format, $rows);
        }

        $path = $this->temporaryPath($format);

        try {
            $written = $this->render($definition, $context, $format, $path);
        } catch (Throwable $exception) {
            @unlink($path);

            throw $exception;
        }

        $this->recordGenerated($definition, $context, $format, $written, 'sync');

        return response()
            ->download($path, $this->fileName($definition, $context, $format), ['Content-Type' => $format->mimeType()])
            ->deleteFileAfterSend();
    }

    /**
     * Write the report in the given format to a local path.
     *
     * Every query of the report reads one consistent snapshot (a REPEATABLE
     * READ transaction that takes no locks): rows read page by page never
     * shift while customer messages keep moving conversations up the list,
     * so no record is exported twice or skipped, and writers are never
     * blocked by the export.
     *
     * @param  ReportDefinition<Model>  $definition
     * @return int Rows written.
     */
    public function render(ReportDefinition $definition, ReportContext $context, ExportFormat $format, string $path): int
    {
        $connection = DB::connection();

        if ($connection->transactionLevel() === 0) {
            $connection->statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        }

        return $connection->transaction(fn (): int => $this->renderer($format)->render($definition, $context, $path, $format->maxRows()));
    }

    /**
     * @throws ValidationException
     */
    public function ensureWithinLimit(int $rows, ExportFormat $format): void
    {
        if ($rows <= $format->maxRows()) {
            return;
        }

        $count = number_format($rows);
        $max = number_format($format->maxRows());

        throw ValidationException::withMessages([
            'export' => "El reporte tiene {$count} registros y el máximo para {$format->label()} es {$max}. Aplica filtros para reducirlo e inténtalo de nuevo.",
        ]);
    }

    /**
     * Safe download name, e.g. "etiquetas-2026-10-21-1530.xlsx".
     *
     * @param  ReportDefinition<Model>  $definition
     */
    public function fileName(ReportDefinition $definition, ReportContext $context, ExportFormat $format): string
    {
        $base = Str::slug($definition->fileName()) ?: 'reporte';

        return "{$base}-{$context->generatedAt->setTimezone($context->timezone)->format('Y-m-d-Hi')}.{$format->extension()}";
    }

    public function temporaryPath(ExportFormat $format): string
    {
        $directory = storage_path('framework/cache/exports');

        if (! is_dir($directory)) {
            mkdir($directory, 0700, true);
        }

        return $directory.'/'.Str::uuid()->toString().'.'.$format->extension();
    }

    /**
     * @param  ReportDefinition<Model>  $definition
     */
    public function recordGenerated(ReportDefinition $definition, ReportContext $context, ExportFormat $format, int $rows, string $mode, ?DataTransfer $transfer = null): void
    {
        $this->audit->record(AuditAction::ExportGenerated, null, [
            ...$this->auditProperties($definition, $context, $format, $rows),
            'mode' => $mode,
            ...($transfer === null ? [] : ['transfer_id' => $transfer->id]),
        ], $context->actor);
    }

    /**
     * @param  ReportDefinition<Model>  $definition
     */
    private function queue(ReportDefinition $definition, ReportContext $context, ExportFormat $format, int $rows): DataTransfer
    {
        $transfer = DataTransfer::query()->create([
            'type' => DataTransferType::Export,
            'report' => $definition->key(),
            'format' => $format,
            'status' => DataTransferStatus::Queued,
            'user_id' => $context->actor->id,
            'filters' => $context->filters,
            'options' => ['filter_labels' => $definition->describeFilters($context)],
            'disk' => (string) config('exports.disk'),
            'file_name' => $this->fileName($definition, $context, $format),
            'rows_total' => $rows,
        ]);

        $this->audit->record(AuditAction::ExportRequested, null, [
            ...$this->auditProperties($definition, $context, $format, $rows),
            'mode' => 'queued',
            'transfer_id' => $transfer->id,
        ], $context->actor);

        GenerateReport::dispatch($transfer->id)->onQueue((string) config('exports.queue'));

        return $transfer;
    }

    /**
     * @param  ReportDefinition<Model>  $definition
     * @return array{report: string, report_title: string, format: string, filters: list<string>, rows: int}
     */
    private function auditProperties(ReportDefinition $definition, ReportContext $context, ExportFormat $format, int $rows): array
    {
        return [
            'report' => $definition->key(),
            'report_title' => $definition->title(),
            'format' => $format->value,
            'filters' => $definition->describeFilters($context),
            'rows' => $rows,
        ];
    }

    private function renderer(ExportFormat $format): ReportRenderer
    {
        return $this->container->make(match ($format) {
            ExportFormat::Xlsx => XlsxRenderer::class,
            ExportFormat::Pdf => PdfRenderer::class,
        });
    }
}

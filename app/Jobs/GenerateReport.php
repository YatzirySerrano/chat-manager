<?php

namespace App\Jobs;

use App\Enums\AuditAction;
use App\Enums\DataTransferStatus;
use App\Models\DataTransfer;
use App\Reports\ReportRegistry;
use App\Services\AuditLogger;
use App\Services\Exports\ReportExporter;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Http\File;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

/**
 * Generates a queued export into the private disk. Authorization and the
 * row limit are checked again, because permissions or data may have
 * changed since the export was requested. A transfer is attempted once.
 */
class GenerateReport implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout;

    public bool $failOnTimeout = true;

    public function __construct(public int $transferId)
    {
        $this->timeout = (int) config('exports.job_timeout', 600);
    }

    public function uniqueId(): string
    {
        return (string) $this->transferId;
    }

    public function handle(ReportRegistry $registry, ReportExporter $exporter): void
    {
        $transfer = DB::transaction(function (): ?DataTransfer {
            $transfer = DataTransfer::query()->lockForUpdate()->find($this->transferId);

            if ($transfer === null || $transfer->status !== DataTransferStatus::Queued) {
                return null;
            }

            $transfer->update(['status' => DataTransferStatus::Processing, 'started_at' => now()]);

            return $transfer;
        });

        if ($transfer === null) {
            return;
        }

        $definition = $registry->find($transfer->report);
        $actor = $transfer->user()->first();

        if ($definition === null || $actor === null || ! $exporter->allowsStored($definition, $actor, $transfer->filters ?? [])) {
            $this->markFailed($transfer, 'Ya no tienes permiso para exportar esta información.');

            return;
        }

        @ini_set('memory_limit', (string) config('exports.memory_limit', '1024M'));

        $context = $exporter->context($actor, $transfer->filters ?? []);
        $path = $exporter->temporaryPath($transfer->format);

        try {
            $exporter->ensureWithinLimit($definition->count($context), $transfer->format);
            $rows = $exporter->render($definition, $context, $transfer->format, $path);

            $disk = Storage::disk($transfer->disk ?? (string) config('exports.disk'));
            $directory = trim((string) config('exports.directory'), '/')."/{$actor->id}";
            $stored = $disk->putFileAs($directory, new File($path), Str::uuid()->toString().'.'.$transfer->format->extension());

            if ($stored === false) {
                throw new RuntimeException('The export could not be written to the disk.');
            }
        } catch (ValidationException $exception) {
            $this->markFailed($transfer, $exception->validator->errors()->first());

            return;
        } finally {
            @unlink($path);
        }

        $transfer->update([
            'status' => DataTransferStatus::Completed,
            'path' => $stored,
            'rows_total' => $rows,
            'rows_processed' => $rows,
            'finished_at' => now(),
            'expires_at' => now()->addHours(max(1, (int) config('exports.retention_hours'))),
        ]);

        $exporter->recordGenerated($definition, $context, $transfer->format, $rows, 'queued', $transfer);
    }

    /**
     * Unexpected errors (Chrome missing, timeouts, disk) end here: the user
     * sees a generic message, details stay in the failed jobs log.
     */
    public function failed(?Throwable $exception): void
    {
        $transfer = DataTransfer::query()->find($this->transferId);

        if ($transfer !== null && $transfer->status->isPending()) {
            $this->markFailed($transfer, 'No se pudo generar el archivo. Inténtalo de nuevo o reduce los filtros.');
        }
    }

    private function markFailed(DataTransfer $transfer, string $reason): void
    {
        $transfer->update([
            'status' => DataTransferStatus::Failed,
            'error' => $reason,
            'finished_at' => now(),
        ]);

        app(AuditLogger::class)->record(AuditAction::ExportFailed, null, [
            'report' => $transfer->report,
            'format' => $transfer->format->value,
            'filters' => $transfer->options['filter_labels'] ?? [],
            'transfer_id' => $transfer->id,
            'reason' => $reason,
        ], $transfer->user()->first());
    }
}

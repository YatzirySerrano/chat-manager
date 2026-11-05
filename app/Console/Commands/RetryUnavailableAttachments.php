<?php

namespace App\Console\Commands;

use App\Enums\AttachmentStatus;
use App\Enums\MessageType;
use App\Jobs\DownloadInboundMedia;
use App\Models\MessageAttachment;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;

/**
 * Customer media that ended up "unavailable", for diagnosis and a
 * controlled second download. Without --apply it only reads: a summary by
 * reason and announced type, and the latest rows (no URLs, tokens or
 * personal data). With --apply it queues again, one job per row, the rows
 * that can still be downloaded: a WhatsApp media id (Meta keeps webhook
 * media for attachments.storage.inbound_available_days), download attempts
 * left and one of the chosen reasons. Nothing is marked available by hand:
 * the download job validates the file again with the current rules.
 */
class RetryUnavailableAttachments extends Command
{
    /** Reasons a later download may solve; credentials and simulations are not retried here. */
    private const RETRYABLE = ['expired', 'download_failed', 'too_large', 'type_not_allowed', 'empty'];

    protected $signature = 'attachments:retry-unavailable
        {--type= : Message type (sticker, image, audio, video or document)}
        {--reason=* : Only these reasons (default: expired, download_failed, too_large, type_not_allowed, empty)}
        {--since= : Only attachments received on or after this date (Y-m-d)}
        {--limit=50 : Rows to queue at most}
        {--apply : Queue the downloads (without it nothing is changed)}';

    protected $description = 'Summarize unavailable customer media and, with --apply, download the recoverable ones again';

    public function handle(): int
    {
        $typeOption = $this->option('type');
        $type = is_string($typeOption) ? MessageType::tryFrom($typeOption) : null;

        if ($typeOption !== null && $type === null) {
            $this->error('Tipo de mensaje desconocido.');

            return self::INVALID;
        }

        $reasons = array_values(array_filter((array) $this->option('reason'), 'is_string')) ?: self::RETRYABLE;
        $unknown = array_diff($reasons, self::RETRYABLE);

        if ($unknown !== []) {
            $this->error('Motivos que no se reintentan con este comando: '.implode(', ', $unknown).'.');

            return self::INVALID;
        }

        $sinceOption = $this->option('since');
        $since = is_string($sinceOption) && CarbonImmutable::canBeCreatedFromFormat($sinceOption, 'Y-m-d')
            ? CarbonImmutable::createFromFormat('Y-m-d', $sinceOption)?->startOfDay()
            : null;

        if ($sinceOption !== null && $since === null) {
            $this->error('La fecha debe tener el formato AAAA-MM-DD.');

            return self::INVALID;
        }

        $unavailable = fn (): Builder => MessageAttachment::query()
            ->where('status', AttachmentStatus::Unavailable)
            ->when($type !== null, fn (Builder $query) => $query->whereHas('message', fn (Builder $message) => $message->where('type', $type)))
            ->when($since !== null, fn (Builder $query) => $query->where('created_at', '>=', $since));

        $this->table(['Motivo', 'Tipo anunciado', 'Adjuntos'], $unavailable()
            ->selectRaw('error_code, mime_type, count(*) as total')
            ->groupBy('error_code', 'mime_type')
            ->orderByDesc('total')
            ->get()
            ->map(fn (MessageAttachment $row): array => [$row->error_code ?? '—', $row->mime_type, $row->getAttribute('total')])
            ->all());

        $this->table(['Id', 'Tipo', 'Motivo', 'Tipo anunciado', 'Tamaño', 'Intentos', 'Recibido'], $unavailable()
            ->with('message:id,type')
            ->latest('id')
            ->limit(20)
            ->get()
            ->map(fn (MessageAttachment $row): array => [$row->id, $row->message?->type->value, $row->error_code, $row->mime_type, $row->size_bytes, $row->download_attempts, $row->created_at?->toDateTimeString()])
            ->all());

        $recoverable = $unavailable()
            ->whereIn('error_code', $reasons)
            ->whereNotNull('whatsapp_media_id')
            ->where('whatsapp_media_id', 'not like', 'sim.%')
            ->where('created_at', '>=', CarbonImmutable::now()->subDays(max(1, (int) config('attachments.storage.inbound_available_days', 7))))
            ->where('download_attempts', '<', max(1, (int) config('attachments.storage.max_download_attempts', 12)));

        $count = (clone $recoverable)->count();

        if (! $this->option('apply')) {
            $this->info("Se podrían volver a descargar {$count} adjuntos (máximo ".(int) $this->option('limit').' por ejecución). Nada se cambió: usa --apply para encolarlos.');

            return self::SUCCESS;
        }

        $queued = 0;

        foreach ($recoverable->orderBy('id')->limit(max(1, (int) $this->option('limit')))->pluck('id') as $id) {
            $updated = MessageAttachment::query()
                ->whereKey($id)
                ->where('status', AttachmentStatus::Unavailable)
                ->update(['status' => AttachmentStatus::Pending, 'error_code' => null, 'updated_at' => now()]);

            if ($updated > 0) {
                DownloadInboundMedia::dispatch($id);
                $queued++;
            }
        }

        $this->info("Descargas encoladas: {$queued}. Los que Meta ya no conserve volverán a quedar como no disponibles con su motivo.");

        return self::SUCCESS;
    }
}

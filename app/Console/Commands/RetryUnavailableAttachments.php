<?php

namespace App\Console\Commands;

use App\Enums\AttachmentStatus;
use App\Enums\MessageType;
use App\Models\MessageAttachment;
use App\Support\Attachments\MediaDownloads;
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
    private const RETRYABLE = ['storage_unavailable', 'expired', 'download_failed', 'too_large', 'type_not_allowed', 'empty'];

    protected $signature = 'attachments:retry-unavailable
        {--type= : Message type (sticker, image, audio, video or document)}
        {--reason=* : Only these reasons (default: storage_unavailable, expired, download_failed, too_large, type_not_allowed, empty)}
        {--channel= : Only one source: whatsapp-web, telegram or meta}
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

        $channel = $this->option('channel');

        if ($channel !== null && ! in_array($channel, ['whatsapp-web', 'telegram', 'meta'], true)) {
            $this->error('Canal desconocido: usa whatsapp-web, telegram o meta.');

            return self::INVALID;
        }

        // Candidates by reason and age; whether a job can still fetch each one
        // (WhatsApp Web descriptor, Telegram file id, Meta media id or CDN URL)
        // is decided row by row (MediaDownloads), so every channel counts.
        $candidates = fn (): Builder => $unavailable()
            ->whereIn('error_code', $reasons)
            ->where('created_at', '>=', CarbonImmutable::now()->subDays(max(1, (int) config('attachments.storage.inbound_available_days', 7))))
            ->where('download_attempts', '<', max(1, (int) config('attachments.storage.max_download_attempts', 12)));

        $recoverable = 0;
        $unrecoverable = 0;
        $queued = 0;
        $limit = max(1, (int) $this->option('limit'));
        $apply = (bool) $this->option('apply');

        $candidates()
            ->with('message:id,conversation_id,metadata', 'message.conversation:id,connection_id', 'message.conversation.channelConnection')
            ->select(['id', 'message_id', 'whatsapp_media_id', 'provider_reference'])
            ->chunkById(500, function ($rows) use (&$recoverable, &$unrecoverable, &$queued, $limit, $apply, $channel): bool {
                foreach ($rows as $row) {
                    if ($channel !== null && self::channelOf($row) !== $channel) {
                        continue;
                    }

                    if (! MediaDownloads::recoverable($row)) {
                        $unrecoverable++;

                        continue;
                    }

                    $recoverable++;

                    if (! $apply || $queued >= $limit) {
                        continue;
                    }

                    $updated = MessageAttachment::query()
                        ->whereKey($row->id)
                        ->where('status', AttachmentStatus::Unavailable)
                        ->update(['status' => AttachmentStatus::Pending, 'error_code' => null, 'updated_at' => now()]);

                    if ($updated > 0) {
                        MediaDownloads::dispatch($row);
                        $queued++;
                    }
                }

                return true;
            });

        $this->table(['Recuperables (adjuntos únicos)', 'Sin forma de descargarlos', 'Encolados ahora'], [[$recoverable, $unrecoverable, $queued]]);

        if (! $apply) {
            $this->info("Se podrían volver a descargar {$recoverable} adjuntos (máximo {$limit} por ejecución). Nada se cambió: usa --apply para encolarlos.");

            return self::SUCCESS;
        }

        $this->info("Descargas encoladas: {$queued}. Los que el canal ya no conserve volverán a quedar como no disponibles con su motivo; repite el comando para el siguiente lote.");

        return self::SUCCESS;
    }

    private static function channelOf(MessageAttachment $row): string
    {
        $connection = $row->message?->conversation?->channelConnection;

        return match (true) {
            $connection?->isTelegram() === true => 'telegram',
            $row->provider_reference !== null || $connection?->isWhatsAppWeb() === true => 'whatsapp-web',
            default => 'meta',
        };
    }
}

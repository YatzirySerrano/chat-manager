<?php

namespace App\Services\Migration\History;

use App\Enums\ContactStatus;
use App\Enums\HistoryImportDecision;
use App\Enums\HistoryImportFileStatus;
use App\Models\DataTransfer;
use App\Models\HistoryImportConversation;
use App\Models\HistoryImportFile;
use App\Services\Storage\MediaDisk;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * The numbers the admin confirms before a history import runs: totals per
 * destination, contacts involved (existing / new / to be saved in the
 * directory), messages, attachments recoverable and missing with their
 * estimated size, duplicates, issues, and what still blocks the execution.
 * The confirmation token is a fingerprint of those numbers, the mappings
 * and the latest change of the inventory: confirming with a token from an
 * older summary is refused (409 summary_changed).
 *
 * @phpstan-type Summary array{totals: array{conversations: int, import: int, archive: int, exclude: int, undecided: int}, contacts: array{involved: int, existing: int, new: int, to_save: int}, messages: array{import: int, archive: int, total: int}, attachments: array{recoverable: int, missing: int, estimated_bytes: int}, duplicates: int, groups: int, issues: array{blocking: int, warnings: int}, unmapped_connections: list<string>, files: array{pending: int, failed: int, parsed: int}, ready: bool, blockers: list<array{code: string, message: string}>, confirmation_token: string}
 */
class HistoryImportSummary
{
    public function __construct(private MediaDisk $mediaDisk) {}

    /**
     * @return Summary
     */
    public function for(DataTransfer $batch): array
    {
        $rows = fn (): Builder => HistoryImportConversation::query()->where('data_transfer_id', $batch->id);
        $creating = fn (): Builder => $rows()->whereIn('decision', [HistoryImportDecision::Import, HistoryImportDecision::Archive]);

        /** @var object{conversations: int|string|null, import: int|string|null, archive: int|string|null, exclude: int|string|null, undecided: int|string|null, messages_import: int|string|null, messages_archive: int|string|null, recoverable: int|string|null, missing: int|string|null, bytes: int|string|null, duplicates: int|string|null, groups: int|string|null} $totals */
        $totals = $rows()->toBase()
            ->selectRaw('COUNT(*) AS conversations')
            ->selectRaw("SUM(decision = 'import') AS import")
            ->selectRaw("SUM(decision = 'archive') AS archive")
            ->selectRaw("SUM(decision = 'exclude') AS exclude")
            ->selectRaw('SUM(decision IS NULL) AS undecided')
            ->selectRaw("SUM(CASE WHEN decision = 'import' THEN message_count ELSE 0 END) AS messages_import")
            ->selectRaw("SUM(CASE WHEN decision = 'archive' THEN message_count ELSE 0 END) AS messages_archive")
            ->selectRaw("SUM(CASE WHEN decision IN ('import', 'archive') THEN attachments_available ELSE 0 END) AS recoverable")
            ->selectRaw("SUM(CASE WHEN decision IN ('import', 'archive') THEN attachments_total - attachments_available ELSE 0 END) AS missing")
            ->selectRaw("SUM(CASE WHEN decision IN ('import', 'archive') THEN media_bytes ELSE 0 END) AS bytes")
            ->selectRaw("SUM(already_imported = 1 AND (decision IS NULL OR decision <> 'exclude')) AS duplicates")
            ->selectRaw("SUM(is_group = 1 AND (decision IS NULL OR decision <> 'exclude')) AS groups")
            ->first();

        $phones = $creating()->whereNotNull('contact_phone')->distinct()->count('contact_phone');
        $withoutPhone = $creating()->whereNull('contact_phone')->count();
        $existing = (int) DB::table('contacts')
            ->where('status', ContactStatus::Active->value)
            ->whereIn('phone_e164', $creating()->whereNotNull('contact_phone')->select('contact_phone'))
            ->distinct()
            ->count('phone_e164');
        $toSave = $creating()->where('save_contact', true)->count();

        $blocking = 0;
        $warnings = 0;

        // Blocking only for what would be created; undecided rows are reported as such.
        $rows()->whereNotNull('issues')->where(fn (Builder $query) => $query->whereNull('decision')->orWhere('decision', '!=', HistoryImportDecision::Exclude))
            ->select(['id', 'issues', 'decision'])
            ->chunkById(1000, function ($chunk) use (&$blocking, &$warnings): void {
                foreach ($chunk as $row) {
                    $row->decision !== null && $row->blockingIssues() !== [] ? $blocking++ : $warnings++;
                }
            });

        $mappings = HistoryImportBatches::mappings($batch);
        $unmapped = array_values($creating()
            ->selectRaw('DISTINCT COALESCE(source_connection, ?) AS source', [HistoryImportBatches::NO_CONNECTION])
            ->pluck('source')
            ->map(fn (mixed $source): string => (string) $source)
            ->reject(fn (string $source): bool => isset($mappings['connections'][$source]))
            ->all());

        $files = HistoryImportFile::query()->where('data_transfer_id', $batch->id)
            ->groupBy('status')->selectRaw('status, COUNT(*) AS total')->pluck('total', 'status');
        $pendingFiles = (int) ($files[HistoryImportFileStatus::Uploaded->value] ?? 0) + (int) ($files[HistoryImportFileStatus::Parsing->value] ?? 0);

        $summary = [
            'totals' => [
                'conversations' => (int) $totals->conversations,
                'import' => (int) $totals->import,
                'archive' => (int) $totals->archive,
                'exclude' => (int) $totals->exclude,
                'undecided' => (int) $totals->undecided,
            ],
            'contacts' => [
                'involved' => $phones + $withoutPhone,
                'existing' => $existing,
                'new' => max(0, $phones - $existing) + $withoutPhone,
                'to_save' => $toSave,
            ],
            'messages' => [
                'import' => (int) $totals->messages_import,
                'archive' => (int) $totals->messages_archive,
                'total' => (int) $totals->messages_import + (int) $totals->messages_archive,
            ],
            'attachments' => [
                'recoverable' => (int) $totals->recoverable,
                'missing' => (int) $totals->missing,
                'estimated_bytes' => (int) $totals->bytes,
            ],
            'duplicates' => (int) $totals->duplicates,
            'groups' => (int) $totals->groups,
            'issues' => ['blocking' => $blocking, 'warnings' => $warnings],
            'unmapped_connections' => $unmapped,
            'files' => [
                'pending' => $pendingFiles,
                'failed' => (int) ($files[HistoryImportFileStatus::Failed->value] ?? 0),
                'parsed' => (int) ($files[HistoryImportFileStatus::Parsed->value] ?? 0),
            ],
        ];

        $blockers = [];

        if ($summary['totals']['conversations'] === 0) {
            $blockers[] = ['code' => 'empty_batch', 'message' => 'Todavía no hay conversaciones: sube y espera a que se lean los archivos de Whaticket.'];
        }

        if ($pendingFiles > 0) {
            $blockers[] = ['code' => 'files_pending', 'message' => 'Hay archivos que todavía se están leyendo. Espera a que terminen.'];
        }

        if ($summary['totals']['undecided'] > 0) {
            $blockers[] = ['code' => 'undecided', 'message' => "Faltan {$summary['totals']['undecided']} conversación(es) por clasificar. Elige un destino para cada una o aplica una regla."];
        }

        if ($blocking > 0) {
            $blockers[] = ['code' => 'blocking_issues', 'message' => "{$blocking} conversación(es) tienen problemas que impiden importarlas. Resuélvelos o márcalas como «No importar»."];
        }

        if ($summary['attachments']['recoverable'] > 0 && ! $this->mediaDisk->ready()) {
            $blockers[] = ['code' => 'storage_unavailable', 'message' => 'El almacenamiento de archivos no está disponible, así que los archivos recuperables no podrían guardarse. Pide a un administrador que lo revise antes de confirmar.'];
        }

        if ($unmapped !== []) {
            $blockers[] = ['code' => 'unmapped_connections', 'message' => 'Indica a qué conexión pertenece el historial de: '.implode(', ', array_map(fn (string $source): string => $source === '' ? 'sin conexión indicada' : "«{$source}»", $unmapped)).'.'];
        }

        $lastChange = (string) $rows()->max('updated_at');

        return [
            ...$summary,
            'ready' => $blockers === [],
            'blockers' => $blockers,
            'confirmation_token' => hash('sha256', json_encode([$summary, $mappings, $lastChange, $rows()->count()]) ?: ''),
        ];
    }
}

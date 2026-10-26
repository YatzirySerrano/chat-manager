<?php

namespace App\Services\Migration\History;

use App\Enums\AuditAction;
use App\Enums\DataTransferStatus;
use App\Enums\DataTransferType;
use App\Enums\ExportFormat;
use App\Enums\HistoryImportDecision;
use App\Enums\HistoryImportFileKind;
use App\Enums\HistoryImportFileStatus;
use App\Enums\HistoryImportRowState;
use App\Jobs\ExecuteHistoryImport;
use App\Models\Connection;
use App\Models\DataTransfer;
use App\Models\Department;
use App\Models\HistoryImportConversation;
use App\Models\HistoryImportFile;
use App\Models\MessageAttachment;
use App\Models\Tag;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\PlatformSettings;
use App\Services\Storage\MediaDisk;
use App\Support\Queues;
use Carbon\CarbonImmutable;
use Closure;
use DateTimeZone;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Lifecycle of a Whaticket history import batch, stored as a DataTransfer
 * of type history_import (report "whaticket-history"):
 *
 * draft (files are added and read, conversations classified, mappings
 * chosen; nothing is imported) → queued (the admin confirmed the summary
 * numbers) → processing (ExecuteHistoryImport, resumable) → completed
 * (final report) | failed (resumable) | expired (retention reached; files
 * and staging deleted).
 *
 * options: source_instance (which Whaticket installation, part of every
 * source key), timezone (of the dates in the files), mappings (connections,
 * departments, agents, tags: source value => Chat Manager record) and,
 * once confirmed, confirmed_by / confirmed_at / confirmed_summary.
 *
 * Mappings are kept in options (not a table) because they are a handful of
 * values per batch, always edited and read as a whole.
 *
 * @phpstan-type BatchMappings array{connections: array<string, array{target: string, connection_id: int|null}>, departments: array<string, int|null>, agents: array<string, int|null>, tags: array<string, int|null>}
 */
class HistoryImportBatches
{
    public const REPORT = 'whaticket-history';

    /** Mapping key of conversations whose files name no connection. */
    public const NO_CONNECTION = '';

    public const TARGET_CONNECTION = 'connection';

    public const TARGET_PLACEHOLDER = 'placeholder';

    public function __construct(private AuditLogger $audit, private HistoryImportSummary $summary) {}

    public function create(User $actor, ?string $sourceInstance, ?string $timezone, ?string $label): DataTransfer
    {
        $instance = Str::slug((string) $sourceInstance) ?: (string) config('history-import.default_source_instance', 'whaticket');
        $timezone = $timezone !== null && in_array($timezone, DateTimeZone::listIdentifiers(), true)
            ? $timezone
            : (string) app(PlatformSettings::class)->get('general.timezone', 'America/Mexico_City');

        $batch = DataTransfer::query()->create([
            'type' => DataTransferType::HistoryImport,
            'report' => self::REPORT,
            'format' => ExportFormat::Xlsx,
            'status' => DataTransferStatus::Draft,
            'user_id' => $actor->id,
            'disk' => (string) config('imports.disk', 'local'),
            'rows_total' => 0,
            'rows_processed' => 0,
            'rows_failed' => 0,
            'options' => [
                'label' => $label === null ? null : Str::limit(trim(strip_tags($label)), 120, ''),
                'source_instance' => Str::limit($instance, 64, ''),
                'timezone' => $timezone,
                'mappings' => self::emptyMappings(),
            ],
        ]);

        $this->audit->record(AuditAction::HistoryImportCreated, null, [
            'transfer_id' => $batch->id,
            'source_instance' => $batch->options['source_instance'],
            'timezone' => $timezone,
        ], $actor);

        return $batch;
    }

    /**
     * @throws HistoryImportConflict
     */
    public static function ensureDraft(DataTransfer $batch): void
    {
        if ($batch->status !== DataTransferStatus::Draft) {
            throw HistoryImportConflict::locked();
        }
    }

    public static function timezone(DataTransfer $batch): string
    {
        $timezone = $batch->options['timezone'] ?? null;

        return is_string($timezone) && in_array($timezone, DateTimeZone::listIdentifiers(), true) ? $timezone : 'America/Mexico_City';
    }

    public static function sourceInstance(DataTransfer $batch): string
    {
        $instance = $batch->options['source_instance'] ?? null;

        return is_string($instance) && $instance !== '' ? $instance : (string) config('history-import.default_source_instance', 'whaticket');
    }

    /**
     * Start (UTC) of a "Y-m-d" day in the platform's time zone.
     */
    public static function dayStart(string $date): CarbonImmutable
    {
        $timezone = (string) app(PlatformSettings::class)->get('general.timezone', 'America/Mexico_City');

        return CarbonImmutable::createFromFormat('!Y-m-d', substr($date, 0, 10), $timezone)?->utc() ?? CarbonImmutable::now()->startOfDay();
    }

    /**
     * @return BatchMappings
     */
    public static function emptyMappings(): array
    {
        return ['connections' => [], 'departments' => [], 'agents' => [], 'tags' => []];
    }

    /**
     * @return BatchMappings
     */
    public static function mappings(DataTransfer $batch): array
    {
        $stored = (array) ($batch->options['mappings'] ?? []);

        return [
            'connections' => (array) ($stored['connections'] ?? []),
            'departments' => (array) ($stored['departments'] ?? []),
            'agents' => (array) ($stored['agents'] ?? []),
            'tags' => (array) ($stored['tags'] ?? []),
        ];
    }

    /**
     * Save the mappings (validated: existing, not deleted records). Keys
     * not present keep their current mapping; null removes one.
     *
     * @param  array{connections?: array<string, mixed>, departments?: array<string, mixed>, agents?: array<string, mixed>, tags?: array<string, mixed>}  $input
     *
     * @throws ValidationException
     * @throws HistoryImportConflict
     */
    public function updateMappings(DataTransfer $batch, array $input, User $actor): DataTransfer
    {
        $mappings = self::mappings($batch->refresh());

        foreach ((array) ($input['connections'] ?? []) as $source => $target) {
            $source = (string) $source;

            if ($target === null) {
                unset($mappings['connections'][$source]);

                continue;
            }

            $kind = is_array($target) ? (string) ($target['target'] ?? '') : '';
            $connectionId = is_array($target) && is_numeric($target['connection_id'] ?? null) ? (int) $target['connection_id'] : null;

            if ($kind === self::TARGET_CONNECTION) {
                if ($connectionId === null || ! Connection::query()->whereKey($connectionId)->notDeleted()->exists()) {
                    throw ValidationException::withMessages(["mappings.connections.{$source}" => 'Elige una conexión existente.']);
                }
            } elseif ($kind === self::TARGET_PLACEHOLDER) {
                $connectionId = null;
            } else {
                throw ValidationException::withMessages(["mappings.connections.{$source}" => 'Elige una conexión o «Conexión histórica sin credenciales».']);
            }

            $mappings['connections'][$source] = ['target' => $kind, 'connection_id' => $connectionId];
        }

        foreach (['departments' => Department::class, 'agents' => User::class, 'tags' => Tag::class] as $key => $model) {
            foreach ((array) ($input[$key] ?? []) as $source => $target) {
                $source = (string) $source;

                if ($target === null || $target === '') {
                    unset($mappings[$key][$source]);

                    continue;
                }

                if (! is_numeric($target) || ! $model::query()->whereKey((int) $target)->notDeleted()->exists()) {
                    throw ValidationException::withMessages(["mappings.{$key}.{$source}" => 'Elige un registro existente de la lista.']);
                }

                $mappings[$key][$source] = (int) $target;
            }
        }

        self::whileDraft($batch, function (DataTransfer $locked) use ($mappings): void {
            $locked->update(['options' => [...(array) $locked->options, 'mappings' => $mappings]]);
        });
        $batch->refresh();

        $this->audit->record(AuditAction::HistoryImportClassified, null, [
            'transfer_id' => $batch->id,
            'mappings' => [
                'connections' => count($mappings['connections']),
                'departments' => count($mappings['departments']),
                'agents' => count($mappings['agents']),
                'tags' => count($mappings['tags']),
            ],
        ], $actor);

        return $batch;
    }

    /**
     * Source values that need (connections) or accept (the rest) a
     * mapping, with their current target and how many conversations use
     * them.
     *
     * @return array{connections: list<array{source: string, conversations: int, target: string|null, connection_id: int|null}>, departments: list<array{source: string, conversations: int, target_id: int|null}>, agents: list<array{source: string, conversations: int, target_id: int|null}>, tags: list<array{source: string, conversations: int, target_id: int|null}>}
     */
    public function mappingRows(DataTransfer $batch): array
    {
        $mappings = self::mappings($batch);
        $count = fn (string $column) => HistoryImportConversation::query()
            ->where('data_transfer_id', $batch->id)
            ->groupBy($column)
            ->selectRaw(match ($column) {
                'source_connection' => "COALESCE(source_connection, '') AS source, COUNT(*) AS total",
                'source_department' => "COALESCE(source_department, '') AS source, COUNT(*) AS total",
                default => "COALESCE(source_agent, '') AS source, COUNT(*) AS total",
            })
            ->orderBy('source')
            ->limit(500)
            ->get()
            ->map(fn (HistoryImportConversation $row): array => ['source' => (string) $row->getAttribute('source'), 'conversations' => (int) $row->getAttribute('total')]);

        $tags = [];

        HistoryImportConversation::query()->where('data_transfer_id', $batch->id)->whereNotNull('source_tags')->select(['id', 'source_tags'])
            ->chunkById(1000, function ($rows) use (&$tags): void {
                foreach ($rows as $row) {
                    foreach ($row->source_tags ?? [] as $tag) {
                        $tags[(string) $tag] = ($tags[(string) $tag] ?? 0) + 1;
                    }
                }
            });

        ksort($tags);

        return [
            'connections' => array_values($count('source_connection')->map(fn (array $row): array => [
                ...$row,
                'target' => $mappings['connections'][$row['source']]['target'] ?? null,
                'connection_id' => $mappings['connections'][$row['source']]['connection_id'] ?? null,
            ])->all()),
            'departments' => array_values($count('source_department')->filter(fn (array $row): bool => $row['source'] !== '')->map(fn (array $row): array => [...$row, 'target_id' => $mappings['departments'][$row['source']] ?? null])->all()),
            'agents' => array_values($count('source_agent')->filter(fn (array $row): bool => $row['source'] !== '')->map(fn (array $row): array => [...$row, 'target_id' => $mappings['agents'][$row['source']] ?? null])->all()),
            'tags' => array_map(fn (string $source, int $total): array => ['source' => $source, 'conversations' => $total, 'target_id' => $mappings['tags'][$source] ?? null], array_keys($tags), $tags),
        ];
    }

    /**
     * Run a change on the locked batch row, only while it is a draft: every
     * mutation of a batch (classification, mappings, files) is serialized
     * with the confirmation, which takes the same lock.
     *
     * @template TResult
     *
     * @param  Closure(DataTransfer): TResult  $change
     * @return TResult
     *
     * @throws HistoryImportConflict
     */
    public static function whileDraft(DataTransfer $batch, Closure $change): mixed
    {
        return DB::transaction(function () use ($batch, $change): mixed {
            $locked = DataTransfer::query()->lockForUpdate()->findOrFail($batch->id);
            self::ensureDraft($locked);
            $batch->setRawAttributes($locked->getAttributes(), true);

            return $change($locked);
        });
    }

    public static function maxAttempts(): int
    {
        return max(1, (int) config('history-import.max_attempts', 3));
    }

    /**
     * Confirm the batch with the numbers the admin reviewed and queue it.
     * The inventory lock is held too: a rebuild still running (a file just
     * read) is never confirmed half-way.
     *
     * @throws HistoryImportConflict When the summary changed, the inventory is being rebuilt or the batch is not a draft.
     * @throws ValidationException When something still blocks the execution.
     */
    public function confirm(DataTransfer $batch, User $actor, string $token): void
    {
        $lock = Cache::lock(HistoryInventory::lockName($batch), 900);

        if (! $lock->get()) {
            throw HistoryImportConflict::busy();
        }

        try {
            $summary = DB::transaction(function () use ($batch, $actor, $token): array {
                $locked = DataTransfer::query()->lockForUpdate()->findOrFail($batch->id);
                self::ensureDraft($locked);

                $summary = $this->summary->for($locked);

                if (! hash_equals($summary['confirmation_token'], $token)) {
                    throw HistoryImportConflict::summaryChanged();
                }

                if (! $summary['ready']) {
                    throw ValidationException::withMessages(['batch' => $summary['blockers'][0]['message'] ?? 'La importación todavía no está lista.']);
                }

                // Excluded conversations are settled now: nothing is created for them.
                HistoryImportConversation::query()
                    ->where('data_transfer_id', $locked->id)
                    ->where('decision', HistoryImportDecision::Exclude)
                    ->update(['state' => HistoryImportRowState::Skipped, 'processed_at' => now()]);

                $locked->update([
                    'status' => DataTransferStatus::Queued,
                    'rows_total' => $summary['totals']['import'] + $summary['totals']['archive'],
                    'rows_processed' => 0,
                    'rows_failed' => 0,
                    'options' => [
                        ...(array) $locked->options,
                        'confirmed_by' => $actor->id,
                        'confirmed_at' => now()->toIso8601String(),
                        'confirmed_summary' => array_diff_key($summary, ['confirmation_token' => true, 'blockers' => true]),
                    ],
                ]);

                return $summary;
            });
        } finally {
            $lock->release();
        }

        $this->audit->record(AuditAction::HistoryImportRequested, null, [
            'transfer_id' => $batch->id,
            'import' => $summary['totals']['import'],
            'archive' => $summary['totals']['archive'],
            'exclude' => $summary['totals']['exclude'],
            'messages' => $summary['messages']['total'],
            'attachments_recoverable' => $summary['attachments']['recoverable'],
            'attachments_missing' => $summary['attachments']['missing'],
            'contacts_new' => $summary['contacts']['new'],
        ], $actor);

        self::dispatch($batch);
    }

    /**
     * Queue an interrupted, paused or failed batch again, by whoever resumes
     * it (they must hold history-import.execute; the job runs as them). What
     * was done is skipped; conversations that failed fewer than
     * max_attempts times go back to pending, also on a batch that finished
     * "completed_with_errors" (its staging was kept for that).
     *
     * @throws HistoryImportConflict
     */
    public function resume(DataTransfer $batch, User $actor): void
    {
        $ended = DB::transaction(function () use ($batch, $actor): bool {
            $locked = DataTransfer::query()->lockForUpdate()->findOrFail($batch->id);

            if (! in_array($locked->status, [DataTransferStatus::Queued, DataTransferStatus::Processing, DataTransferStatus::Failed, DataTransferStatus::Completed], true)) {
                throw HistoryImportConflict::notResumable();
            }

            $rows = fn () => HistoryImportConversation::query()
                ->where('data_transfer_id', $locked->id)
                ->whereIn('decision', [HistoryImportDecision::Import, HistoryImportDecision::Archive]);
            $hasStaging = HistoryImportFile::query()->where('data_transfer_id', $locked->id)->where('status', '!=', HistoryImportFileStatus::Purged)->exists();

            if ($hasStaging) {
                $rows()->where('state', HistoryImportRowState::Failed)->where('attempts', '<', self::maxAttempts())
                    ->update(['state' => HistoryImportRowState::Pending, 'error' => null, 'processed_at' => null]);
            }

            if (! $rows()->where('state', HistoryImportRowState::Pending)->exists()) {
                throw HistoryImportConflict::notResumable();
            }

            $ended = in_array($locked->status, [DataTransferStatus::Failed, DataTransferStatus::Completed], true);

            $locked->forceFill([
                'status' => DataTransferStatus::Queued,
                'error' => null,
                'finished_at' => null,
                'options' => [
                    ...(array) $locked->options,
                    'confirmed_by' => $actor->id,
                    'resumed_by' => $actor->id,
                    'resumed_at' => now()->toIso8601String(),
                ],
            ])->saveQuietly();

            return $ended;
        });

        // A failed or finished batch has no run left: a lock still there was
        // left by a run killed by its timeout. (A queued / processing batch
        // may be running; its lock is respected.)
        if ($ended) {
            Cache::lock('history-import:execute:'.$batch->id)->forceRelease();
        }

        self::dispatch($batch->refresh());
    }

    public static function dispatch(DataTransfer $batch): void
    {
        ExecuteHistoryImport::dispatch($batch->id)->onQueue(Queues::name(Queues::EXPORTS));
    }

    /**
     * Cancel a draft: its files and staging rows are deleted; nothing was
     * imported.
     *
     * @throws HistoryImportConflict
     */
    public function discard(DataTransfer $batch, User $actor, HistoryFileStore $store): void
    {
        self::whileDraft($batch, function (DataTransfer $locked) use ($store): void {
            $this->purgeStaging($locked, $store, true);
            $locked->update(['status' => DataTransferStatus::Expired, 'error' => 'Importación cancelada.', 'finished_at' => now()]);
        });

        $this->audit->record(AuditAction::HistoryImportDiscarded, null, ['transfer_id' => $batch->id], $actor);
    }

    /**
     * Remove one uploaded file from a draft: its staged messages and stored
     * copy are deleted and the inventory is rebuilt (conversations that only
     * came from it disappear).
     *
     * @throws HistoryImportConflict
     */
    public function removeFile(DataTransfer $batch, HistoryImportFile $file, User $actor, HistoryFileStore $store, HistoryInventory $inventory): void
    {
        self::whileDraft($batch, function (DataTransfer $locked) use ($file, $store): void {
            DB::table('history_import_messages')->where('history_import_file_id', $file->id)->delete();
            $store->purge($file);

            if ($file->kind === HistoryImportFileKind::Chats
                && ! HistoryImportFile::query()->where('data_transfer_id', $locked->id)->where('kind', HistoryImportFileKind::Chats)->whereKeyNot($file->id)->exists()) {
                HistoryImportConversation::query()->where('data_transfer_id', $locked->id)->update(['in_chats_report' => false]);
            }

            $file->delete();
        });

        $this->audit->record(AuditAction::HistoryImportClassified, null, ['transfer_id' => $batch->id, 'file_removed' => $file->id, 'kind' => $file->kind->value], $actor);

        $inventory->rebuild($batch->refresh());
    }

    /**
     * Delete the stored files and the staged messages of a batch (and, for
     * a batch that never ran, its inventory). Copies made to the media disk
     * that no attachment uses are deleted too.
     */
    public function purgeStaging(DataTransfer $batch, HistoryFileStore $store, bool $withInventory): void
    {
        $this->discardUnusedMediaCopies(DB::table('history_import_messages')->where('data_transfer_id', $batch->id));

        HistoryImportFile::query()->where('data_transfer_id', $batch->id)->where('status', '!=', HistoryImportFileStatus::Purged)
            ->each(fn (HistoryImportFile $file) => $store->purge($file));

        DB::table('history_import_messages')->where('data_transfer_id', $batch->id)->delete();
        HistoryMediaLocator::purgeWorkingCopies($batch);

        if ($withInventory) {
            DB::table('history_import_conversations')->where('data_transfer_id', $batch->id)->delete();
        }
    }

    /**
     * Staged messages of conversations that are settled (created, duplicate,
     * excluded): deleted; the ones of failed conversations stay for a retry.
     */
    public function purgeSettledStaging(DataTransfer $batch): void
    {
        $settled = fn () => DB::table('history_import_messages')
            ->where('data_transfer_id', $batch->id)
            ->whereNotIn('history_import_conversation_id', HistoryImportConversation::query()->select('id')
                ->where('data_transfer_id', $batch->id)->where('state', HistoryImportRowState::Failed));

        $this->discardUnusedMediaCopies($settled());
        $settled()->delete();
    }

    /**
     * Rows that produced nothing (excluded, undecided, failed, duplicate)
     * keep only a minimal reference: no names, numbers, participants,
     * original identifiers or error texts.
     */
    public function minimizeSettledRows(DataTransfer $batch, bool $includingFailed = false): void
    {
        HistoryImportConversation::query()
            ->where('data_transfer_id', $batch->id)
            ->whereIn('state', $includingFailed
                ? [HistoryImportRowState::Skipped, HistoryImportRowState::Pending, HistoryImportRowState::Failed, HistoryImportRowState::Duplicate]
                : [HistoryImportRowState::Skipped])
            ->update(self::minimizedColumns());
    }

    /**
     * @return array<string, null>
     */
    public static function minimizedColumns(): array
    {
        return array_fill_keys(['contact_name', 'contact_phone', 'contact_identifier', 'participants', 'business_participants', 'source_details', 'source_agent', 'source_tags', 'error'], null);
    }

    /**
     * Delete media-disk copies of staged messages that no attachment uses.
     *
     * @param  Builder  $staged
     */
    private function discardUnusedMediaCopies($staged): void
    {
        $disk = app(MediaDisk::class);

        $staged->whereNotNull('media_path')->select(['id', 'media_disk', 'media_path'])->orderBy('id')
            ->chunkById(500, function ($rows) use ($disk): void {
                $used = MessageAttachment::query()->whereIn('path', $rows->pluck('media_path')->all())->pluck('path')->flip();

                foreach ($rows as $row) {
                    if (! $used->has($row->media_path)) {
                        $disk->discard((string) $row->media_disk, (string) $row->media_path);
                    }
                }
            });
    }

    /**
     * JSON shape of a batch.
     *
     * @return array<string, mixed>
     */
    public function present(DataTransfer $batch): array
    {
        $files = HistoryImportFile::query()->where('data_transfer_id', $batch->id)->orderBy('id')->get();
        $columns = app(HistoryColumns::class);

        return [
            'id' => $batch->id,
            'label' => $batch->options['label'] ?? null,
            'status' => $batch->status->value,
            'status_label' => $batch->status->label(),
            'source_instance' => self::sourceInstance($batch),
            'timezone' => self::timezone($batch),
            'owner' => $batch->user?->name,
            'created_at' => $batch->created_at?->toIso8601String(),
            'started_at' => $batch->started_at?->toIso8601String(),
            'finished_at' => $batch->finished_at?->toIso8601String(),
            'expires_at' => $batch->expires_at?->toIso8601String(),
            'progress' => [
                'total' => (int) $batch->rows_total,
                'processed' => (int) $batch->rows_processed,
                'failed' => (int) $batch->rows_failed,
            ],
            'error' => $batch->error,
            'result' => $batch->summary,
            'files' => $files->map(fn (HistoryImportFile $file): array => [
                'id' => $file->id,
                'kind' => $file->kind->value,
                'kind_label' => $file->kind->label(),
                'status' => $file->status->value,
                'status_label' => $file->status->label(),
                'name' => $file->original_name,
                'size_bytes' => $file->size_bytes,
                'format' => $file->format_version,
                'verified_format' => false,
                'headers' => $file->headers ?? [],
                'mapping' => $file->column_mapping ?? (object) [],
                'fields' => $file->kind === HistoryImportFileKind::Contacts
                    ? [['value' => 'name', 'label' => 'Nombre', 'required' => false], ['value' => 'phone', 'label' => 'Número', 'required' => true]]
                    : $columns->options($file->kind),
                'unknown_headers' => $file->unknown_headers ?? [],
                'missing_fields' => $file->missing_fields ?? [],
                'media_entries' => count($file->media_index ?? []),
                'rows' => ['total' => $file->rows_total, 'accepted' => $file->rows_accepted, 'rejected' => $file->rows_rejected],
                'issues' => array_map(fn (string $code, array $issue): array => ['code' => $code, ...$issue], array_keys($file->issues ?? []), $file->issues ?? []),
                'error' => $file->error,
                'parsed_at' => $file->parsed_at?->toIso8601String(),
            ])->values()->all(),
        ];
    }
}

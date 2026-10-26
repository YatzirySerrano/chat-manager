<?php

namespace App\Services\Migration\History;

use App\Enums\AuditAction;
use App\Enums\DataTransferStatus;
use App\Enums\DataTransferType;
use App\Enums\HistoryImportFileKind;
use App\Enums\HistoryImportFileStatus;
use App\Enums\MessageType;
use App\Jobs\ParseHistoryImportFile;
use App\Models\DataTransfer;
use App\Models\HistoryImportConversation;
use App\Models\HistoryImportFile;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\Imports\ContactImportAnalyzer;
use App\Services\Migration\WhaticketContactsAdapter;
use App\Support\Queues;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

/**
 * Files of a history import batch: upload (content checks, fingerprint,
 * private storage, column detection) and reading into the staging tables
 * (ParseHistoryImportFile job). Nothing is imported here: reading only
 * fills history_import_messages and the inventory, which the admin then
 * classifies.
 *
 * - chats report (XLSX/CSV): one inventory row per chat with what the
 *   report says (contact, agent, department, connection, tags, dates);
 * - messages CSV: streamed into history_import_messages in batches, grouped
 *   by conversation id (or by contact number and connection when the file
 *   has no conversation id);
 * - media ZIP: entries checked (SafeZip) and indexed by name; a Chat.txt
 *   inside (or uploaded alone as .txt) becomes its own conversation;
 * - contacts CSV (WhaticketContactsAdapter mapping): names for the numbers
 *   of the inventory.
 *
 * Message content is never written to logs or the audit log.
 *
 * @phpstan-import-type ImportMapping from ContactImportAnalyzer
 */
class HistoryFileIngestor
{
    /**
     * MIME types finfo may report for each accepted extension.
     *
     * @var array<string, list<string>>
     */
    private const MIME_TYPES = [
        'csv' => ['text/plain', 'text/csv', 'application/csv', 'text/x-csv', 'application/vnd.ms-excel', 'text/x-c', 'text/html', 'application/octet-stream'],
        'txt' => ['text/plain', 'application/octet-stream'],
        'xlsx' => ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/zip', 'application/octet-stream'],
        'zip' => ['application/zip', 'application/x-zip-compressed', 'application/octet-stream'],
    ];

    public function __construct(
        private TabularFileReader $reader,
        private SafeZip $zip,
        private HistoryColumns $columns,
        private HistoryValues $values,
        private ChatTextParser $chatText,
        private WhaticketContactsAdapter $contactsAdapter,
        private HistoryContactsFile $contactsFile,
        private HistoryFileStore $store,
        private HistoryInventory $inventory,
        private AuditLogger $audit,
    ) {}

    /**
     * Check, store and register a file, then queue its reading.
     *
     * @throws ValidationException When the file is unsafe, invalid or repeated.
     * @throws HistoryImportConflict When the batch is no longer a draft.
     */
    public function add(DataTransfer $batch, UploadedFile $upload, HistoryImportFileKind $kind, User $actor): HistoryImportFile
    {
        HistoryImportBatches::ensureDraft($batch->refresh());

        $extension = strtolower($upload->getClientOriginalExtension());
        $local = (string) $upload->getRealPath();

        if (! in_array($extension, $kind->extensions(), true)) {
            $this->fail('Para «'.$kind->label().'» solo se aceptan archivos '.implode(' o ', array_map(fn (string $value): string => ".{$value}", $kind->extensions())).'.');
        }

        $mime = (string) (new \finfo(FILEINFO_MIME_TYPE))->file($local);

        if (! in_array($mime, self::MIME_TYPES[$extension] ?? [], true)) {
            $this->fail('El contenido del archivo no corresponde a su extensión (.'.$extension.').');
        }

        $headers = null;
        $mapping = null;

        if ($extension === 'xlsx') {
            $this->reader->assertXlsx($local);
        } elseif ($extension === 'zip') {
            $this->zip->entries($local);
        } else {
            $this->reader->assertCsv($local);
        }

        if (in_array($kind, [HistoryImportFileKind::Chats, HistoryImportFileKind::Messages, HistoryImportFileKind::Contacts], true)) {
            $headers = $this->reader->header($local, $extension)['cells'];
            $mapping = $kind === HistoryImportFileKind::Contacts
                ? $this->contactsAdapter->suggestMapping($headers)
                : $this->columns->suggest($kind, $headers);
        }

        $hash = (string) hash_file('sha256', $local);
        $this->assertNotRepeated($batch, $hash);

        $disk = (string) config('imports.disk', 'local');
        $directory = trim((string) config('history-import.directory', 'history-imports'), '/')."/{$batch->id}";
        $stored = $upload->storeAs($directory, Str::uuid()->toString().'.'.$extension, ['disk' => $disk]);

        if ($stored === false) {
            throw new RuntimeException('The history import file could not be stored.');
        }

        try {
            $file = HistoryImportBatches::whileDraft($batch, function (DataTransfer $locked) use ($kind, $upload, $extension, $disk, $stored, $hash, $headers, $mapping, $actor): HistoryImportFile {
                $this->assertNotRepeated($locked, $hash);

                $file = new HistoryImportFile;
                $file->forceFill([
                    'data_transfer_id' => $locked->id,
                    'kind' => $kind,
                    'status' => HistoryImportFileStatus::Uploaded,
                    'original_name' => Str::limit($this->safeName($upload->getClientOriginalName()), 150, ''),
                    'extension' => $extension,
                    'disk' => $disk,
                    'path' => $stored,
                    'size_bytes' => (int) $upload->getSize(),
                    'sha256' => $hash,
                    'format_version' => $this->formatVersion($kind, $extension),
                    'headers' => $headers,
                    'column_mapping' => $mapping,
                    'unknown_headers' => $headers === null ? null : $this->columns->unknownHeaders($mapping, $headers),
                    'missing_fields' => $this->missingFields($kind, $mapping),
                    'uploaded_by_user_id' => $actor->id,
                ])->save();

                return $file;
            });
        } catch (Throwable $exception) {
            Storage::disk($disk)->delete($stored);

            throw $exception;
        }

        // WhatsApp export names carry the customer's name: the audit keeps a fingerprint only.
        $this->audit->record(AuditAction::HistoryImportFileAdded, null, [
            'transfer_id' => $batch->id,
            'file_id' => $file->id,
            'kind' => $kind->value,
            'file_name_sha256' => hash('sha256', $file->original_name),
            'extension' => $extension,
            'size_bytes' => $file->size_bytes,
            'sha256' => $hash,
        ], $actor);

        $this->queueParse($file);

        return $file;
    }

    /**
     * Override the column mapping of a tabular file and read it again.
     *
     * @param  array<string, mixed>  $mapping
     *
     * @throws HistoryImportConflict
     */
    public function remap(HistoryImportFile $file, array $mapping): HistoryImportFile
    {
        $batch = $file->batch()->firstOrFail();
        $headers = array_map('strval', $file->headers ?? []);

        $clean = $file->kind === HistoryImportFileKind::Contacts
            ? array_map(fn (mixed $value): ?int => is_numeric($value) && (int) $value >= 0 && (int) $value < count($headers) ? (int) $value : null, array_merge(array_fill_keys(array_keys(ContactImportAnalyzer::FIELDS), null), array_intersect_key($mapping, ContactImportAnalyzer::FIELDS)))
            : $this->columns->sanitize($file->kind, $mapping, $headers);

        HistoryImportBatches::whileDraft($batch, function () use ($file, $clean, $headers): void {
            $file->forceFill([
                'column_mapping' => $clean,
                'unknown_headers' => $this->columns->unknownHeaders($clean, $headers),
                'missing_fields' => $this->missingFields($file->kind, $clean),
                'status' => HistoryImportFileStatus::Uploaded,
                'error' => null,
            ])->save();

            // A reading in progress with the previous mapping notices the
            // change and discards its result (see parse()); this one follows.
            $this->queueParse($file);
        });

        return $file;
    }

    /**
     * Read a file again (after an interrupted or failed reading).
     *
     * @throws HistoryImportConflict
     */
    public function retry(HistoryImportFile $file): void
    {
        HistoryImportBatches::whileDraft($file->batch()->firstOrFail(), function () use ($file): void {
            $file->forceFill(['status' => HistoryImportFileStatus::Uploaded, 'error' => null])->save();
            $this->queueParse($file);
        });
    }

    public function queueParse(HistoryImportFile $file): void
    {
        ParseHistoryImportFile::dispatch($file->id)->onQueue(Queues::name(Queues::EXPORTS))->afterCommit();
    }

    /**
     * Read a file into the staging tables (called by ParseHistoryImportFile).
     * Reading again replaces what the file produced before.
     */
    public function parse(HistoryImportFile $file): void
    {
        $lock = Cache::lock('history-import:parse:'.$file->id, ParseHistoryImportFile::timeout() + 60);
        $lock->block(ParseHistoryImportFile::timeout(), fn () => $this->parseLocked($file));
    }

    private function parseLocked(HistoryImportFile $file): void
    {
        $file->refresh();
        $batch = $file->batch()->first();

        if ($batch === null || $batch->status !== DataTransferStatus::Draft || $file->path === null) {
            return;
        }

        $mapping = self::mappingHash($file);
        $file->forceFill(['status' => HistoryImportFileStatus::Parsing, 'error' => null])->save();

        try {
            $stats = $this->store->withLocalCopy($file, fn (string $path): array => match ($file->kind) {
                HistoryImportFileKind::Messages => $this->parseMessages($batch, $file, $path),
                HistoryImportFileKind::Chats => $this->parseChats($batch, $file, $path),
                HistoryImportFileKind::Media => $this->parseMedia($batch, $file, $path),
                HistoryImportFileKind::Contacts => $this->countContacts($file, $path),
            });

            if (self::mappingHash($file->fresh() ?? $file) !== $mapping) {
                // The admin changed the mapping meanwhile: the queued reading replaces this one.
                $this->discardStaging($file);

                return;
            }

            // Still "parsing" while the inventory is rebuilt: the summary keeps blocking the confirmation.
            $file->forceFill([
                'rows_total' => $stats['total'],
                'rows_accepted' => $stats['accepted'],
                'rows_rejected' => $stats['rejected'],
                'issues' => $stats['issues'] === [] ? null : $stats['issues'],
                'format_version' => $stats['format'] ?? $file->format_version,
                'media_index' => $stats['media_index'] ?? $file->media_index,
            ])->save();

            $this->inventory->rebuild($batch);

            $file->forceFill(['status' => HistoryImportFileStatus::Parsed, 'parsed_at' => now()])->save();
        } catch (ValidationException $exception) {
            $this->failParse($file, $batch, Str::limit($exception->validator->errors()->first(), 480));
        } catch (Throwable $exception) {
            // Never the message: it may quote content of the file.
            Log::warning('History import file could not be read.', ['file_id' => $file->id, 'exception' => $exception::class]);
            $this->failParse($file, $batch, 'No se pudo leer el archivo por un error inesperado. Revisa que sea una exportación de Whaticket sin modificar.');
        }
    }

    /**
     * A reading that could not finish: the file is failed (it can be read
     * again with «Reintentar» or a new mapping) and leaves nothing staged.
     */
    public function failParse(HistoryImportFile $file, ?DataTransfer $batch, string $reason): void
    {
        $this->discardStaging($file);
        $file->forceFill(['status' => HistoryImportFileStatus::Failed, 'error' => $reason])->save();

        if ($batch !== null) {
            $this->inventory->rebuild($batch);
        }
    }

    private static function mappingHash(HistoryImportFile $file): string
    {
        return hash('sha256', (string) json_encode($file->column_mapping));
    }

    /**
     * Remove the staging rows a file produced.
     */
    public function discardStaging(HistoryImportFile $file): void
    {
        DB::table('history_import_messages')->where('history_import_file_id', $file->id)->delete();
    }

    /**
     * @return array{total: int, accepted: int, rejected: int, issues: array<string, mixed>}
     */
    private function parseMessages(DataTransfer $batch, HistoryImportFile $file, string $path): array
    {
        $this->discardStaging($file);

        $mapping = (array) ($file->column_mapping ?? []);
        $missing = $this->columns->missingFields(HistoryImportFileKind::Messages, $mapping);

        if ($missing !== []) {
            $this->fail('Faltan columnas obligatorias: '.implode(', ', $missing).'. Asígnalas en el mapeo de columnas.');
        }

        $timezone = HistoryImportBatches::timezone($batch);
        $cell = fn (array $cells, string $field): ?string => isset($mapping[$field]) ? ($cells[(int) $mapping[$field]] ?? null) : null;
        $issues = new IssueCounter;
        $buffer = [];
        $total = 0;
        $accepted = 0;
        $inserted = 0;
        $now = now();
        $size = max(100, (int) config('history-import.parse_batch_size', 1000));

        foreach ($this->reader->rows($path, $file->extension) as $line => $cells) {
            $total++;

            if ($this->values->flag($cell($cells, 'is_deleted')) === true) {
                $issues->add('deleted', $line);

                continue;
            }

            $occurredAt = $this->values->date($cell($cells, 'created_at'), $timezone, $cell($cells, 'time'));

            if ($occurredAt === null) {
                $issues->add('invalid_date', $line);

                continue;
            }

            $body = $this->values->body($cell($cells, 'body'));
            $mediaName = $this->values->mediaName($cell($cells, 'media_name'));
            $type = $this->values->type($cell($cells, 'media_type'), $mediaName);

            if ($body['text'] === null && $mediaName === null && $type === MessageType::Text) {
                $issues->add('empty_message', $line);

                continue;
            }

            if ($body['truncated']) {
                $issues->add('truncated', $line);
            }

            $conversationId = $this->values->identifier($cell($cells, 'conversation_id'));
            $identifier = $this->values->identifier($cell($cells, 'contact_number'));
            $connection = $this->values->label($cell($cells, 'connection'));
            $key = $this->conversationKey($conversationId, $identifier, $connection);

            if ($key === null) {
                $issues->add('no_conversation', $line);

                continue;
            }

            $fromMe = $this->values->flag($cell($cells, 'from_me'));

            $buffer[] = [
                'data_transfer_id' => $batch->id,
                'history_import_file_id' => $file->id,
                'conversation_key' => $key,
                'source_message_id' => $this->values->identifier($cell($cells, 'message_id')),
                'line' => $line,
                'occurred_at' => $occurredAt,
                'from_me' => $fromMe,
                'direction_in_file' => $fromMe !== null,
                'is_system' => false,
                'sender_name' => $this->values->label($cell($cells, 'sender')),
                'type' => $type->value,
                'body' => $body['text'],
                'media_name' => $mediaName,
                'media_available' => false,
                'quoted_source_id' => $this->values->identifier($cell($cells, 'quoted_id')),
                'contact_name' => $this->values->label($cell($cells, 'contact_name')),
                'contact_phone' => $this->values->phone($identifier),
                'contact_identifier' => $identifier,
                'source_agent' => $this->values->label($cell($cells, 'user')),
                'source_department' => $this->values->label($cell($cells, 'department')),
                'source_connection' => $connection,
                'created_at' => $now,
            ];
            $accepted++;

            if (count($buffer) >= $size) {
                $inserted += $this->flush($buffer, $issues);
            }
        }

        $inserted += $this->flush($buffer, $issues);

        return ['total' => $total, 'accepted' => $inserted, 'rejected' => $total - $inserted, 'issues' => $issues->toArray(), 'format' => 'whaticket-messages-csv:no-verificado'];
    }

    /**
     * @return array{total: int, accepted: int, rejected: int, issues: array<string, mixed>}
     */
    private function parseChats(DataTransfer $batch, HistoryImportFile $file, string $path): array
    {
        $mapping = (array) ($file->column_mapping ?? []);
        $missing = $this->columns->missingFields(HistoryImportFileKind::Chats, $mapping);

        if ($missing !== []) {
            $this->fail('Faltan columnas obligatorias: '.implode(', ', $missing).'. Asígnalas en el mapeo de columnas.');
        }

        $timezone = HistoryImportBatches::timezone($batch);
        $cell = fn (array $cells, string $field): ?string => isset($mapping[$field]) ? ($cells[(int) $mapping[$field]] ?? null) : null;
        $issues = new IssueCounter;
        $buffer = [];
        $total = 0;
        $accepted = 0;
        $now = now();

        foreach ($this->reader->rows($path, $file->extension) as $line => $cells) {
            $total++;

            $conversationId = $this->values->identifier($cell($cells, 'conversation_id'));
            $chatId = $this->values->identifier($cell($cells, 'chat_id'));
            $identifier = $this->values->identifier($cell($cells, 'contact_number'));
            $connection = $this->values->label($cell($cells, 'connection'));
            $key = $this->conversationKey($conversationId ?? $chatId, $identifier, $connection);

            if ($key === null) {
                $issues->add('no_conversation', $line);

                continue;
            }

            $resolvedAt = $this->values->date($cell($cells, 'resolved_at'), $timezone);
            $status = $this->values->label($cell($cells, 'status'), 40) ?? ($resolvedAt !== null ? 'resuelto' : 'abierto');

            $buffer[$key] = [
                'data_transfer_id' => $batch->id,
                'source_key' => $key,
                'source_kind' => str_starts_with($key, 'ticket:') ? HistoryImportConversation::KIND_TICKET : HistoryImportConversation::KIND_CONTACT,
                'source_ticket_id' => $conversationId ?? $chatId,
                'contact_name' => $this->values->label($cell($cells, 'contact_name')),
                'contact_phone' => $this->values->phone($identifier),
                'contact_identifier' => $identifier,
                'is_group' => $this->values->isGroupIdentifier($identifier),
                'opened_at' => $this->values->date($cell($cells, 'created_at'), $timezone),
                'closed_at' => $resolvedAt,
                'source_agent' => $this->values->label($cell($cells, 'user')),
                'source_department' => $this->values->label($cell($cells, 'department')),
                'source_connection' => $connection,
                'source_tags' => json_encode($this->values->tags($cell($cells, 'tags'), $cell($cells, 'conversation_tags')), JSON_UNESCAPED_UNICODE),
                'source_status' => $status,
                'source_details' => json_encode(array_filter([
                    'conversation_id' => $conversationId,
                    'chat_id' => $chatId,
                    'chat_link' => $this->values->label($cell($cells, 'chat_link'), 255),
                    'number' => $identifier,
                ]), JSON_UNESCAPED_UNICODE),
                'in_chats_report' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ];
            $accepted++;

            if (count($buffer) >= 500) {
                $this->upsertChats($buffer);
            }
        }

        $this->upsertChats($buffer);

        return ['total' => $total, 'accepted' => $accepted, 'rejected' => $total - $accepted, 'issues' => $issues->toArray(), 'format' => 'whaticket-chats-report:no-verificado'];
    }

    /**
     * @return array{total: int, accepted: int, rejected: int, issues: array<string, mixed>, format: string, media_index: array<string, int>|null}
     */
    private function parseMedia(DataTransfer $batch, HistoryImportFile $file, string $path): array
    {
        $this->discardStaging($file);
        $timezone = HistoryImportBatches::timezone($batch);
        $maxText = max(1024, (int) config('history-import.zip.max_chat_text_bytes', 64 * 1024 * 1024));

        if ($file->extension === 'txt') {
            if ((int) filesize($path) > $maxText) {
                $this->fail('El archivo de texto del chat es demasiado grande.');
            }

            $content = (string) file_get_contents($path);
            $stats = $this->stageChatText($batch, $file, $content, self::chatTextKey($content), $timezone, $this->chatText->nameFromFileName($file->original_name));

            return [...$stats, 'media_index' => null];
        }

        $index = [];
        $chats = [];

        foreach ($this->zip->entries($path) as $entry) {
            if (ChatTextParser::isChatText($entry['base'])) {
                $chats[] = $entry;

                continue;
            }

            $index[$entry['base']] ??= $entry['size'];
        }

        $totals = ['total' => count($index), 'accepted' => count($index), 'rejected' => 0, 'issues' => [], 'format' => 'zip:media'];

        foreach ($chats as $chat) {
            $hint = $this->chatText->nameFromFileName(basename($chat['name'])) ?? $this->chatText->nameFromFileName($file->original_name);
            $content = $this->zip->read($path, $chat['index'], $chat['size'], $maxText);
            $stats = $this->stageChatText($batch, $file, $content, self::chatTextKey($content), $timezone, $hint);
            $totals['total'] += $stats['total'];
            $totals['accepted'] += $stats['accepted'];
            $totals['rejected'] += $stats['rejected'];
            $totals['issues'] = array_merge_recursive($totals['issues'], $stats['issues']);
            $totals['format'] = 'zip:'.$stats['format'];
        }

        return [...$totals, 'media_index' => $index];
    }

    /**
     * Chat.txt lines into the staging messages of one conversation.
     *
     * @return array{total: int, accepted: int, rejected: int, issues: array<string, mixed>, format: string}
     */
    private function stageChatText(DataTransfer $batch, HistoryImportFile $file, string $content, string $key, string $timezone, ?string $contactHint): array
    {
        $issues = new IssueCounter;

        // The same chat inside another ZIP of this batch: read once.
        if (DB::table('history_import_messages')->where('data_transfer_id', $batch->id)->where('conversation_key', $key)->where('history_import_file_id', '!=', $file->id)->exists()) {
            $issues->add('duplicate_chat', 0);

            return ['total' => 0, 'accepted' => 0, 'rejected' => 0, 'issues' => $issues->toArray(), 'format' => 'chat-txt:repetido'];
        }

        $parsed = $this->chatText->parse($content, $timezone);
        $buffer = [];
        $accepted = 0;
        $now = now();

        foreach ($parsed['lines'] as $line) {
            if ($line['is_system']) {
                $issues->add('system_line', $line['line']);

                continue;
            }

            if ($line['occurred_at'] === null) {
                $issues->add('invalid_date', $line['line']);

                continue;
            }

            $body = $this->values->body($line['body']);

            if ($body['truncated']) {
                $issues->add('truncated', $line['line']);
            }

            $buffer[] = [
                'data_transfer_id' => $batch->id,
                'history_import_file_id' => $file->id,
                'conversation_key' => $key,
                'source_message_id' => null,
                'line' => $line['line'],
                'occurred_at' => $line['occurred_at'],
                'from_me' => null,
                'direction_in_file' => false,
                'is_system' => false,
                'sender_name' => $line['sender'],
                'type' => $line['type']->value,
                'body' => $body['text'],
                'media_name' => $line['media_name'],
                'media_available' => false,
                'quoted_source_id' => null,
                'contact_name' => $contactHint,
                'contact_phone' => null,
                'contact_identifier' => null,
                'source_agent' => null,
                'source_department' => null,
                'source_connection' => null,
                'created_at' => $now,
            ];
            $accepted++;

            if (count($buffer) >= 1000) {
                $accepted -= $this->flushCounting($buffer, $issues);
            }
        }

        $accepted -= $this->flushCounting($buffer, $issues);

        if ($parsed['unparsed'] > 0) {
            $issues->add('unparsed_line', 0, $parsed['unparsed']);
        }

        $total = count($parsed['lines']);

        return ['total' => $total, 'accepted' => $accepted, 'rejected' => $total - $accepted, 'issues' => $issues->toArray(), 'format' => $parsed['format']];
    }

    /**
     * Contacts files are read again by the inventory every time it is
     * rebuilt (they only give names); here they are only counted.
     *
     * @return array{total: int, accepted: int, rejected: int, issues: array<string, mixed>}
     */
    private function countContacts(HistoryImportFile $file, string $path): array
    {
        $issues = new IssueCounter;
        $total = 0;
        $accepted = 0;

        foreach ($this->contactsFile->rows($file, $path) as $line => $contact) {
            $total++;

            if ($contact === null) {
                $issues->add('invalid_phone', $line);

                continue;
            }

            $accepted++;
        }

        return ['total' => $total, 'accepted' => $accepted, 'rejected' => $total - $accepted, 'issues' => $issues->toArray(), 'format' => 'whaticket-contacts-csv:no-verificado'];
    }

    /**
     * Stable key of a Chat.txt conversation: a fingerprint of its normalized
     * content (the same chat uploaded again, in another batch or another
     * ZIP, is recognized), never the upload's database id.
     */
    public static function chatTextKey(string $content): string
    {
        $normalized = trim(str_replace(["\r\n", "\r", "\xEF\xBB\xBF"], ["\n", "\n", ''], $content));

        return 'chattxt:'.substr(hash('sha256', $normalized), 0, 40);
    }

    /**
     * Conversation key of a row: the source conversation id, or the
     * contact and connection when the file has none.
     */
    private function conversationKey(?string $conversationId, ?string $identifier, ?string $connection): ?string
    {
        if ($conversationId !== null) {
            return 'ticket:'.Str::limit($conversationId, 120, '');
        }

        if ($identifier === null) {
            return null;
        }

        $contact = $this->values->phone($identifier) ?? mb_strtolower($identifier);

        return Str::limit('contact:'.$contact.'|'.mb_strtolower((string) $connection), 160, '');
    }

    /**
     * Write the buffered rows; returns how many were inserted (the same
     * source message id in two exports is kept once and counted apart).
     *
     * @param  list<array<string, mixed>>  $buffer
     */
    private function flush(array &$buffer, IssueCounter $issues): int
    {
        if ($buffer === []) {
            return 0;
        }

        $inserted = DB::table('history_import_messages')->insertOrIgnore($buffer);
        $ignored = count($buffer) - $inserted;

        if ($ignored > 0) {
            $issues->add('duplicate_message', 0, $ignored);
        }

        $buffer = [];

        return $inserted;
    }

    /**
     * flush() for the Chat.txt reader: returns how many rows were NOT inserted.
     *
     * @param  list<array<string, mixed>>  $buffer
     */
    private function flushCounting(array &$buffer, IssueCounter $issues): int
    {
        $count = count($buffer);

        return $count - $this->flush($buffer, $issues);
    }

    /**
     * @param  array<string, array<string, mixed>>  $buffer
     */
    private function upsertChats(array &$buffer): void
    {
        if ($buffer === []) {
            return;
        }

        DB::table('history_import_conversations')->upsert(array_values($buffer), ['data_transfer_id', 'source_key'], [
            'source_ticket_id', 'contact_name', 'contact_phone', 'contact_identifier', 'is_group', 'opened_at', 'closed_at',
            'source_agent', 'source_department', 'source_connection', 'source_tags', 'source_status', 'source_details',
            'in_chats_report', 'updated_at',
        ]);
        $buffer = [];
    }

    /**
     * @param  array<string, int|null>|null  $mapping
     * @return list<string>|null
     */
    private function missingFields(HistoryImportFileKind $kind, ?array $mapping): ?array
    {
        if ($mapping === null) {
            return null;
        }

        if ($kind === HistoryImportFileKind::Contacts) {
            return ($mapping['phone'] ?? null) === null ? ['Número'] : [];
        }

        return $this->columns->missingFields($kind, $mapping);
    }

    private function formatVersion(HistoryImportFileKind $kind, string $extension): string
    {
        return match ($kind) {
            HistoryImportFileKind::Chats => 'whaticket-chats-report:no-verificado',
            HistoryImportFileKind::Messages => 'whaticket-messages-csv:no-verificado',
            HistoryImportFileKind::Contacts => 'whaticket-contacts-csv:no-verificado',
            HistoryImportFileKind::Media => $extension === 'txt' ? 'chat-txt:no-verificado' : 'zip:media',
        };
    }

    /**
     * The same file is never added twice: not to this batch, nor to another
     * batch that is still open or finished.
     *
     * @throws ValidationException
     */
    private function assertNotRepeated(DataTransfer $batch, string $hash): void
    {
        // A copy already purged (its batch finished) does not block: the
        // source keys make a second import of it skip what exists.
        $previous = HistoryImportFile::query()
            ->where('sha256', $hash)
            ->where('status', '!=', HistoryImportFileStatus::Purged)
            ->whereIn('data_transfer_id', DataTransfer::query()
                ->select('id')
                ->where('type', DataTransferType::HistoryImport)
                ->where('status', '!=', DataTransferStatus::Expired))
            ->orderBy('id')
            ->first(['id', 'data_transfer_id']);

        if ($previous === null) {
            return;
        }

        $this->fail($previous->data_transfer_id === $batch->id
            ? 'Este archivo ya está en esta importación.'
            : "Este archivo ya se subió en la importación #{$previous->data_transfer_id}. No se repite para no duplicar el historial.");
    }

    private function safeName(string $name): string
    {
        return trim((string) preg_replace('/[^\pL\pN ._()\-]+/u', '_', $name)) ?: 'archivo';
    }

    /**
     * @throws ValidationException
     */
    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['file' => $message]);
    }
}

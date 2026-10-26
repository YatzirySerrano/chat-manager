<?php

namespace App\Services\Migration\History;

use App\Enums\AttachmentStatus;
use App\Enums\AuditAction;
use App\Enums\ConnectionChannel;
use App\Enums\ConnectionProvider;
use App\Enums\ConversationOrigin;
use App\Enums\ConversationStatus;
use App\Enums\DataTransferStatus;
use App\Enums\HistoryImportDecision;
use App\Enums\HistoryImportRowState;
use App\Enums\MessageDirection;
use App\Enums\MessageStatus;
use App\Exceptions\StorageUnavailable;
use App\Models\Connection;
use App\Models\Conversation;
use App\Models\DataTransfer;
use App\Models\HistoryImportConversation;
use App\Models\HistoryImportFile;
use App\Models\HistoryImportMessage;
use App\Models\HistoryImportSourceKey;
use App\Models\ImportBatchRecord;
use App\Models\Message;
use App\Models\MessageAttachment;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\Meta\MediaStorage;
use App\Services\Storage\MediaDisk;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Executes a confirmed history import batch, one conversation at a time:
 *
 * 1. Its files are copied from the ZIP to the media disk BEFORE any
 *    database transaction (like QueueOutboundMessage), one by one, and the
 *    result is written on the staged message right away (media_path, or
 *    media_error: historical_missing, historical_ambiguous,
 *    zip_entry_invalid, too_large, type_not_allowed). A run that reaches
 *    its deadline in the middle of a conversation with thousands of photos
 *    stops there and the next run continues: nothing is uploaded twice and
 *    nothing is orphaned (staged copies that never end up in an attachment
 *    are deleted when the staging is purged). Archived history keeps files
 *    above the WhatsApp send limits (history-import.max_media_bytes), always
 *    with the type detected from the content.
 * 2. One short transaction creates the conversation (closed, origin
 *    "whaticket", history_only_at for archived history, closed_at = the
 *    historical end, never now), its messages in chronological order
 *    (created_at and sent_at = the original time; inbound "received",
 *    outbound "sent" with metadata.import, never a delivery or read state;
 *    no ids in whatsapp_message_id), its attachments, the mapped tags, the
 *    source key (idempotency) and the batch record — or nothing at all.
 *
 * A conversation keyed by contact (exports without a conversation id) that
 * an earlier batch already imported is extended with the messages it did
 * not have yet (message source keys), never duplicated.
 *
 * The media disk is checked before the run; if it fails during the run the
 * batch is paused (HistoryImportPaused) and the conversation in progress
 * stays pending. Nothing is dispatched: no MessageRecorded /
 * ConversationChanged events, notifications, automations or metrics;
 * last_inbound_at stays empty (no 24 hour window). Per-conversation errors
 * are isolated, retried at most history-import.max_attempts times and
 * reported without message content.
 */
class HistoryImportExecutor
{
    private const MESSAGE_CHUNK = 500;

    /**
     * @var array<string, int>
     */
    private array $connections = [];

    /**
     * @var array<int, bool> File id => whether it is a ZIP (its Chat.txt media is in it).
     */
    private array $zipFiles = [];

    public function __construct(
        private HistoryContactResolver $contacts,
        private HistoryMediaLocator $media,
        private MediaStorage $mediaStorage,
        private MediaDisk $mediaDisk,
        private AuditLogger $audit,
        private HistoryFileStore $store,
        private HistoryImportBatches $batches,
    ) {}

    /**
     * Process pending conversations until done, the limit or the deadline.
     * Returns true when the batch finished.
     *
     * @throws HistoryImportPaused When the media disk is not available (nothing of the conversation in progress is lost).
     */
    public function run(DataTransfer $batch, User $actor, ?int $limit = null, ?float $deadline = null): bool
    {
        $this->prepareConnections($batch);

        if ($this->needsMedia($batch)) {
            try {
                $this->mediaDisk->assertReady();
            } catch (StorageUnavailable $exception) {
                throw HistoryImportPaused::storage($exception);
            }
        }

        $this->zipFiles = HistoryImportFile::query()->where('data_transfer_id', $batch->id)->pluck('extension', 'id')
            ->map(fn (mixed $extension): bool => $extension === 'zip')->all();
        $this->media->open($batch);
        $done = 0;
        $chunk = max(1, (int) config('history-import.execute_chunk_size', 50));

        try {
            while (true) {
                $rows = HistoryImportConversation::query()
                    ->where('data_transfer_id', $batch->id)
                    ->where('state', HistoryImportRowState::Pending)
                    ->whereIn('decision', [HistoryImportDecision::Import, HistoryImportDecision::Archive])
                    ->orderBy('id')
                    ->limit($chunk)
                    ->get();

                if ($rows->isEmpty()) {
                    break;
                }

                foreach ($rows as $row) {
                    $completed = $this->execute($batch, $row, $actor, $deadline);
                    $done++;

                    if (! $completed || ($limit !== null && $done >= $limit) || ($deadline !== null && microtime(true) >= $deadline)) {
                        $this->progress($batch);

                        return false;
                    }
                }

                $this->progress($batch);
            }
        } finally {
            $this->media->close();
        }

        $this->finish($batch, $actor);

        return true;
    }

    /**
     * Mark the batch failed (a problem outside one conversation); it stays
     * resumable and its staging is kept until it expires.
     */
    public function fail(DataTransfer $batch, ?User $actor, string $reason): void
    {
        $batch->forceFill(['status' => DataTransferStatus::Failed, 'error' => $reason, 'finished_at' => now()])->saveQuietly();

        $this->audit->record(AuditAction::HistoryImportFailed, null, ['transfer_id' => $batch->id, 'reason' => $reason], $actor);
    }

    /**
     * Whether pending conversations have files to copy.
     */
    private function needsMedia(DataTransfer $batch): bool
    {
        return HistoryImportConversation::query()
            ->where('data_transfer_id', $batch->id)
            ->where('state', HistoryImportRowState::Pending)
            ->whereIn('decision', [HistoryImportDecision::Import, HistoryImportDecision::Archive])
            ->where('attachments_available', '>', 0)
            ->exists();
    }

    /**
     * @return bool False when the deadline was reached while copying its
     *              files (the conversation stays pending; the next run goes on).
     *
     * @throws HistoryImportPaused
     */
    private function execute(DataTransfer $batch, HistoryImportConversation $row, User $actor, ?float $deadline): bool
    {
        try {
            // Outside any transaction: a placeholder connection created here
            // survives a failed conversation, and the file copies are slow.
            $connectionId = $this->connectionFor($batch, $row->source_connection, $actor);

            if (! $this->stageMedia($row, $deadline)) {
                return false;
            }

            DB::transaction(function () use ($batch, $row, $actor, $connectionId): void {
                $locked = HistoryImportConversation::query()->lockForUpdate()->find($row->id);

                if ($locked === null || $locked->state !== HistoryImportRowState::Pending || $locked->decision === null || ! $locked->decision->createsConversation()) {
                    return;
                }

                $existing = $this->conversationKey($batch, $locked);

                if ($existing !== null) {
                    $this->extendOrSkip($batch, $locked, $existing);

                    return;
                }

                if ($locked->blockingIssues() !== []) {
                    throw new HistoryImportRowFailed($locked->blockingIssues()[0]['message']);
                }

                ['contact' => $contact, 'created' => $createdContact] = $this->contacts->resolve($batch, $locked, $actor);
                $conversation = $this->createConversation($batch, $locked, $connectionId, $contact->id);
                $counts = $this->copyMessages($locked, $conversation, $locked->source_kind === HistoryImportConversation::KIND_CONTACT);
                $this->attachTags($batch, $locked, $conversation, $actor);

                HistoryImportSourceKey::query()->insert([
                    'source_system' => HistoryImportSourceKey::SYSTEM_WHATICKET,
                    'source_instance' => HistoryImportBatches::sourceInstance($batch),
                    'source_kind' => HistoryImportSourceKey::KIND_CONVERSATION,
                    'source_id' => $locked->source_key,
                    'target_type' => $conversation->getMorphClass(),
                    'target_id' => $conversation->id,
                    'data_transfer_id' => $batch->id,
                    // Identifiers of the source only: never names or numbers (retention).
                    'details' => json_encode(array_filter([
                        'kind' => $locked->source_kind,
                        'ticket_id' => $locked->source_ticket_id,
                        'conversation_id' => $locked->source_details['conversation_id'] ?? null,
                        'chat_id' => $locked->source_details['chat_id'] ?? null,
                        'chat_link' => $locked->source_details['chat_link'] ?? null,
                        'status' => $locked->source_status,
                    ]), JSON_UNESCAPED_UNICODE),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                ImportBatchRecord::query()->create([
                    'data_transfer_id' => $batch->id,
                    'record_type' => $conversation->getMorphClass(),
                    'record_id' => $conversation->id,
                    'action' => ImportBatchRecord::ACTION_CREATED,
                    'applied_values' => ['destination' => $locked->decision->value, ...$counts],
                ]);

                if ($createdContact) {
                    ImportBatchRecord::query()->create([
                        'data_transfer_id' => $batch->id,
                        'record_type' => $contact->getMorphClass(),
                        'record_id' => $contact->id,
                        'action' => ImportBatchRecord::ACTION_CREATED,
                        'applied_values' => ['provisional' => ! $contact->isSaved()],
                    ]);
                }

                $locked->forceFill([
                    'state' => HistoryImportRowState::Done,
                    'result_conversation_id' => $conversation->id,
                    'result_contact_id' => $contact->id,
                    'error' => null,
                    'processed_at' => now(),
                ])->save();
            });
        } catch (HistoryImportPaused $paused) {
            throw $paused;
        } catch (StorageUnavailable $exception) {
            throw HistoryImportPaused::storage($exception);
        } catch (Throwable $exception) {
            if (! $exception instanceof HistoryImportRowFailed) {
                // The exception class only: its message may quote content of the files.
                Log::warning('History import conversation failed.', ['inventory_id' => $row->id, 'exception' => $exception::class]);
            }

            HistoryImportConversation::query()->whereKey($row->id)->update([
                'state' => HistoryImportRowState::Failed,
                'attempts' => DB::raw('attempts + 1'),
                'error' => $exception instanceof HistoryImportRowFailed
                    ? Str::limit($exception->getMessage(), 250)
                    : 'No se pudo importar esta conversación por un error inesperado. Puedes reintentarla.',
                'processed_at' => now(),
            ]);
        }

        return true;
    }

    /**
     * Copy (or settle as unavailable) every file of the conversation not
     * handled yet, writing each result on its staged message.
     *
     * @return bool False when the deadline was reached.
     *
     * @throws StorageUnavailable
     */
    private function stageMedia(HistoryImportConversation $row, ?float $deadline): bool
    {
        $pending = HistoryImportMessage::query()
            ->where('history_import_conversation_id', $row->id)
            ->where('is_system', false)
            ->whereIn('type', HistoryInventory::MEDIA_TYPES)
            ->whereNull('media_path')
            ->whereNull('media_error');

        foreach ($pending->select(['id', 'history_import_file_id', 'media_name', 'media_available', 'type'])->lazyById(200) as $staged) {
            $entry = $staged->media_available ? $this->media->find(($this->zipFiles[$staged->history_import_file_id] ?? false) ? $staged->history_import_file_id : null, $staged->media_name) : null;

            $result = match (true) {
                $entry === HistoryMediaLocator::AMBIGUOUS => ['media_error' => 'historical_ambiguous'],
                is_array($entry) => $this->copy($entry),
                default => ['media_error' => 'historical_missing'],
            };

            HistoryImportMessage::query()->whereKey($staged->id)->update($result);

            if ($deadline !== null && microtime(true) >= $deadline) {
                return false;
            }
        }

        return true;
    }

    /**
     * Copy one ZIP entry to the media disk, validated by content: a type
     * WhatsApp or the web chat accept, up to history-import.max_media_bytes
     * (the WhatsApp send limits do not apply to history).
     *
     * @param  array{file: int, index: int, size: int}  $entry
     * @return array<string, mixed> Columns of the staged message.
     *
     * @throws StorageUnavailable
     */
    private function copy(array $entry): array
    {
        if ($entry['size'] > max(1, (int) config('history-import.max_media_bytes', 100 * 1024 * 1024))) {
            return ['media_error' => 'too_large'];
        }

        try {
            $temporary = $this->media->extract($entry);
        } catch (ValidationException) {
            return ['media_error' => 'zip_entry_invalid'];
        }

        try {
            $size = (int) filesize($temporary);
            $mime = $this->mediaStorage->detect($temporary);

            if ($size <= 0) {
                return ['media_error' => 'zip_entry_invalid'];
            }

            if (! in_array($mime, $this->historicalMimes(), true)) {
                return ['media_error' => 'type_not_allowed'];
            }

            $stored = $this->mediaDisk->putFile($temporary, 'media/history/'.now()->format('Y').'/'.now()->format('m'));

            return [
                'media_disk' => $stored['disk'],
                'media_path' => $stored['path'],
                'media_mime' => $mime,
                'media_size' => $size,
                'media_sha256' => (string) hash_file('sha256', $temporary),
            ];
        } finally {
            @unlink($temporary);
        }
    }

    /**
     * Types a historical file may have (detected from its content): every
     * type the WhatsApp table accepts, plus common phone formats.
     *
     * @return list<string>
     */
    private function historicalMimes(): array
    {
        $mimes = ['image/gif', 'image/webp', 'image/heic', 'video/quicktime', 'video/webm', 'audio/wav', 'audio/webm', 'text/csv'];

        foreach ((array) config('whatsapp.media.types', []) as $type) {
            foreach ((array) ($type['mimes'] ?? []) as $mime) {
                $mimes[] = (string) $mime;
            }
        }

        return array_values(array_unique($mimes));
    }

    /**
     * The conversation an earlier run or batch created for this source key.
     */
    private function conversationKey(DataTransfer $batch, HistoryImportConversation $row): ?HistoryImportSourceKey
    {
        return HistoryImportSourceKey::query()
            ->where('source_system', HistoryImportSourceKey::SYSTEM_WHATICKET)
            ->where('source_instance', HistoryImportBatches::sourceInstance($batch))
            ->where('source_kind', HistoryImportSourceKey::KIND_CONVERSATION)
            ->where('source_id', $row->source_key)
            ->first();
    }

    /**
     * Already imported: a contact-keyed conversation gets the messages it did
     * not have (later exports of the same number); anything else is a
     * duplicate.
     */
    private function extendOrSkip(DataTransfer $batch, HistoryImportConversation $row, HistoryImportSourceKey $key): void
    {
        $conversation = $key->target_type === 'conversation' ? Conversation::query()->lockForUpdate()->find($key->target_id) : null;

        if ($conversation === null || $row->source_kind !== HistoryImportConversation::KIND_CONTACT) {
            $row->forceFill(['state' => HistoryImportRowState::Duplicate, 'result_conversation_id' => $conversation?->id, 'processed_at' => now()])->save();

            return;
        }

        $counts = $this->copyMessages($row, $conversation, true);

        if ($counts['messages'] === 0) {
            $row->forceFill(['state' => HistoryImportRowState::Duplicate, 'result_conversation_id' => $conversation->id, 'processed_at' => now()])->save();

            return;
        }

        $last = Message::query()->where('conversation_id', $conversation->id)->max('created_at');
        $first = Message::query()->where('conversation_id', $conversation->id)->min('created_at');
        $conversation->forceFill([
            'last_message_at' => $last,
            'closed_at' => $conversation->closed_at !== null && $last !== null && $conversation->closed_at->greaterThan(CarbonImmutable::parse($last)) ? $conversation->closed_at : $last,
            'created_at' => $first !== null && $conversation->created_at !== null && CarbonImmutable::parse($first)->lessThan($conversation->created_at) ? $first : $conversation->created_at,
        ])->save();

        ImportBatchRecord::query()->updateOrCreate(
            ['data_transfer_id' => $batch->id, 'record_type' => $conversation->getMorphClass(), 'record_id' => $conversation->id],
            ['action' => ImportBatchRecord::ACTION_FILLED, 'applied_values' => ['extended' => true, ...$counts]],
        );

        $row->forceFill(['state' => HistoryImportRowState::Done, 'result_conversation_id' => $conversation->id, 'result_contact_id' => $conversation->contact_id, 'error' => null, 'processed_at' => now()])->save();
    }

    private function createConversation(DataTransfer $batch, HistoryImportConversation $row, int $connectionId, int $contactId): Conversation
    {
        $mappings = HistoryImportBatches::mappings($batch);
        $start = $row->opened_at ?? $row->first_message_at ?? $row->last_message_at ?? CarbonImmutable::now();
        $lastMessage = $row->last_message_at;
        $end = $row->closed_at !== null && ($lastMessage === null || $row->closed_at->greaterThanOrEqualTo($lastMessage)) ? $row->closed_at : ($lastMessage ?? $start);

        if ($row->first_message_at !== null && $row->first_message_at->lessThan($start)) {
            $start = $row->first_message_at;
        }

        // Department and agent are kept on archived history too (the archive
        // is searched by them); ConversationAccess and Contact::visibleTo
        // never let them widen anybody's reach to history-only rows.
        $operational = $row->decision === HistoryImportDecision::Import;

        $conversation = new Conversation;
        $conversation->forceFill([
            'connection_id' => $connectionId,
            'contact_id' => $contactId,
            'department_id' => $mappings['departments'][(string) $row->source_department] ?? null,
            'assigned_user_id' => $mappings['agents'][(string) $row->source_agent] ?? null,
            'status' => ConversationStatus::Closed,
            'last_message_at' => $lastMessage ?? $end,
            // Never set: it would open a 24 hour window (CustomerServiceWindow ignores imported rows anyway).
            'last_inbound_at' => null,
            'last_outbound_at' => null,
            'first_response_at' => null,
            'closed_at' => $end,
            'closed_by_user_id' => null,
            'origin' => ConversationOrigin::Whaticket,
            'history_only_at' => $operational ? null : now(),
            'imported_by_transfer_id' => $batch->id,
            'created_at' => $start,
            'updated_at' => now(),
        ]);
        // created_at is the historical start (Eloquent keeps a dirty created_at).
        $conversation->save();

        return $conversation;
    }

    /**
     * Insert the staged messages in chronological order with their
     * attachments (files already copied by stageMedia). With $keyed, each
     * message gets a source key and the ones an earlier import already has
     * are skipped.
     *
     * @return array{messages: int, attachments_available: int, attachments_missing: int}
     */
    private function copyMessages(HistoryImportConversation $row, Conversation $conversation, bool $keyed): array
    {
        $counts = ['messages' => 0, 'attachments_available' => 0, 'attachments_missing' => 0];
        $references = [];
        $quotes = [];
        $lastId = 0;
        $lastAt = null;
        $instance = (string) DataTransfer::query()->whereKey($row->data_transfer_id)->value('options->source_instance');

        while (true) {
            /** @var Collection<int, HistoryImportMessage> $chunk */
            $chunk = HistoryImportMessage::query()
                ->where('history_import_conversation_id', $row->id)
                ->where('is_system', false)
                ->when($lastAt !== null, fn ($query) => $query->where(fn ($after) => $after->where('occurred_at', '>', $lastAt)->orWhere(fn ($same) => $same->where('occurred_at', $lastAt)->where('id', '>', $lastId))))
                ->orderBy('occurred_at')
                ->orderBy('id')
                ->limit(self::MESSAGE_CHUNK)
                ->get();

            if ($chunk->isEmpty()) {
                break;
            }

            $last = $chunk->last();
            $lastAt = $last->occurred_at;
            $lastId = $last->id;

            $messageKeys = [];

            if ($keyed) {
                foreach ($chunk as $staged) {
                    $messageKeys[$staged->id] = $this->messageKey($row, $staged);
                }

                $known = HistoryImportSourceKey::query()
                    ->where('source_system', HistoryImportSourceKey::SYSTEM_WHATICKET)
                    ->where('source_instance', $instance)
                    ->where('source_kind', HistoryImportSourceKey::KIND_MESSAGE)
                    ->whereIn('source_id', array_values($messageKeys))
                    ->pluck('source_id')
                    ->flip();

                $chunk = $chunk->reject(fn (HistoryImportMessage $staged): bool => $known->has($messageKeys[$staged->id]))->values();

                if ($chunk->isEmpty()) {
                    continue;
                }
            }

            $now = now();
            $inserts = [];
            $byReference = [];

            foreach ($chunk as $staged) {
                $reference = (string) Str::uuid();
                $outbound = $staged->from_me === true;
                $inserts[] = [
                    'conversation_id' => $conversation->id,
                    'whatsapp_message_id' => null,
                    'client_reference' => $reference,
                    'direction' => $outbound ? MessageDirection::Outbound->value : MessageDirection::Inbound->value,
                    'type' => $staged->type,
                    // A neutral historical state: never delivered / read, nothing to send.
                    'status' => $outbound ? MessageStatus::Sent->value : MessageStatus::Received->value,
                    'body' => $staged->body,
                    'sender_user_id' => null,
                    'reply_to_message_id' => null,
                    'metadata' => json_encode(['import' => array_filter([
                        'source' => 'whaticket',
                        'batch_id' => $row->data_transfer_id,
                        'kind' => $row->source_kind,
                        'source_id' => $staged->source_message_id,
                        'sender_name' => $staged->sender_name,
                        'from_me' => $outbound,
                        'quoted_source_id' => $staged->quoted_source_id,
                    ], fn (mixed $value): bool => $value !== null)], JSON_UNESCAPED_UNICODE),
                    'sent_at' => $staged->occurred_at,
                    'delivery_attempts' => 0,
                    'created_at' => $staged->occurred_at,
                    'updated_at' => $now,
                ];
                $byReference[$reference] = $staged;
            }

            DB::table('messages')->insert($inserts);
            $ids = Message::query()->where('conversation_id', $conversation->id)->whereIn('client_reference', array_keys($byReference))->pluck('id', 'client_reference');
            $keys = [];

            foreach ($byReference as $reference => $staged) {
                $messageId = (int) $ids[$reference];
                $counts['messages']++;

                if ($staged->source_message_id !== null) {
                    $references[$staged->source_message_id] = $messageId;
                }

                if ($staged->quoted_source_id !== null) {
                    $quotes[$messageId] = $staged->quoted_source_id;
                }

                if (in_array($staged->type, HistoryInventory::MEDIA_TYPES, true)) {
                    $available = $this->attach($messageId, $staged);
                    $counts[$available ? 'attachments_available' : 'attachments_missing']++;
                }

                if ($keyed) {
                    $keys[] = [
                        'source_system' => HistoryImportSourceKey::SYSTEM_WHATICKET,
                        'source_instance' => $instance,
                        'source_kind' => HistoryImportSourceKey::KIND_MESSAGE,
                        'source_id' => $messageKeys[$staged->id],
                        'target_type' => 'message',
                        'target_id' => $messageId,
                        'data_transfer_id' => $row->data_transfer_id,
                        'details' => null,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }
            }

            if ($keys !== []) {
                HistoryImportSourceKey::query()->insert($keys);
            }
        }

        foreach ($quotes as $messageId => $quoted) {
            if (isset($references[$quoted])) {
                DB::table('messages')->where('id', $messageId)->update(['reply_to_message_id' => $references[$quoted]]);
            }
        }

        return $counts;
    }

    /**
     * Stable key of a message of a contact-keyed conversation: its source
     * id, else a fingerprint of when, who, what (never the text itself).
     */
    private function messageKey(HistoryImportConversation $row, HistoryImportMessage $staged): string
    {
        $identity = $staged->source_message_id
            ?? implode('|', [$staged->occurred_at?->getTimestamp(), (int) $staged->from_me, $staged->type, hash('sha256', (string) $staged->body), (string) $staged->media_name]);

        return hash('sha256', $row->source_key.'#'.$identity);
    }

    /**
     * The attachment of a message, from what stageMedia left on it.
     */
    private function attach(int $messageId, HistoryImportMessage $staged): bool
    {
        $available = $staged->media_path !== null;
        $attachment = new MessageAttachment;
        $attachment->forceFill([
            'message_id' => $messageId,
            'disk' => $staged->media_disk ?? $this->mediaDisk->name(),
            'path' => $staged->media_path,
            'mime_type' => $staged->media_mime ?? $this->guessMime($staged->media_name),
            'file_name' => $staged->media_name,
            'size_bytes' => $staged->media_size,
            'sha256' => $staged->media_sha256,
            'status' => $available ? AttachmentStatus::Available : AttachmentStatus::Unavailable,
            'error_code' => $available ? null : ($staged->media_error ?? 'historical_missing'),
            'downloaded_at' => $available ? now() : null,
        ])->save();

        return $available;
    }

    private function attachTags(DataTransfer $batch, HistoryImportConversation $row, Conversation $conversation, User $actor): void
    {
        $mapping = HistoryImportBatches::mappings($batch)['tags'];
        $tagIds = [];

        foreach ($row->source_tags ?? [] as $tag) {
            if (isset($mapping[(string) $tag])) {
                $tagIds[(int) $mapping[(string) $tag]] = true;
            }
        }

        if ($tagIds === []) {
            return;
        }

        DB::table('conversation_tag')->insertOrIgnore(array_map(fn (int $tagId): array => [
            'conversation_id' => $conversation->id,
            'tag_id' => $tagId,
            'tagged_by_user_id' => $actor->id,
            'created_at' => now(),
        ], array_keys($tagIds)));
    }

    /**
     * Resolve the connection mappings to existing connections.
     */
    private function prepareConnections(DataTransfer $batch): void
    {
        $this->connections = [];

        foreach (HistoryImportBatches::mappings($batch)['connections'] as $source => $target) {
            if ($target['target'] === HistoryImportBatches::TARGET_CONNECTION && is_int($target['connection_id'] ?? null)) {
                $this->connections[(string) $source] = $target['connection_id'];
            }
        }
    }

    /**
     * Connection of a source value. A "placeholder" mapping creates (once per
     * source value and batch, only when a conversation needs it) a
     * historical connection without credentials that is inactive and
     * logically deleted: it can never send, receive, be reached by webhooks
     * or the API, while its history keeps resolving (deleted connections keep
     * their conversations).
     *
     * @throws HistoryImportRowFailed When the source has no mapping.
     */
    private function connectionFor(DataTransfer $batch, ?string $source, User $actor): int
    {
        $source ??= HistoryImportBatches::NO_CONNECTION;

        if (isset($this->connections[$source])) {
            return $this->connections[$source];
        }

        $mapping = HistoryImportBatches::mappings($batch)['connections'][$source] ?? null;

        if ($mapping === null || $mapping['target'] !== HistoryImportBatches::TARGET_PLACEHOLDER) {
            throw new HistoryImportRowFailed('La conexión de esta conversación no tiene asignación.');
        }

        $batch->refresh();
        $placeholders = (array) ($batch->options['placeholder_connections'] ?? []);
        $existing = isset($placeholders[$source]) ? Connection::query()->find((int) $placeholders[$source]) : null;

        if ($existing === null) {
            $existing = new Connection([
                'name' => Str::limit('Whaticket (histórico) · '.($source === HistoryImportBatches::NO_CONNECTION ? 'sin conexión indicada' : $source), 120, ''),
                'channel' => ConnectionChannel::Whatsapp,
                'provider' => ConnectionProvider::MetaCloudApi,
            ]);
            $existing->forceFill([
                'is_active' => false,
                'deleted_at' => now(),
                'created_by_user_id' => $actor->id,
            ])->save();
            $placeholders[$source] = $existing->id;
            $batch->forceFill(['options' => [...(array) $batch->options, 'placeholder_connections' => $placeholders]])->saveQuietly();
        }

        return $this->connections[$source] = $existing->id;
    }

    private function progress(DataTransfer $batch): void
    {
        $counts = $this->stateCounts($batch);

        $batch->forceFill([
            'rows_processed' => $counts['done'] + $counts['duplicate'] + $counts['failed'],
            'rows_failed' => $counts['failed'],
        ])->saveQuietly();
    }

    /**
     * @return array{done: int, duplicate: int, failed: int, skipped: int, pending: int}
     */
    private function stateCounts(DataTransfer $batch): array
    {
        $states = HistoryImportConversation::query()
            ->where('data_transfer_id', $batch->id)
            ->groupBy('state')
            ->selectRaw('state, COUNT(*) AS total')
            ->pluck('total', 'state');

        return [
            'done' => (int) ($states[HistoryImportRowState::Done->value] ?? 0),
            'duplicate' => (int) ($states[HistoryImportRowState::Duplicate->value] ?? 0),
            'failed' => (int) ($states[HistoryImportRowState::Failed->value] ?? 0),
            'skipped' => (int) ($states[HistoryImportRowState::Skipped->value] ?? 0),
            'pending' => (int) ($states[HistoryImportRowState::Pending->value] ?? 0),
        ];
    }

    /**
     * Final report and minimization. Without failed conversations the whole
     * staging is purged. With failed conversations that can still be
     * retried, the batch ends "completed_with_errors": their staging and the
     * files stay (until retried, discarded or expired) and everything else is
     * purged.
     */
    private function finish(DataTransfer $batch, User $actor): void
    {
        $counts = $this->stateCounts($batch);
        $conversations = Conversation::query()->where('imported_by_transfer_id', $batch->id);
        $retryable = HistoryImportConversation::query()->where('data_transfer_id', $batch->id)->where('state', HistoryImportRowState::Failed)
            ->where('attempts', '<', HistoryImportBatches::maxAttempts())->count();

        $summary = [
            'outcome' => $counts['failed'] > 0 ? 'completed_with_errors' : 'completed',
            'imported' => (clone $conversations)->whereNull('history_only_at')->count(),
            'archived' => (clone $conversations)->whereNotNull('history_only_at')->count(),
            'duplicates' => $counts['duplicate'],
            'failed' => $counts['failed'],
            'retryable' => $retryable,
            'excluded' => $counts['skipped'],
            'messages' => Message::query()->whereIn('conversation_id', (clone $conversations)->select('id'))->count(),
            'attachments_available' => MessageAttachment::query()->whereIn('message_id', Message::query()->select('id')->whereIn('conversation_id', (clone $conversations)->select('id')))->where('status', AttachmentStatus::Available)->count(),
            'attachments_missing' => MessageAttachment::query()->whereIn('message_id', Message::query()->select('id')->whereIn('conversation_id', (clone $conversations)->select('id')))->where('status', AttachmentStatus::Unavailable)->count(),
            'contacts_created' => ImportBatchRecord::query()->where('data_transfer_id', $batch->id)->where('record_type', 'contact')->count(),
            'contacts_saved' => HistoryImportConversation::query()->where('data_transfer_id', $batch->id)->where('state', HistoryImportRowState::Done)->where('save_contact', true)->count(),
        ];

        if ($retryable > 0) {
            $this->batches->purgeSettledStaging($batch);
        } else {
            $this->batches->purgeStaging($batch, $this->store, false);
        }

        $this->batches->minimizeSettledRows($batch);

        $batch->forceFill([
            'status' => DataTransferStatus::Completed,
            'summary' => $summary,
            'error' => null,
            'rows_processed' => $counts['done'] + $counts['duplicate'] + $counts['failed'],
            'rows_failed' => $counts['failed'],
            'finished_at' => now(),
            'expires_at' => now()->addDays(max(1, (int) config('history-import.retention_days', 30))),
        ])->saveQuietly();

        $this->audit->record(AuditAction::HistoryImportCompleted, null, ['transfer_id' => $batch->id, ...$summary], $actor);
    }

    private function guessMime(?string $name): string
    {
        $extension = mb_strtolower(pathinfo((string) $name, PATHINFO_EXTENSION));

        return match ($extension) {
            'jpg', 'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'webp' => 'image/webp',
            'gif' => 'image/gif',
            'opus', 'ogg' => 'audio/ogg',
            'mp3' => 'audio/mpeg',
            'm4a' => 'audio/mp4',
            'mp4' => 'video/mp4',
            '3gp' => 'video/3gpp',
            'pdf' => 'application/pdf',
            default => 'application/octet-stream',
        };
    }
}

<?php

namespace App\Services\Migration\History;

use App\Enums\DataTransferStatus;
use App\Enums\HistoryImportDecision;
use App\Enums\HistoryImportFileKind;
use App\Enums\HistoryImportFileStatus;
use App\Enums\HistoryImportRowState;
use App\Models\DataTransfer;
use App\Models\HistoryImportConversation;
use App\Models\HistoryImportFile;
use App\Models\HistoryImportSourceKey;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * The inventory of a history import batch: one row per detected
 * conversation, rebuilt from the staging tables after every file is read
 * (counts, dates, attachments found in the ZIPs, participants, names from
 * the contacts files, duplicates against earlier imports) with its issues
 * and recommendation. The admin's decisions survive every rebuild unless a
 * decision is no longer allowed (it then goes back to undecided).
 *
 * Also the filtered query of the classification screen, its filter
 * options, and the JSON shape of a row.
 *
 * @phpstan-type InventoryFilters array{search?: string|null, date_from?: string|null, date_to?: string|null, inactive_since?: string|null, agent?: string|null, department?: string|null, connection?: string|null, status?: string|null, tag?: string|null, decision?: string|null, kind?: string|null, state?: string|null, duplicates?: bool|null, empty?: bool|null, with_attachments?: bool|null, missing_attachments?: bool|null, group?: bool|null, has_issues?: bool|null}
 */
class HistoryInventory
{
    /** Filter value of rows without a decision. */
    public const UNDECIDED = 'undecided';

    /** Message types that carry a file. */
    public const MEDIA_TYPES = ['image', 'audio', 'video', 'document', 'sticker'];

    /** Participants kept per conversation. */
    private const MAX_PARTICIPANTS = 50;

    public function __construct(private HistoryFileStore $store, private HistoryContactsFile $contacts) {}

    /**
     * A Chat.txt is a group when, once the company's participants are
     * known, more than one other person wrote; before that, when more than
     * two people wrote.
     *
     * @param  list<string>  $participants
     * @param  list<string>|null  $business
     */
    public static function isGroupChat(array $participants, ?array $business): bool
    {
        return $business === null
            ? count($participants) > 2
            : count(array_diff($participants, $business)) > 1;
    }

    public static function lockName(DataTransfer $batch): string
    {
        return "history-import:inventory:{$batch->id}";
    }

    /**
     * Rebuild the inventory of a batch from its staging rows (one rebuild at
     * a time per batch, under the lock confirm() also takes). Only a draft
     * is rebuilt: once confirmed, the inventory is what the admin confirmed.
     */
    public function rebuild(DataTransfer $batch): void
    {
        Cache::lock(self::lockName($batch), 900)->block(300, function () use ($batch): void {
            if ($batch->refresh()->status !== DataTransferStatus::Draft) {
                return;
            }

            $this->createAndLink($batch);
            $this->markMedia($batch);
            $this->applyBusinessParticipants($batch);
            $this->aggregate($batch);
            $this->participants($batch);
            $this->namesFromContacts($batch);
            $this->duplicates($batch);
            $this->evaluate($batch);
        });
    }

    /**
     * @param  InventoryFilters  $filters
     * @return Builder<HistoryImportConversation>
     */
    public function query(DataTransfer $batch, array $filters): Builder
    {
        $flag = fn (string $key): bool => ($filters[$key] ?? null) === true;
        $text = fn (string $key): ?string => isset($filters[$key]) && is_string($filters[$key]) && trim($filters[$key]) !== '' ? trim($filters[$key]) : null;

        return HistoryImportConversation::query()
            ->where('data_transfer_id', $batch->id)
            ->when($text('search'), function (Builder $query, string $search): void {
                $digits = (string) preg_replace('/\D+/', '', $search);
                $query->where(fn (Builder $match) => $match
                    ->where('contact_name', 'like', '%'.addcslashes($search, '%_\\').'%')
                    ->when(strlen($digits) >= 3, fn (Builder $phone) => $phone->orWhere('contact_phone', 'like', '%'.$digits.'%')->orWhere('contact_identifier', 'like', '%'.$digits.'%'))
                    ->orWhere('source_ticket_id', $search));
            })
            ->when($text('date_from'), fn (Builder $query, string $date) => $query->where('last_message_at', '>=', HistoryImportBatches::dayStart($date)))
            ->when($text('date_to'), fn (Builder $query, string $date) => $query->where('last_message_at', '<', HistoryImportBatches::dayStart($date)->addDay()))
            ->when($text('inactive_since'), fn (Builder $query, string $date) => $query->where(fn (Builder $old) => $old->whereNull('last_message_at')->orWhere('last_message_at', '<', HistoryImportBatches::dayStart($date))))
            ->when($text('agent'), fn (Builder $query, string $value) => $value === self::UNDECIDED ? $query->whereNull('source_agent') : $query->where('source_agent', $value))
            ->when($text('department'), fn (Builder $query, string $value) => $value === self::UNDECIDED ? $query->whereNull('source_department') : $query->where('source_department', $value))
            ->when($text('connection'), fn (Builder $query, string $value) => $value === self::UNDECIDED ? $query->whereNull('source_connection') : $query->where('source_connection', $value))
            ->when($text('status'), fn (Builder $query, string $value) => $query->where('source_status', $value))
            ->when($text('tag'), fn (Builder $query, string $value) => $query->whereJsonContains('source_tags', $value))
            ->when($text('kind'), fn (Builder $query, string $value) => $query->where('source_kind', $value))
            ->when($text('state'), fn (Builder $query, string $value) => $query->where('state', $value))
            ->when($text('decision'), fn (Builder $query, string $value) => $value === self::UNDECIDED ? $query->whereNull('decision') : $query->where('decision', $value))
            ->when($flag('duplicates'), fn (Builder $query) => $query->where('already_imported', true))
            ->when($flag('empty'), fn (Builder $query) => $query->where('message_count', 0))
            ->when($flag('with_attachments'), fn (Builder $query) => $query->where('attachments_total', '>', 0))
            ->when($flag('missing_attachments'), fn (Builder $query) => $query->whereColumn('attachments_available', '<', 'attachments_total'))
            ->when($flag('group'), fn (Builder $query) => $query->where('is_group', true))
            ->when($flag('has_issues'), fn (Builder $query) => $query->whereNotNull('issues'));
    }

    /**
     * Values present in the batch, for the filter selects.
     *
     * @return array{agents: list<string>, departments: list<string>, connections: list<string>, statuses: list<string>, tags: list<string>}
     */
    public function facets(DataTransfer $batch): array
    {
        $distinct = fn (string $column): array => HistoryImportConversation::query()
            ->where('data_transfer_id', $batch->id)
            ->whereNotNull($column)
            ->distinct()
            ->orderBy($column)
            ->limit(300)
            ->pluck($column)
            ->map(fn (mixed $value): string => (string) $value)
            ->values()
            ->all();
        $distinctList = fn (string $column): array => array_values($distinct($column));

        $tags = [];

        HistoryImportConversation::query()
            ->where('data_transfer_id', $batch->id)
            ->whereNotNull('source_tags')
            ->select(['id', 'source_tags'])
            ->chunkById(1000, function (Collection $rows) use (&$tags): void {
                foreach ($rows as $row) {
                    foreach ($row->source_tags ?? [] as $tag) {
                        $tags[(string) $tag] = true;
                    }
                }
            });

        $tags = array_keys($tags);
        sort($tags);

        return [
            'agents' => $distinctList('source_agent'),
            'departments' => $distinctList('source_department'),
            'connections' => $distinctList('source_connection'),
            'statuses' => $distinctList('source_status'),
            'tags' => array_slice(array_map('strval', $tags), 0, 300),
        ];
    }

    /**
     * JSON shape of a row for the classification screen.
     *
     * @return array<string, mixed>
     */
    public function present(HistoryImportConversation $row): array
    {
        return [
            'id' => $row->id,
            'source_key' => $row->source_key,
            'kind' => $row->source_kind,
            'ticket_id' => $row->source_ticket_id,
            'contact' => [
                'name' => $row->contact_name,
                'phone' => $row->contact_phone,
                'identifier' => $row->contact_identifier,
            ],
            'is_group' => $row->is_group,
            'participants' => $row->participants ?? [],
            'business_participants' => $row->business_participants ?? [],
            'opened_at' => $row->opened_at?->toIso8601String(),
            'closed_at' => $row->closed_at?->toIso8601String(),
            'first_message_at' => $row->first_message_at?->toIso8601String(),
            'last_message_at' => $row->last_message_at?->toIso8601String(),
            'messages' => ['total' => $row->message_count, 'inbound' => $row->inbound_count, 'outbound' => $row->outbound_count],
            'attachments' => [
                'total' => $row->attachments_total,
                'available' => $row->attachments_available,
                'missing' => $row->attachmentsMissing(),
                'bytes' => $row->media_bytes,
            ],
            'source' => [
                'agent' => $row->source_agent,
                'department' => $row->source_department,
                'connection' => $row->source_connection,
                'tags' => $row->source_tags ?? [],
                'status' => $row->source_status,
                'details' => $row->source_details ?? (object) [],
            ],
            'in_chats_report' => $row->in_chats_report,
            'already_imported' => $row->already_imported,
            'issues' => $row->issues ?? [],
            'recommendation' => $row->recommendation === null ? null : ['decision' => $row->recommendation, 'reason' => $row->recommendation_reason],
            'decision' => $row->decision?->value,
            'decision_source' => $row->decision_source,
            'decided_at' => $row->decided_at?->toIso8601String(),
            'applied_rule_id' => $row->applied_rule_id,
            'allowed_decisions' => array_map(fn (HistoryImportDecision $decision): string => $decision->value, $row->allowedDecisions()),
            'save_contact' => $row->save_contact,
            'state' => $row->state->value,
            'result' => [
                'conversation_id' => $row->result_conversation_id,
                'contact_id' => $row->result_contact_id,
                'error' => $row->error,
                'processed_at' => $row->processed_at?->toIso8601String(),
            ],
        ];
    }

    private function createAndLink(DataTransfer $batch): void
    {
        DB::statement(<<<'SQL'
            INSERT IGNORE INTO history_import_conversations (data_transfer_id, source_key, source_kind, state, created_at, updated_at)
            SELECT data_transfer_id, conversation_key,
                CASE WHEN conversation_key LIKE 'ticket:%' THEN 'ticket' WHEN conversation_key LIKE 'chattxt:%' THEN 'chat_txt' ELSE 'contact' END,
                'pending', NOW(), NOW()
            FROM history_import_messages
            WHERE data_transfer_id = ? AND history_import_conversation_id IS NULL
            GROUP BY data_transfer_id, conversation_key
            SQL, [$batch->id]);

        DB::statement(<<<'SQL'
            UPDATE history_import_messages m
            JOIN history_import_conversations i ON i.data_transfer_id = m.data_transfer_id AND i.source_key = m.conversation_key
            SET m.history_import_conversation_id = i.id
            WHERE m.data_transfer_id = ? AND m.history_import_conversation_id IS NULL
            SQL, [$batch->id]);

        // Rows left without messages nor a chats report row (a file read again with another mapping).
        DB::statement(<<<'SQL'
            DELETE i FROM history_import_conversations i
            WHERE i.data_transfer_id = ? AND i.in_chats_report = 0
              AND NOT EXISTS (SELECT 1 FROM history_import_messages m WHERE m.history_import_conversation_id = i.id)
            SQL, [$batch->id]);
    }

    /**
     * Which referenced files are inside the batch's ZIPs, and the size of
     * what each conversation can recover.
     */
    private function markMedia(DataTransfer $batch): void
    {
        ['files' => $byFile, 'owners' => $owners] = $this->mediaIndex($batch);
        $zips = HistoryImportFile::query()->where('data_transfer_id', $batch->id)->where('extension', 'zip')->pluck('id')->flip();
        $bytes = [];

        // Draft only (rebuild() checks it): no execution result is in these columns yet.
        DB::table('history_import_messages')->where('data_transfer_id', $batch->id)->whereNotNull('media_error')->update(['media_error' => null]);

        DB::table('history_import_messages')
            ->where('data_transfer_id', $batch->id)
            ->whereNotNull('media_name')
            ->select(['id', 'history_import_conversation_id', 'history_import_file_id', 'media_name'])
            ->chunkById(2000, function ($rows) use ($byFile, $owners, $zips, &$bytes): void {
                $state = ['available' => [], 'missing' => [], 'ambiguous' => []];

                foreach ($rows as $row) {
                    $name = (string) $row->media_name;

                    // A Chat.txt message only uses the files of its own ZIP; anything else a name only one ZIP has.
                    if ($zips->has($row->history_import_file_id)) {
                        $size = $byFile[(int) $row->history_import_file_id][$name] ?? null;
                    } elseif (count($owners[$name] ?? []) > 1) {
                        $state['ambiguous'][] = $row->id;

                        continue;
                    } else {
                        $size = isset($owners[$name][0]) ? $byFile[$owners[$name][0]][$name] : null;
                    }

                    if ($size !== null) {
                        $state['available'][] = $row->id;
                        $bytes[(int) $row->history_import_conversation_id] = ($bytes[(int) $row->history_import_conversation_id] ?? 0) + $size;
                    } else {
                        $state['missing'][] = $row->id;
                    }
                }

                DB::table('history_import_messages')->whereIn('id', $state['available'])->update(['media_available' => true]);
                DB::table('history_import_messages')->whereIn('id', [...$state['missing'], ...$state['ambiguous']])->update(['media_available' => false]);
                DB::table('history_import_messages')->whereIn('id', $state['ambiguous'])->update(['media_error' => 'historical_ambiguous']);
            });

        HistoryImportConversation::query()->where('data_transfer_id', $batch->id)->update(['media_bytes' => 0]);

        foreach ($bytes as $conversationId => $total) {
            DB::table('history_import_conversations')->where('id', $conversationId)->update(['media_bytes' => $total]);
        }
    }

    /**
     * Media entries of the batch's read ZIPs: per ZIP (base name => size)
     * and, per base name, the ZIPs that have it.
     *
     * @return array{files: array<int, array<string, int>>, owners: array<string, list<int>>}
     */
    public function mediaIndex(DataTransfer $batch): array
    {
        $files = [];
        $owners = [];

        HistoryImportFile::query()
            ->where('data_transfer_id', $batch->id)
            ->where('kind', HistoryImportFileKind::Media)
            ->whereIn('status', [HistoryImportFileStatus::Parsing, HistoryImportFileStatus::Parsed])
            ->whereNotNull('media_index')
            ->orderBy('id')
            ->get(['id', 'media_index'])
            ->each(function (HistoryImportFile $file) use (&$files, &$owners): void {
                foreach ($file->media_index ?? [] as $name => $size) {
                    $files[$file->id][(string) $name] = (int) $size;
                    $owners[(string) $name][] = $file->id;
                }
            });

        return ['files' => $files, 'owners' => $owners];
    }

    /**
     * Direction of the messages whose file does not say it, from the
     * participants the admin marked as the company.
     */
    private function applyBusinessParticipants(DataTransfer $batch): void
    {
        // Conversations without a decided list: direction unknown (never a
        // batch-wide reset: each conversation is settled in one statement).
        DB::statement(<<<'SQL'
            UPDATE history_import_messages m
            JOIN history_import_conversations i ON i.id = m.history_import_conversation_id
            SET m.from_me = NULL
            WHERE m.data_transfer_id = ? AND m.direction_in_file = 0 AND m.from_me IS NOT NULL AND i.business_participants IS NULL
            SQL, [$batch->id]);

        HistoryImportConversation::query()
            ->where('data_transfer_id', $batch->id)
            ->whereNotNull('business_participants')
            ->select(['id', 'business_participants'])
            ->chunkById(500, function (Collection $rows): void {
                foreach ($rows as $row) {
                    $business = array_values(array_filter($row->business_participants ?? [], 'is_string'));
                    $direction = $business === []
                        ? '0'
                        : '(sender_name IN ('.implode(',', array_fill(0, count($business), '?')).'))';

                    // A decided list (even empty: nobody is the company) settles every sender at once.
                    DB::update(
                        "UPDATE history_import_messages SET from_me = {$direction} WHERE history_import_conversation_id = ? AND direction_in_file = 0 AND sender_name IS NOT NULL",
                        [...$business, $row->id],
                    );
                }
            });
    }

    private function aggregate(DataTransfer $batch): void
    {
        $media = "'".implode("','", self::MEDIA_TYPES)."'";

        DB::statement(<<<SQL
            UPDATE history_import_conversations i
            LEFT JOIN (
                SELECT history_import_conversation_id AS cid,
                    COUNT(*) AS total,
                    SUM(from_me = 1) AS outbound,
                    SUM(from_me = 0) AS inbound,
                    MIN(occurred_at) AS first_at,
                    MAX(occurred_at) AS last_at,
                    SUM(type IN ({$media})) AS media_total,
                    SUM(type IN ({$media}) AND media_available = 1) AS media_found,
                    MAX(contact_name) AS contact_name,
                    MAX(contact_phone) AS contact_phone,
                    MAX(contact_identifier) AS contact_identifier,
                    MAX(source_agent) AS agent,
                    MAX(source_department) AS department,
                    MAX(source_connection) AS connection,
                    MAX(contact_identifier LIKE '%@g.us') AS is_group
                FROM history_import_messages
                WHERE data_transfer_id = ? AND is_system = 0
                GROUP BY history_import_conversation_id
            ) a ON a.cid = i.id
            SET i.message_count = COALESCE(a.total, 0),
                i.outbound_count = COALESCE(a.outbound, 0),
                i.inbound_count = COALESCE(a.inbound, 0),
                i.first_message_at = a.first_at,
                i.last_message_at = COALESCE(a.last_at, i.closed_at, i.opened_at),
                i.attachments_total = COALESCE(a.media_total, 0),
                i.attachments_available = COALESCE(a.media_found, 0),
                i.contact_name = COALESCE(i.contact_name, a.contact_name),
                i.contact_phone = COALESCE(i.contact_phone, a.contact_phone),
                i.contact_identifier = COALESCE(i.contact_identifier, a.contact_identifier),
                i.source_agent = COALESCE(i.source_agent, a.agent),
                i.source_department = COALESCE(i.source_department, a.department),
                i.source_connection = COALESCE(i.source_connection, a.connection),
                i.is_group = (i.is_group = 1 OR COALESCE(a.is_group, 0) = 1),
                i.updated_at = NOW()
            WHERE i.data_transfer_id = ?
            SQL, [$batch->id, $batch->id]);
    }

    /**
     * Distinct senders per conversation; for a Chat.txt, the customer is
     * the only participant who is not the company (a phone-like name gives
     * its number) and more than one means a group.
     */
    private function participants(DataTransfer $batch): void
    {
        $senders = [];

        DB::table('history_import_messages')
            ->where('data_transfer_id', $batch->id)
            ->whereNotNull('sender_name')
            ->where('is_system', false)
            ->groupBy('history_import_conversation_id', 'sender_name')
            ->orderBy('history_import_conversation_id')
            ->select(['history_import_conversation_id', 'sender_name'])
            ->cursor()
            ->each(function (object $row) use (&$senders): void {
                $conversation = (int) $row->history_import_conversation_id;

                if (count($senders[$conversation] ?? []) < self::MAX_PARTICIPANTS) {
                    $senders[$conversation][] = (string) $row->sender_name;
                }
            });

        $values = app(HistoryValues::class);

        HistoryImportConversation::query()
            ->where('data_transfer_id', $batch->id)
            ->chunkById(500, function (Collection $rows) use ($senders, $values): void {
                foreach ($rows as $row) {
                    $participants = $senders[$row->id] ?? [];
                    $row->participants = $participants === [] ? null : $participants;

                    if ($row->source_kind === HistoryImportConversation::KIND_CHAT_TEXT) {
                        $business = $row->business_participants ?? [];
                        $others = array_values(array_diff($participants, $business));
                        $row->is_group = self::isGroupChat($participants, $row->business_participants);

                        if (count($others) === 1 && $business !== []) {
                            $phone = $values->phone($others[0]);
                            $row->contact_phone = $phone ?? $row->contact_phone;
                            $row->contact_name = $phone === null ? $others[0] : ($row->contact_name ?? $others[0]);
                        }
                    }

                    if ($row->isDirty()) {
                        $row->save();
                    }
                }
            });
    }

    /**
     * Names for numbers without one, from the batch's contacts files.
     */
    private function namesFromContacts(DataTransfer $batch): void
    {
        $files = HistoryImportFile::query()
            ->where('data_transfer_id', $batch->id)
            ->where('kind', HistoryImportFileKind::Contacts)
            ->where('status', HistoryImportFileStatus::Parsed)
            ->whereNotNull('path')
            ->get();

        if ($files->isEmpty()) {
            return;
        }

        $needed = array_fill_keys(HistoryImportConversation::query()
            ->where('data_transfer_id', $batch->id)
            ->whereNull('contact_name')
            ->whereNotNull('contact_phone')
            ->distinct()
            ->pluck('contact_phone')
            ->map(fn (mixed $phone): string => (string) $phone)
            ->all(), true);

        foreach ($files as $file) {
            if ($needed === []) {
                return;
            }

            $this->store->withLocalCopy($file, function (string $path) use ($file, $batch, &$needed): void {
                foreach ($this->contacts->rows($file, $path) as $contact) {
                    if ($contact === null || $contact['name'] === null || ! isset($needed[$contact['phone']])) {
                        continue;
                    }

                    HistoryImportConversation::query()
                        ->where('data_transfer_id', $batch->id)
                        ->where('contact_phone', $contact['phone'])
                        ->whereNull('contact_name')
                        ->update(['contact_name' => $contact['name']]);

                    unset($needed[$contact['phone']]);
                }
            });
        }
    }

    /**
     * Conversations an earlier batch already imported (import_source_keys).
     */
    private function duplicates(DataTransfer $batch): void
    {
        $instance = HistoryImportBatches::sourceInstance($batch);

        HistoryImportConversation::query()
            ->where('data_transfer_id', $batch->id)
            ->select(['id', 'source_key', 'already_imported'])
            ->chunkById(1000, function (Collection $rows) use ($instance): void {
                $existing = HistoryImportSourceKey::query()
                    ->where('source_system', HistoryImportSourceKey::SYSTEM_WHATICKET)
                    ->where('source_instance', $instance)
                    ->where('source_kind', HistoryImportSourceKey::KIND_CONVERSATION)
                    ->whereIn('source_id', $rows->pluck('source_key'))
                    ->pluck('source_id')
                    ->flip();

                $duplicates = $rows->filter(fn (HistoryImportConversation $row): bool => $existing->has($row->source_key))->modelKeys();
                $fresh = $rows->reject(fn (HistoryImportConversation $row): bool => $existing->has($row->source_key))->modelKeys();

                HistoryImportConversation::query()->whereKey($duplicates)->update(['already_imported' => true]);
                HistoryImportConversation::query()->whereKey($fresh)->update(['already_imported' => false]);
            });
    }

    /**
     * Issues, recommendation and allowed decision of every row.
     */
    private function evaluate(DataTransfer $batch): void
    {
        $unknown = DB::table('history_import_messages')
            ->where('data_transfer_id', $batch->id)
            ->whereNull('from_me')
            ->groupBy('history_import_conversation_id')
            ->selectRaw('history_import_conversation_id AS cid, COUNT(*) AS total')
            ->pluck('total', 'cid')
            ->all();
        $ambiguous = DB::table('history_import_messages')
            ->where('data_transfer_id', $batch->id)
            ->where('media_error', 'historical_ambiguous')
            ->groupBy('history_import_conversation_id')
            ->selectRaw('history_import_conversation_id AS cid, COUNT(*) AS total')
            ->pluck('total', 'cid')
            ->all();

        HistoryImportConversation::query()
            ->where('data_transfer_id', $batch->id)
            ->where('state', HistoryImportRowState::Pending)
            ->chunkById(500, function (Collection $rows) use ($unknown, $ambiguous): void {
                foreach ($rows as $row) {
                    $issues = $this->issuesOf($row, (int) ($unknown[$row->id] ?? 0), (int) ($ambiguous[$row->id] ?? 0));
                    $row->issues = $issues === [] ? null : $issues;
                    [$row->recommendation, $row->recommendation_reason] = match (true) {
                        $row->message_count === 0 => [HistoryImportDecision::Exclude->value, 'Sin mensajes en los archivos.'],
                        $row->already_imported && $row->source_kind !== HistoryImportConversation::KIND_CONTACT => [HistoryImportDecision::Exclude->value, 'Ya se importó en una importación anterior.'],
                        $row->is_group => [HistoryImportDecision::Archive->value, 'Chat de grupo: solo puede archivarse.'],
                        default => [null, null],
                    };

                    if ($row->decision !== null && ! $row->allows($row->decision)) {
                        $row->forceFill(['decision' => null, 'decision_source' => null, 'decided_by_user_id' => null, 'decided_at' => null, 'applied_rule_id' => null]);
                    }

                    if ($row->isDirty()) {
                        $row->save();
                    }
                }
            });
    }

    /**
     * @return list<array{code: string, severity: string, message: string}>
     */
    private function issuesOf(HistoryImportConversation $row, int $unknownDirection, int $ambiguous): array
    {
        $issues = [];
        $add = function (string $code, string $severity, string $message) use (&$issues): void {
            $issues[] = ['code' => $code, 'severity' => $severity, 'message' => $message];
        };

        if ($row->message_count === 0) {
            $add('empty', 'error', 'No hay mensajes de esta conversación en los archivos: solo puede quedar como «No importar».');
        }

        if ($unknownDirection > 0) {
            ($row->participants ?? []) !== []
                ? $add('participants_unidentified', 'error', 'Marca qué participantes son de la empresa para saber quién escribió cada mensaje.')
                : $add('direction_unknown', 'error', 'El archivo no indica quién envió cada mensaje. Asigna la columna «Enviado por la empresa» en el mapeo.');
        }

        if ($row->is_group) {
            $add('group', 'warning', 'Chat de grupo: solo puede archivarse como historial o quedar como «No importar».');
        }

        if ($row->contact_phone === null && $row->contact_name === null) {
            $add('no_contact', 'warning', 'No se conoce el nombre ni el número del contacto.');
        } elseif ($row->contact_phone === null && $row->contact_identifier !== null && ! $row->is_group) {
            $add('phone_invalid', 'warning', 'El número no se pudo normalizar; se conservará tal como viene, sin relacionarlo con otro contacto.');
        }

        if ($row->already_imported) {
            $row->source_kind === HistoryImportConversation::KIND_CONTACT
                ? $add('already_imported', 'warning', 'Ya se importó en una importación anterior; solo se agregarán los mensajes que todavía no tiene.')
                : $add('already_imported', 'warning', 'Ya se importó en una importación anterior; se omitirá para no duplicarla.');
        }

        if ($ambiguous > 0) {
            $add('attachments_ambiguous', 'warning', $ambiguous.' archivo(s) tienen un nombre que aparece en varios ZIP; no se adivina cuál es y quedarán como no disponibles.');
        }

        if ($row->attachmentsMissing() > 0) {
            $add('attachments_missing', 'warning', $row->attachmentsMissing().' archivo(s) no vienen en los ZIP; quedarán como «Archivo histórico no disponible».');
        }

        return $issues;
    }
}

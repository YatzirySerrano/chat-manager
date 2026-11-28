<?php

namespace App\Actions\Conversations;

use App\Enums\AuditAction;
use App\Enums\ConversationEventType;
use App\Enums\ConversationStatus;
use App\Enums\MessageType;
use App\Exceptions\ConversationConflict;
use App\Models\ContactAttribution;
use App\Models\Conversation;
use App\Models\ConversationNote;
use App\Models\ConversationRead;
use App\Models\Message;
use App\Models\ResponseCycle;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\Conversations\ConversationLifecycle;
use App\Services\Conversations\RequestBoundary;
use App\Services\Conversations\ResponseClock;
use App\Support\RequestFields\RequestFieldCatalog;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * «Corregir tamaño de la solicitud»: moves the boundary between two
 * consecutive requests of the same contact and connection (the earlier one,
 * «previous», and the later one, «current»), when a request was completed
 * too early or the customer's messages landed in the wrong one.
 *
 * The boundary is a message: the later request starts there. Every message
 * of both requests (by id, the order the chat pages them in) before it
 * belongs to the earlier one, the rest to the later one; reactions follow
 * the message they react to. Only `messages.conversation_id` changes:
 * attachments follow their message (message_id), quotes and reactions keep
 * pointing at the same rows, and nothing is ever deleted. Events stay where
 * they happened; a `boundary_adjusted` event is recorded on both requests
 * and the change is audited. The data of the request («Cliente»,
 * «Producto»…) captured from a moved message moves with it unless the
 * other request already has that field.
 *
 * When one request is left without messages it is absorbed by the other
 * (merge): its row stays, closed, with `merged_into_conversation_id` and
 * `merged_at` set, and from then on it is outside every list, count,
 * history and report (ConversationAccess, the metrics calculator). The
 * joined request keeps the state of the request that decides how it goes
 * on (stateSource()): the one still in attention or in a queue, otherwise
 * the completed one, otherwise the later one; so an active request
 * absorbed into a completed one reopens it with its own status, agent,
 * queue clock and readiness, and must be completed again (the credit result
 * already recorded is kept). Its notes, provenance evidence, response
 * cycles and read markers go along with the messages.
 *
 * Derived columns of both requests (last message, last inbound and
 * outbound, first human response) are recomputed from their messages; the
 * metrics are refreshed by the events (RefreshMetricsOnActivity), and an
 * absorbed request loses its rows. `created_at` stays as it was.
 *
 * Known limitation: response cycles are facts of the conversation they
 * happened in; moving part of the messages leaves them where they are
 * (only a merge moves them, with the open one). They are never corrupted:
 * the open one stays on the request that is still in attention.
 *
 * Lock order (ConversationLifecycle): both conversation rows in id order,
 * then their department rows (shared, in id order), then the actor. One
 * transaction; ConversationChanged for both after commit (record()).
 */
class AdjustRequestBoundary
{
    /** Message ids updated per statement. */
    private const CHUNK = 500;

    /** Merge: the later request was absorbed by the earlier one. */
    public const MERGED_INTO_PREVIOUS = 'into_previous';

    /** Merge: the earlier request was absorbed by the later one. */
    public const MERGED_INTO_CURRENT = 'into_current';

    /**
     * What the joined request takes from the request whose state wins
     * (attention, queue, closing and completion).
     */
    private const STATE_COLUMNS = [
        'status', 'department_id', 'assigned_user_id', 'assigned_at', 'on_hold_at', 'on_hold_reason',
        'closed_at', 'closed_by_user_id', 'completed_at', 'queued_at', 'readiness', 'readiness_changed_at',
        'readiness_changed_by_user_id', 'awaiting_info_at', 'not_request_at', 'not_request_by_user_id',
    ];

    /** The credit result: kept from whichever request has one, the winning one first. */
    private const OUTCOME_COLUMNS = ['outcome', 'outcome_amount', 'outcome_at', 'outcome_by_user_id'];

    public function __construct(
        private ConversationLifecycle $lifecycle,
        private RequestBoundary $boundary,
        private ResponseClock $clock,
        private AuditLogger $audit,
    ) {}

    /**
     * @param  int|null  $startsAt  The message the later request starts at; null joins the later request into the earlier one.
     * @return array{previous_id: int, current_id: int, moved_to_previous: int, moved_to_current: int, merged: string|null, survivor_id: int|null}
     */
    public function handle(Conversation $current, int $previousId, ?int $startsAt, User $actor, ?int $currentVersion = null, ?int $previousVersion = null): array
    {
        if ($previousId === $current->id) {
            throw self::notConsecutive();
        }

        $previous = Conversation::query()->find($previousId) ?? throw self::notConsecutive();

        return DB::transaction(function () use ($current, $previous, $startsAt, $actor, $currentVersion, $previousVersion): array {
            // Lock order step 1: both conversation rows, in id order.
            if ($previous->id < $current->id) {
                $previous = $this->lifecycle->lock($previous, $previousVersion);
                $current = $this->lifecycle->lock($current, $currentVersion);
            } else {
                $current = $this->lifecycle->lock($current, $currentVersion);
                $previous = $this->lifecycle->lock($previous, $previousVersion);
            }

            // Step 2: their departments (shared: nothing is distributed here).
            $departmentIds = array_values(array_unique(array_filter([$previous->department_id, $current->department_id])));
            sort($departmentIds);

            foreach ($departmentIds as $departmentId) {
                $this->lifecycle->lockDepartment($departmentId);
            }

            // Step 3: the actor, authorized on both with fresh data.
            $actor = $this->lifecycle->authorize($actor, 'adjustBoundary', $previous);
            $this->lifecycle->authorize($actor, 'adjustBoundary', $current);

            $this->ensureAdjustable($previous, $current);

            return $this->adjust($previous, $current, $startsAt, $actor);
        });
    }

    /**
     * Both live, neither absorbed, and still consecutive: same contact and
     * connection, nothing between them.
     */
    private function ensureAdjustable(Conversation $previous, Conversation $current): void
    {
        foreach ([$previous, $current] as $conversation) {
            if ($conversation->isImported() || $conversation->isHistoryOnly()) {
                throw ConversationLifecycle::forbidden($conversation);
            }

            if ($conversation->isMerged()) {
                throw new ConversationConflict('Una de estas solicitudes ya se unió a otra. Vuelve a abrir «Conversación completa» para ver cómo quedaron.');
            }
        }

        if ($previous->contact_id !== $current->contact_id
            || $previous->connection_id !== $current->connection_id
            || $this->boundary->previousOf($current)?->id !== $previous->id) {
            throw self::notConsecutive();
        }
    }

    /**
     * @return array{previous_id: int, current_id: int, moved_to_previous: int, moved_to_current: int, merged: string|null, survivor_id: int|null}
     */
    private function adjust(Conversation $previous, Conversation $current, ?int $startsAt, User $actor): array
    {
        [$toPrevious, $toCurrent, $visibleToPrevious, $visibleToCurrent, $leftInPrevious, $leftInCurrent] = $this->plan($previous, $current, $startsAt);

        $merged = match (true) {
            $leftInCurrent === 0 => self::MERGED_INTO_PREVIOUS,
            $leftInPrevious === 0 => self::MERGED_INTO_CURRENT,
            default => null,
        };

        if ($merged === null && $toPrevious === [] && $toCurrent === []) {
            throw ValidationException::withMessages(['starts_at' => 'La solicitud siguiente ya empieza en ese mensaje: no hay nada que mover.']);
        }

        $this->moveMessages($toPrevious, $previous->id);
        $this->moveMessages($toCurrent, $current->id);

        $result = [
            'previous_id' => $previous->id,
            'current_id' => $current->id,
            'moved_to_previous' => $visibleToPrevious,
            'moved_to_current' => $visibleToCurrent,
            'merged' => $merged,
            'survivor_id' => null,
        ];

        if ($merged === null) {
            // The data captured from a moved message goes with it.
            $previousFields = RequestFieldCatalog::fromStored($previous->request_fields);
            $currentFields = RequestFieldCatalog::fromStored($current->request_fields);
            [$previousFields, $currentFields, $forward] = $this->followMessages($previousFields, $currentFields, $toCurrent);
            [$currentFields, $previousFields, $backward] = $this->followMessages($currentFields, $previousFields, $toPrevious);
            // Untouched request data is left exactly as it was stored.
            $fields = fn (array $list): array => $forward || $backward ? ['request_fields' => $list === [] ? null : $list] : [];

            $this->refreshDerived($previous, $fields($previousFields), bump: true);
            $this->refreshDerived($current, $fields($currentFields), bump: true);

            $this->recordBoth($previous, $current, $actor, $result);

            return $result;
        }

        [$survivor, $absorbed] = $merged === self::MERGED_INTO_PREVIOUS ? [$previous, $current] : [$current, $previous];
        $result['survivor_id'] = $survivor->id;

        // The state that wins is read before either request changes.
        $source = $this->stateSource($previous, $current);
        $sourceState = $source->only([...self::STATE_COLUMNS, 'category_id', ...self::OUTCOME_COLUMNS, 'request_fields']);
        $loser = $source->is($survivor) ? $absorbed : $survivor;
        $loserState = $loser->only(['category_id', ...self::OUTCOME_COLUMNS, 'request_fields']);

        $this->moveResponseCycles($absorbed, $survivor);
        // A single unclosed conversation per contact and connection: the
        // absorbed one is closed before the survivor may take an open state.
        $this->closeAbsorbed($absorbed, $survivor, $actor);
        $this->takeState($survivor, $source->is($survivor) ? null : $sourceState, $sourceState, $loserState);
        $this->moveFollowUp($absorbed, $survivor);
        $this->refreshDerived($survivor);

        $this->recordBoth($previous, $current, $actor, $result);

        return $result;
    }

    /**
     * Which request each message belongs to once the boundary is at
     * `$startsAt` (null: everything in the earlier one).
     *
     * @return array{0: list<int>, 1: list<int>, 2: int, 3: int, 4: int, 5: int} Ids to the earlier one, ids to the later one, visible counts of both, messages left in each.
     */
    private function plan(Conversation $previous, Conversation $current, ?int $startsAt): array
    {
        $rows = DB::table('messages')
            ->whereIn('conversation_id', [$previous->id, $current->id])
            ->orderBy('id')
            ->get(['id', 'conversation_id', 'type', 'whatsapp_message_id']);

        $reaction = MessageType::Reaction->value;
        $visible = $rows->filter(fn (object $row): bool => $row->type !== $reaction);

        if ($startsAt !== null && ! $visible->contains(fn (object $row): bool => (int) $row->id === $startsAt)) {
            throw ValidationException::withMessages(['starts_at' => 'Elige un mensaje de una de las dos solicitudes.']);
        }

        $sideOf = fn (int $id): int => $startsAt === null || $id < $startsAt ? $previous->id : $current->id;
        $final = [];
        $byProviderId = [];

        foreach ($visible as $row) {
            $final[(int) $row->id] = $sideOf((int) $row->id);

            if (is_string($row->whatsapp_message_id) && $row->whatsapp_message_id !== '') {
                $byProviderId[$row->whatsapp_message_id] = (int) $row->id;
            }
        }

        $reactionIds = $rows->filter(fn (object $row): bool => $row->type === $reaction)->pluck('id')->map(fn (mixed $id): int => (int) $id)->all();

        if ($reactionIds !== []) {
            foreach (Message::query()->whereKey($reactionIds)->get(['id', 'metadata']) as $message) {
                $content = is_array($message->metadata) ? ($message->metadata['content'] ?? null) : null;
                $target = is_array($content) && is_string($content['message_id'] ?? null) ? $content['message_id'] : null;
                // A reaction goes where the message it reacts to goes.
                $final[$message->id] = $target !== null && isset($byProviderId[$target]) ? $final[$byProviderId[$target]] : $sideOf($message->id);
            }
        }

        $toPrevious = [];
        $toCurrent = [];
        $visibleToPrevious = 0;
        $visibleToCurrent = 0;

        foreach ($rows as $row) {
            $id = (int) $row->id;
            $isVisible = $row->type !== $reaction;

            if ((int) $row->conversation_id === $current->id && $final[$id] === $previous->id) {
                $toPrevious[] = $id;
                $visibleToPrevious += $isVisible ? 1 : 0;
            } elseif ((int) $row->conversation_id === $previous->id && $final[$id] === $current->id) {
                $toCurrent[] = $id;
                $visibleToCurrent += $isVisible ? 1 : 0;
            }
        }

        $left = array_count_values(array_map('strval', $final));

        return [$toPrevious, $toCurrent, $visibleToPrevious, $visibleToCurrent, $left[(string) $previous->id] ?? 0, $left[(string) $current->id] ?? 0];
    }

    /**
     * The request whose state the joined one keeps: the one still in
     * attention or in a queue; otherwise the completed one, then a plain
     * close, then «No es solicitud»; the later one on a tie.
     */
    private function stateSource(Conversation $previous, Conversation $current): Conversation
    {
        $rank = fn (Conversation $conversation): int => match (true) {
            $conversation->status !== ConversationStatus::Closed => 4,
            $conversation->completed_at !== null => 3,
            $conversation->not_request_at === null => 2,
            default => 1,
        };

        return $rank($previous) > $rank($current) ? $previous : $current;
    }

    /**
     * @param  list<int>  $ids
     */
    private function moveMessages(array $ids, int $conversationId): void
    {
        foreach (array_chunk($ids, self::CHUNK) as $chunk) {
            // Eloquent's update also touches updated_at, so open chats see the rows changed.
            Message::query()->whereKey($chunk)->update(['conversation_id' => $conversationId]);
        }
    }

    /**
     * Fields captured from one of the moved messages leave `$from` for `$to`,
     * unless `$to` already has a value for that field (then both keep theirs).
     *
     * @param  list<array{key: string, label: string, value: string, message_id: int|null, captured_at: string|null}>  $from
     * @param  list<array{key: string, label: string, value: string, message_id: int|null, captured_at: string|null}>  $to
     * @param  list<int>  $movedIds
     * @return array{0: list<array{key: string, label: string, value: string, message_id: int|null, captured_at: string|null}>, 1: list<array{key: string, label: string, value: string, message_id: int|null, captured_at: string|null}>, 2: bool} Both lists, and whether any field moved.
     */
    private function followMessages(array $from, array $to, array $movedIds): array
    {
        if ($movedIds === [] || $from === []) {
            return [$from, $to, false];
        }

        $moved = array_flip($movedIds);
        $taken = array_flip(array_keys(RequestFieldCatalog::values($to)));
        $kept = [];

        foreach ($from as $field) {
            if ($field['message_id'] !== null && isset($moved[$field['message_id']]) && ! isset($taken[$field['key']])) {
                $to = array_values(array_filter($to, fn (array $existing): bool => $existing['key'] !== $field['key']));
                $to[] = $field;
                $taken[$field['key']] = true;

                continue;
            }

            $kept[] = $field;
        }

        return [$kept, $to, count($kept) !== count($from)];
    }

    /**
     * Response cycles are facts of the request: the absorbed one's go to
     * the survivor, the open one too (pointer included). Should both have
     * one open (never expected: only one of them can be unclosed), the
     * absorbed one's is ended first, so the unique (conversation_id,
     * active) index never collides. Rows are read and updated by key only.
     */
    private function moveResponseCycles(Conversation $absorbed, Conversation $survivor): void
    {
        $open = fn (Conversation $conversation): ?int => ResponseCycle::query()
            ->where('conversation_id', $conversation->id)
            ->where('active', 1)
            ->value('id');

        if ($open($absorbed) !== null && $open($survivor) !== null) {
            $this->clock->closed($absorbed);
        }

        $absorbedOpen = $open($absorbed);
        $ids = ResponseCycle::query()->where('conversation_id', $absorbed->id)->pluck('id')->all();

        foreach (array_chunk($ids, self::CHUNK) as $chunk) {
            ResponseCycle::query()->whereKey($chunk)->update(['conversation_id' => $survivor->id]);
        }

        DB::table('conversations')->where('id', $absorbed->id)->update(['open_response_cycle_id' => null]);

        if ($absorbedOpen !== null) {
            DB::table('conversations')->where('id', $survivor->id)->update(['open_response_cycle_id' => $absorbedOpen]);
        }
    }

    /**
     * The absorbed request is closed (never deleted) and remembers where
     * its messages went.
     */
    private function closeAbsorbed(Conversation $absorbed, Conversation $survivor, User $actor): void
    {
        $attributes = [
            'merged_into_conversation_id' => $survivor->id,
            'merged_at' => now(),
            'closed_at' => $absorbed->closed_at ?? now(),
            'closed_by_user_id' => $absorbed->closed_by_user_id ?? $actor->id,
            'on_hold_at' => null,
            'on_hold_reason' => null,
        ];

        if ($absorbed->status !== ConversationStatus::Closed) {
            $this->lifecycle->apply($absorbed, ConversationStatus::Closed, $attributes);

            return;
        }

        $absorbed->forceFill([...$attributes, 'version' => $absorbed->version + 1])->save();
    }

    /**
     * The survivor takes the winning state (when it came from the absorbed
     * request), the category and credit result of whichever has one (the
     * winning request first), and the request data of both (the winning
     * request's value when both have the field).
     *
     * @param  array<string, mixed>|null  $state  The absorbed request's state, when it wins.
     * @param  array<string, mixed>  $winner
     * @param  array<string, mixed>  $loser
     */
    private function takeState(Conversation $survivor, ?array $state, array $winner, array $loser): void
    {
        $attributes = $state === null ? [] : array_intersect_key($state, array_flip(self::STATE_COLUMNS));
        $attributes['category_id'] = $winner['category_id'] ?? $loser['category_id'] ?? null;

        foreach (self::OUTCOME_COLUMNS as $column) {
            $attributes[$column] = ($winner['outcome'] ?? null) !== null ? ($winner[$column] ?? null) : ($loser[$column] ?? null);
        }

        $fields = $this->joinFields(RequestFieldCatalog::fromStored($loser['request_fields'] ?? null), RequestFieldCatalog::fromStored($winner['request_fields'] ?? null));
        $attributes['request_fields'] = $fields === [] ? null : $fields;

        /** @var ConversationStatus $target */
        $target = $attributes['status'] ?? $survivor->status;
        unset($attributes['status']);

        if ($target !== $survivor->status) {
            $this->lifecycle->apply($survivor, $target, $attributes);

            return;
        }

        $survivor->forceFill([...$attributes, 'version' => $survivor->version + 1])->save();
    }

    /**
     * @param  list<array{key: string, label: string, value: string, message_id: int|null, captured_at: string|null}>  $base
     * @param  list<array{key: string, label: string, value: string, message_id: int|null, captured_at: string|null}>  $over
     * @return list<array{key: string, label: string, value: string, message_id: int|null, captured_at: string|null}>
     */
    private function joinFields(array $base, array $over): array
    {
        $fields = [];

        foreach ($base as $field) {
            $fields[$field['key']] = $field;
        }

        foreach ($over as $field) {
            if (trim($field['value']) !== '' || ! isset($fields[$field['key']])) {
                $fields[$field['key']] = $field;
            }
        }

        return array_values($fields);
    }

    /**
     * Notes, provenance evidence and read markers follow the messages into
     * the survivor (a reader keeps the furthest point they read).
     */
    private function moveFollowUp(Conversation $absorbed, Conversation $survivor): void
    {
        ConversationNote::query()->where('conversation_id', $absorbed->id)->update(['conversation_id' => $survivor->id]);
        ContactAttribution::query()->where('conversation_id', $absorbed->id)->update(['conversation_id' => $survivor->id]);

        foreach (ConversationRead::query()->where('conversation_id', $absorbed->id)->get() as $read) {
            $mine = ConversationRead::query()->firstOrNew(['user_id' => $read->user_id, 'conversation_id' => $survivor->id]);

            if (! $mine->exists || (int) $read->last_read_message_id > (int) $mine->last_read_message_id) {
                $mine->forceFill([
                    'user_id' => $read->user_id,
                    'conversation_id' => $survivor->id,
                    'last_read_message_id' => $read->last_read_message_id,
                    'read_at' => $read->read_at,
                ])->save();
            }
        }
    }

    /**
     * Last message, last inbound and outbound, and first human response,
     * read again from the messages the request holds now (as
     * ReceiveInboundMessage and QueueOutboundMessage keep them). A request
     * left without any message keeps its last message time.
     *
     * @param  array<string, mixed>  $extra
     * @param  bool  $bump  A plain move changes the request: a decision taken on its old version is stale.
     */
    private function refreshDerived(Conversation $conversation, array $extra = [], bool $bump = false): void
    {
        $isAutomatic = "COALESCE(JSON_VALUE(metadata, '$.automatic'), 'false') IN ('true', '1')";

        /** @var object{last_inbound_at: string|null, last_outbound_at: string|null, last_message_at: string|null, first_response_at: string|null} $row */
        $row = DB::table('messages')
            ->where('conversation_id', $conversation->id)
            ->selectRaw('MAX(CASE WHEN direction = ? THEN COALESCE(sent_at, created_at) END) AS last_inbound_at', ['inbound'])
            ->selectRaw('MAX(CASE WHEN direction = ? THEN created_at END) AS last_outbound_at', ['outbound'])
            ->selectRaw('MAX(CASE WHEN direction = ? THEN COALESCE(sent_at, created_at) ELSE created_at END) AS last_message_at', ['inbound'])
            ->selectRaw("MIN(CASE WHEN direction = ? AND sender_user_id IS NOT NULL AND NOT ({$isAutomatic}) THEN created_at END) AS first_response_at", ['outbound'])
            ->first();

        $date = fn (?string $value): ?Carbon => $value === null ? null : Carbon::parse($value);

        $conversation->forceFill([
            ...$extra,
            'last_inbound_at' => $date($row->last_inbound_at),
            'last_outbound_at' => $date($row->last_outbound_at),
            'last_message_at' => $date($row->last_message_at) ?? $conversation->last_message_at,
            'first_response_at' => $date($row->first_response_at),
        ]);

        if ($bump) {
            $conversation->forceFill(['version' => $conversation->version + 1]);
        }

        $conversation->save();
    }

    /**
     * One `boundary_adjusted` event on each request, saying what moved
     * (metadata) and in words (note), and one audit entry.
     *
     * @param  array{previous_id: int, current_id: int, moved_to_previous: int, moved_to_current: int, merged: string|null, survivor_id: int|null}  $result
     */
    private function recordBoth(Conversation $previous, Conversation $current, User $actor, array $result): void
    {
        $merged = $result['merged'];

        foreach ([[$previous, $current, 'previous'], [$current, $previous, 'current']] as [$conversation, $other, $side]) {
            $absorbed = ($merged === self::MERGED_INTO_PREVIOUS && $side === 'current') || ($merged === self::MERGED_INTO_CURRENT && $side === 'previous');
            $movedIn = $side === 'previous' ? $result['moved_to_previous'] : $result['moved_to_current'];
            $movedOut = $side === 'previous' ? $result['moved_to_current'] : $result['moved_to_previous'];

            $this->lifecycle->record($conversation, ConversationEventType::BoundaryAdjusted, $actor, [
                'to_department_id' => $conversation->department_id,
                'note' => $this->describe($side, $other->id, $movedIn, $movedOut, $merged === null ? null : ($absorbed ? 'absorbed' : 'survivor')),
                'metadata' => [
                    'side' => $side,
                    'other_conversation_id' => $other->id,
                    'moved_in' => $movedIn,
                    'moved_out' => $movedOut,
                    'merged' => $merged === null ? null : ($absorbed ? 'absorbed' : 'survivor'),
                ],
            ]);
        }

        $this->audit->record(AuditAction::ConversationBoundaryAdjusted, $current, [
            'previous_conversation_id' => $previous->id,
            'current_conversation_id' => $current->id,
            'moved_to_previous' => $result['moved_to_previous'],
            'moved_to_current' => $result['moved_to_current'],
            'merged' => $merged,
            'survivor_id' => $result['survivor_id'],
            'contact_id' => $current->contact_id,
        ], $actor);
    }

    private function describe(string $side, int $otherId, int $movedIn, int $movedOut, ?string $merged): string
    {
        $neighbour = $side === 'previous' ? 'la solicitud siguiente' : 'la solicitud anterior';
        $count = fn (int $count): string => $count === 1 ? '1 mensaje' : "{$count} mensajes";

        if ($merged === 'absorbed') {
            return "Se unió a {$neighbour} (#{$otherId}): sus mensajes y notas están ahora ahí.";
        }

        $parts = [];

        if ($movedOut > 0) {
            $parts[] = "{$count($movedOut)} pasaron a {$neighbour} (#{$otherId})";
        }

        if ($movedIn > 0) {
            $parts[] = "llegaron {$count($movedIn)} de {$neighbour} (#{$otherId})";
        }

        $text = $parts === [] ? '' : ucfirst(implode('; ', $parts)).'.';

        return $merged === 'survivor'
            ? trim("Se unió con {$neighbour} (#{$otherId}): ahora es una sola solicitud. {$text}")
            : $text;
    }

    private static function notConsecutive(): ConversationConflict
    {
        return new ConversationConflict('Estas solicitudes ya no son consecutivas del mismo contacto y conexión. Vuelve a abrir «Conversación completa» para ver cómo están ahora.');
    }
}

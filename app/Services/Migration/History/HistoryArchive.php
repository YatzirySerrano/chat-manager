<?php

namespace App\Services\Migration\History;

use App\Enums\ConversationOrigin;
use App\Models\Conversation;
use App\Models\HistoryImportSourceKey;
use App\Models\User;
use App\Support\PhoneNumber;
use App\Support\ProtectedAccounts;
use Illuminate\Database\Eloquent\Builder;

/**
 * Consultation of imported Whaticket history (read-only), for whoever holds
 * history-import.archive-view (an organization-wide permission, so no
 * per-department reach applies). By default it lists the conversations
 * archived as read-only history; "import" lists the ones imported as
 * operational history and "all" both. Search by contact name or number,
 * dates, department, agent, tag, connection and batch.
 *
 * @phpstan-type ArchiveFilters array{destination?: string|null, search?: string|null, date_from?: string|null, date_to?: string|null, department_id?: int|null, agent_id?: int|null, tag_id?: int|null, connection_id?: int|null, batch_id?: int|null, contact_id?: int|null}
 */
class HistoryArchive
{
    public const DESTINATION_ARCHIVE = 'archive';

    public const DESTINATION_IMPORT = 'import';

    public const DESTINATION_ALL = 'all';

    /**
     * @param  ArchiveFilters  $filters
     * @return Builder<Conversation>
     */
    public function query(array $filters, User $viewer): Builder
    {
        $destination = $filters['destination'] ?? self::DESTINATION_ARCHIVE;
        $search = trim((string) ($filters['search'] ?? ''));
        $dateFrom = $filters['date_from'] ?? null;
        $dateTo = $filters['date_to'] ?? null;
        $department = $filters['department_id'] ?? null;
        $agent = $filters['agent_id'] ?? null;
        $protected = app(ProtectedAccounts::class);
        $connection = $filters['connection_id'] ?? null;
        $batch = $filters['batch_id'] ?? null;
        $contact = $filters['contact_id'] ?? null;
        $tag = $filters['tag_id'] ?? null;

        return Conversation::query()
            // Equality on origin: the composite (origin, history_only_at, last_message_at) index applies.
            ->where('conversations.origin', ConversationOrigin::Whaticket->value)
            ->when($destination === self::DESTINATION_ARCHIVE, fn (Builder $query) => $query->whereNotNull('conversations.history_only_at'))
            ->when($destination === self::DESTINATION_IMPORT, fn (Builder $query) => $query->whereNull('conversations.history_only_at'))
            ->when($search !== '', function (Builder $query) use ($search): void {
                $prefixes = PhoneNumber::searchPrefixes($search);
                $query->whereHas('contact', fn (Builder $contact) => $contact->where(fn (Builder $match) => $match
                    ->where('name', 'like', '%'.addcslashes($search, '%_\\').'%')
                    ->orWhere('profile_name', 'like', '%'.addcslashes($search, '%_\\').'%')
                    ->when($prefixes !== [], fn (Builder $phone) => $phone->orWhere(function (Builder $numbers) use ($prefixes): void {
                        foreach ($prefixes as $prefix) {
                            $numbers->orWhere('phone_e164', 'like', '+'.$prefix.'%');
                        }
                    }))));
            })
            ->when($dateFrom !== null, fn (Builder $query) => $query->where('conversations.last_message_at', '>=', HistoryImportBatches::dayStart((string) $dateFrom)))
            ->when($dateTo !== null, fn (Builder $query) => $query->where('conversations.created_at', '<', HistoryImportBatches::dayStart((string) $dateTo)->addDay()))
            ->when($department !== null, fn (Builder $query) => $query->where('conversations.department_id', $department))
            // A protected account is never a valid filter for whoever may not see it (it matches nothing).
            ->when($agent !== null, fn (Builder $query) => $query->where('conversations.assigned_user_id', $agent)
                ->when($protected->hides((int) $agent, $viewer), fn (Builder $hidden) => $hidden->whereRaw('1 = 0')))
            ->when($connection !== null, fn (Builder $query) => $query->where('conversations.connection_id', $connection))
            ->when($batch !== null, fn (Builder $query) => $query->where('conversations.imported_by_transfer_id', $batch))
            ->when($contact !== null, fn (Builder $query) => $query->where('conversations.contact_id', $contact))
            ->when($tag !== null, fn (Builder $query) => $query->whereHas('tags', fn (Builder $tags) => $tags->where('tags.id', $tag)))
            ->with(['contact:id,name,phone_e164,saved_at', 'department:id,name', 'assignee:id,name', 'channelConnection:id,name,channel,deleted_at', 'tags:id,name'])
            ->withCount('messages')
            ->orderByDesc('conversations.last_message_at')
            ->orderByDesc('conversations.id');
    }

    /**
     * Original identifiers of the given conversations (one query per page).
     *
     * @param  list<int>  $conversationIds
     * @return array<int, array<string, mixed>|null>
     */
    public function sourceDetails(array $conversationIds): array
    {
        return HistoryImportSourceKey::query()
            ->where('target_type', 'conversation')
            ->whereIn('target_id', $conversationIds)
            ->where('source_kind', HistoryImportSourceKey::KIND_CONVERSATION)
            ->get(['target_id', 'details'])
            ->mapWithKeys(fn (HistoryImportSourceKey $key): array => [$key->target_id => $key->details])
            ->all();
    }

    /**
     * JSON shape of an imported conversation (protected accounts masked for
     * whoever may not see them).
     *
     * @param  array<string, mixed>|null  $details  Preloaded with sourceDetails().
     * @return array<string, mixed>
     */
    public function present(Conversation $conversation, User $viewer, ?array $details = null): array
    {
        $details ??= $this->sourceDetails([$conversation->id])[$conversation->id] ?? null;
        $protected = app(ProtectedAccounts::class);

        return [
            'id' => $conversation->id,
            'origin' => $conversation->origin->value,
            'origin_label' => $conversation->origin->label(),
            'destination' => $conversation->isHistoryOnly() ? self::DESTINATION_ARCHIVE : self::DESTINATION_IMPORT,
            'read_only' => true,
            'contact' => $conversation->contact === null ? null : [
                'id' => $conversation->contact->id,
                'name' => $conversation->contact->name,
                'phone' => $conversation->contact->phone_e164,
                'saved' => $conversation->contact->isSaved(),
            ],
            'department' => $conversation->department === null ? null : ['id' => $conversation->department->id, 'name' => $conversation->department->name],
            'agent' => $conversation->assignee === null ? null : [
                'id' => $protected->idFor($conversation->assignee, $viewer),
                'name' => $protected->nameFor($conversation->assignee, $viewer),
            ],
            'connection' => $conversation->channelConnection === null ? null : [
                'id' => $conversation->channelConnection->id,
                'name' => $conversation->channelConnection->name,
                'historical' => $conversation->channelConnection->isDeleted(),
            ],
            'tags' => $conversation->tags->map(fn ($tag): array => ['id' => $tag->id, 'name' => $tag->name])->values()->all(),
            'started_at' => $conversation->created_at?->toIso8601String(),
            'last_message_at' => $conversation->last_message_at?->toIso8601String(),
            'closed_at' => $conversation->closed_at?->toIso8601String(),
            'archived_at' => $conversation->history_only_at?->toIso8601String(),
            'messages_count' => (int) ($conversation->getAttribute('messages_count') ?? 0),
            'batch_id' => $conversation->imported_by_transfer_id,
            'source' => $details ?? (object) [],
        ];
    }
}

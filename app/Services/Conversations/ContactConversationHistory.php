<?php

namespace App\Services\Conversations;

use App\Enums\Permission;
use App\Models\Conversation;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * The conversations of one contact listed on the contact page and in the
 * conversation's details panel: those within the viewer's reach and, only
 * for whoever consults the imported history (history-import.archive-view),
 * the ones archived as read-only history too. Each item says where it comes
 * from and, for that same permission, links to the history archive.
 */
class ContactConversationHistory
{
    /**
     * @return Builder<Conversation>
     */
    public function query(User $viewer, int $contactId): Builder
    {
        $readsArchive = $this->readsArchive($viewer);

        return Conversation::query()
            ->where('contact_id', $contactId)
            ->where(fn (Builder $reach) => $reach
                ->where(fn (Builder $visible) => $visible->visibleTo($viewer))
                ->when($readsArchive, fn (Builder $archived) => $archived->orWhereNotNull('history_only_at')));
    }

    /**
     * @return array{origin: string, origin_label: string, history_only: bool, history_url: string|null}
     */
    public function describe(Conversation $conversation, User $viewer): array
    {
        return [
            'origin' => $conversation->origin->value,
            'origin_label' => $conversation->origin->label(),
            'history_only' => $conversation->isHistoryOnly(),
            'history_url' => $conversation->isImported() && $this->readsArchive($viewer)
                ? route('admin.history.conversations.show', $conversation)
                : null,
        ];
    }

    private function readsArchive(User $viewer): bool
    {
        return $viewer->holdsPermission(Permission::ViewArchivedHistory);
    }
}

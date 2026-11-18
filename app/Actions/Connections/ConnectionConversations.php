<?php

namespace App\Actions\Connections;

use App\Enums\ConversationOrigin;
use App\Models\Connection;
use App\Models\Conversation;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\Rule;

/**
 * The conversations a connection holds, as its own screen lists them: every
 * conversation whatever its status, live or imported, including history
 * archived as read-only (Whaticket). The connections module is global
 * (ConnectionPolicy requires the organization-wide scope), so the list is
 * not narrowed by departments; it is shared by the list and by «move all»,
 * so selecting every result moves exactly what was listed.
 *
 * Kinds: `live` (received or sent by the platform), `imported` (Whaticket
 * or QR history shown in the inbox, closed), `archived` (Whaticket history
 * kept only in «Historial importado»).
 */
class ConnectionConversations
{
    public const KINDS = ['all', 'live', 'imported', 'archived'];

    /**
     * @return array<string, mixed>
     */
    public static function rules(): array
    {
        return [
            'kind' => ['nullable', Rule::in(self::KINDS)],
            'search' => ['nullable', 'string', 'max:120'],
        ];
    }

    /**
     * @param  array{kind?: string|null, search?: string|null}  $filters
     * @return Builder<Conversation>
     */
    public function query(Connection $connection, array $filters): Builder
    {
        $kind = $filters['kind'] ?? 'all';
        $search = trim((string) ($filters['search'] ?? ''));

        return Conversation::query()
            ->where('conversations.connection_id', $connection->id)
            ->when($kind === 'live', fn (Builder $query) => $query->where('conversations.origin', ConversationOrigin::Live->value))
            ->when($kind === 'imported', fn (Builder $query) => $query
                ->where('conversations.origin', '!=', ConversationOrigin::Live->value)
                ->whereNull('conversations.history_only_at'))
            ->when($kind === 'archived', fn (Builder $query) => $query->whereNotNull('conversations.history_only_at'))
            ->when($search !== '', function (Builder $query) use ($search): void {
                $like = '%'.addcslashes($search, '%_\\').'%';
                $digits = preg_replace('/\D+/', '', $search) ?? '';

                // Grouped, so the OR never escapes the correlation with the conversation.
                $query->whereHas('contact', fn (Builder $contact) => $contact->where(fn (Builder $match) => $match
                    ->where('name', 'like', $like)
                    ->orWhere('profile_name', 'like', $like)
                    ->orWhere('email', 'like', $like)
                    ->when(strlen($digits) >= 3, fn (Builder $phone) => $phone->orWhere('phone_e164', 'like', '%'.$digits.'%'))));
            });
    }

    /**
     * Counters of the connection by kind (never filtered by the search).
     *
     * @return array{all: int, live: int, imported: int, archived: int, active: int}
     */
    public function counts(Connection $connection): array
    {
        $row = Conversation::query()
            ->where('connection_id', $connection->id)
            ->selectRaw('COUNT(*) AS total')
            ->selectRaw('SUM(CASE WHEN origin = ? THEN 1 ELSE 0 END) AS live', [ConversationOrigin::Live->value])
            ->selectRaw('SUM(CASE WHEN origin <> ? AND history_only_at IS NULL THEN 1 ELSE 0 END) AS imported', [ConversationOrigin::Live->value])
            ->selectRaw('SUM(CASE WHEN history_only_at IS NOT NULL THEN 1 ELSE 0 END) AS archived')
            ->selectRaw("SUM(CASE WHEN status <> 'closed' THEN 1 ELSE 0 END) AS active")
            ->toBase()
            ->first();

        return [
            'all' => (int) ($row->total ?? 0),
            'live' => (int) ($row->live ?? 0),
            'imported' => (int) ($row->imported ?? 0),
            'archived' => (int) ($row->archived ?? 0),
            'active' => (int) ($row->active ?? 0),
        ];
    }
}

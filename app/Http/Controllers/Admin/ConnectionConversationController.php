<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Connections\ConnectionConversations;
use App\Actions\Connections\MoveConnectionConversations;
use App\Http\Controllers\Controller;
use App\Models\Connection;
use App\Models\Conversation;
use App\Models\User;
use App\Policies\HistoryImportPolicy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;

/**
 * «Conversaciones» of a connection's screen: every conversation it holds
 * (live, imported and archived history) with a link to open each one, and
 * the bulk move of a selection, or of every listed conversation, to another
 * connection of the same channel.
 */
class ConnectionConversationController extends Controller
{
    private const PER_PAGE = 50;

    public function index(Request $request, Connection $connection, ConnectionConversations $list, HistoryImportPolicy $archive): JsonResponse
    {
        Gate::authorize('view', $connection);

        /** @var User $actor */
        $actor = $request->user();
        $filters = $request->validate([...ConnectionConversations::rules(), 'page' => ['nullable', 'integer', 'min:1']]);

        $page = $list->query($connection, $filters)
            ->with('contact:id,name,profile_name,phone_e164,email')
            ->withCount('messages')
            ->orderByDesc('last_message_at')
            ->orderByDesc('id')
            ->paginate(self::PER_PAGE);

        return response()->json([
            'data' => $page->getCollection()->map(fn (Conversation $conversation): array => [
                'id' => $conversation->id,
                'contact' => [
                    'name' => $conversation->contact?->name ?: ($conversation->contact?->profile_name ?: 'Sin nombre'),
                    'phone' => $conversation->contact?->phone_e164,
                ],
                'status' => ['value' => $conversation->status->value, 'label' => $conversation->status->label()],
                'origin' => ['value' => $conversation->origin->value, 'label' => $conversation->origin->label()],
                'history_only' => $conversation->isHistoryOnly(),
                'messages_count' => (int) $conversation->messages_count,
                'last_message_at' => $conversation->last_message_at?->toIso8601String(),
                'url' => $this->openUrl($actor, $conversation, $archive),
            ])->all(),
            'meta' => [
                'total' => $page->total(),
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
            ],
            'counts' => $list->counts($connection),
        ]);
    }

    public function move(Request $request, Connection $connection, ConnectionConversations $list, MoveConnectionConversations $move): RedirectResponse
    {
        Gate::authorize('moveConversations', $connection);

        /** @var User $actor */
        $actor = $request->user();

        $validated = $request->validate([
            ...ConnectionConversations::rules(),
            'target_connection_id' => ['required', 'integer', 'exists:connections,id'],
            'all' => ['boolean'],
            'conversation_ids' => ['required_unless:all,true', 'array', 'max:5000'],
            'conversation_ids.*' => ['integer'],
            'show_archived' => ['boolean'],
        ], [
            'target_connection_id.required' => 'Elige la conexión de destino.',
            'conversation_ids.required_unless' => 'Selecciona al menos una conversación.',
        ]);

        /** @var Connection $target */
        $target = Connection::query()->findOrFail((int) $validated['target_connection_id']);
        $move->ensureValidTarget($connection, $target);

        // Whatever is moved always belongs to this connection: the
        // selection is narrowed by the same query that lists it.
        $ids = $list->query($connection, $validated)
            ->when(! $request->boolean('all'), fn (Builder $query) => $query->whereKey(array_map('intval', (array) ($validated['conversation_ids'] ?? []))))
            ->pluck('conversations.id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all();

        $result = $move->handle($connection, $target, $ids, $request->boolean('show_archived'), $actor);

        $message = $result['moved'] === 1
            ? "1 conversación movida a {$target->name}."
            : "{$result['moved']} conversaciones movidas a {$target->name}.";

        if ($result['unarchived'] > 0) {
            $message .= " {$result['unarchived']} del historial archivado ahora se ven en la bandeja (cerradas).";
        }

        if ($result['closed'] > 0) {
            $message .= " {$result['closed']} contactos tenían conversación activa en las dos conexiones: quedó activa la más reciente y la otra se cerró.";
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => $message]);

        return back();
    }

    /**
     * Where the conversation opens for this person: the inbox when they reach
     * it, otherwise the imported-history viewer, or nowhere.
     */
    private function openUrl(User $actor, Conversation $conversation, HistoryImportPolicy $archive): ?string
    {
        if ($actor->can('view', $conversation)) {
            return route('conversations.show', $conversation);
        }

        if ($archive->viewArchived($actor, $conversation)->allowed()) {
            return route('admin.history.conversations.show', $conversation);
        }

        return null;
    }
}

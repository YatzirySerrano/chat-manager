<?php

namespace App\Http\Controllers\Conversations;

use App\Actions\Contacts\ContactSearch;
use App\Actions\Conversations\StartConversation;
use App\Enums\ConnectionChannel;
use App\Enums\ContactStatus;
use App\Enums\Permission;
use App\Exceptions\ConversationConflict;
use App\Http\Controllers\Controller;
use App\Models\Connection;
use App\Models\Contact;
use App\Models\User;
use App\Services\Conversations\ConversationRouter;
use App\Services\WhatsAppWeb\WhatsAppWebContactProfiles;
use App\Support\PhoneNumber;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;

/**
 * «Iniciar chat»: the dialog's choices (connections, departments and
 * contacts) and the start itself (StartConversation).
 */
class ConversationStartController extends Controller
{
    private const CONTACT_LIMIT = 20;

    /**
     * WhatsApp connections the actor may write from, and the departments of
     * the chosen one (the first one when none is chosen).
     */
    public function options(Request $request, StartConversation $start, ConversationRouter $router): JsonResponse
    {
        /** @var User $actor */
        $actor = $request->user();
        abort_unless($actor->holdsPermission(Permission::ReplyConversations), 403);

        $connections = $start->connections($actor);
        $selected = $connections->firstWhere('id', $request->integer('connection_id')) ?? $connections->first();
        $departments = $selected === null ? collect() : $start->departments($actor, $selected);
        $routed = $selected === null ? null : $router->route($selected)['department'];

        return response()->json([
            'connections' => $connections->map(fn (Connection $connection): array => [
                'id' => $connection->id,
                'name' => $connection->name,
                'phone' => $connection->display_phone_number,
                'modality' => $connection->isWhatsAppWeb() ? 'qr' : 'cloud_api',
                'blocked_reason' => $connection->sendingBlockReason(),
            ])->values(),
            'connection_id' => $selected?->id,
            'departments' => $departments->map(fn ($department): array => [
                'id' => $department->id,
                'name' => $department->name,
                'color' => $department->color->value,
            ])->values(),
            'default_department_id' => $departments->contains('id', $routed?->id)
                ? $routed?->id
                : ($departments->first(fn ($department): bool => in_array($department->id, $actor->departmentIds(), true)) ?? $departments->first())?->id,
        ]);
    }

    /**
     * Contacts the actor can see that have a WhatsApp number: the most
     * recent ones first (who wrote last), or those matching the search.
     */
    public function contacts(Request $request, ContactSearch $search): JsonResponse
    {
        /** @var User $actor */
        $actor = $request->user();
        abort_unless($actor->holdsPermission(Permission::ReplyConversations), 403);

        $term = trim((string) $request->query('q', ''));

        $query = Contact::query()
            ->visibleTo($actor)
            ->where('contacts.status', ContactStatus::Active)
            ->where(fn ($reachable) => $reachable
                ->whereNotNull('contacts.phone_e164')
                ->orWhereHas('identities', fn ($identities) => $identities->where('channel', ConnectionChannel::Whatsapp)))
            ->with('whatsappIdentity');

        if ($term !== '') {
            $search->constrain($query, mb_substr($term, 0, 100));
        }

        $contacts = $query
            ->orderByRaw('contacts.last_interaction_at is null')
            ->orderByDesc('contacts.last_interaction_at')
            ->orderByDesc('contacts.id')
            ->limit(self::CONTACT_LIMIT)
            ->get();

        return response()->json([
            'contacts' => $contacts->map(fn (Contact $contact): array => [
                'id' => $contact->id,
                'name' => $contact->name,
                'phone' => self::visiblePhone($contact),
                'avatar_url' => WhatsAppWebContactProfiles::contactAvatarUrl($contact),
                'last_interaction_at' => $contact->last_interaction_at?->toIso8601String(),
            ])->values(),
            // A typed number the dialog can offer to write to directly.
            'typed_phone' => $term !== '' ? PhoneNumber::normalize($term) : null,
        ]);
    }

    /**
     * The contact's phone, or the digits of its WhatsApp id when it is a
     * phone. A privacy id (LID, "lid:…") is never shown as a number.
     */
    private static function visiblePhone(Contact $contact): ?string
    {
        if ($contact->phone_e164 !== null) {
            return PhoneNumber::format($contact->phone_e164) ?? $contact->phone_e164;
        }

        $id = (string) $contact->whatsappIdentity?->external_id;

        return ctype_digit($id) ? PhoneNumber::format('+'.$id) : null;
    }

    public function store(Request $request, StartConversation $start): RedirectResponse
    {
        /** @var User $actor */
        $actor = $request->user();

        $validated = $request->validate([
            'connection_id' => ['required', 'integer'],
            'department_id' => ['required', 'integer'],
            'contact_id' => ['nullable', 'integer', 'required_without:phone'],
            'phone' => ['nullable', 'string', 'max:32', 'required_without:contact_id'],
            'name' => ['nullable', 'string', 'max:255'],
        ], [
            'department_id.required' => 'Elige el departamento de la conversación.',
            'contact_id.required_without' => 'Elige un contacto o escribe un número.',
            'phone.required_without' => 'Elige un contacto o escribe un número.',
        ]);

        $connection = Connection::query()->whereKey((int) $validated['connection_id'])->first();

        if ($connection === null) {
            return back()->withErrors(['connection_id' => 'La conexión ya no existe.']);
        }

        $contact = null;
        $phone = null;

        if (! empty($validated['contact_id'])) {
            $contact = Contact::query()->whereKey((int) $validated['contact_id'])->first();

            if ($contact === null || ! Gate::allows('view', $contact)) {
                return back()->withErrors(['contact_id' => 'No encontramos ese contacto.']);
            }
        } else {
            $phone = PhoneNumber::normalize($validated['phone'] ?? null);

            if ($phone === null || ! PhoneNumber::isValid($phone)) {
                return back()->withErrors(['phone' => 'Escribe un número válido con su lada, por ejemplo 55 1234 5678 o +52 1 55 1234 5678.']);
            }
        }

        try {
            ['conversation' => $conversation, 'outcome' => $outcome] = $start->handle(
                $actor,
                $connection,
                $contact,
                $phone,
                $validated['name'] ?? null,
                (int) $validated['department_id'],
            );
        } catch (ConversationConflict $conflict) {
            return back()->withErrors(['start' => $conflict->getMessage()]);
        }

        $window = $connection->usesCustomerServiceWindow()
            ? ' Si el cliente no ha escrito en las últimas 24 horas, envía una plantilla aprobada.'
            : '';

        Inertia::flash('toast', ['type' => $outcome === 'queued' ? 'warning' : 'success', 'message' => match ($outcome) {
            'existing' => 'Ya tenías una conversación abierta con este contacto: te llevamos a ella.'.$window,
            'created' => 'Chat iniciado y asignado a ti. Escribe tu mensaje.'.$window,
            'queued' => 'Chat iniciado; quedó en «Pendientes» de su departamento porque las reglas de la cola no te lo asignan ahora.',
        }]);

        return $actor->can('view', $conversation)
            ? redirect()->route('conversations.show', $conversation)
            : redirect()->to(ConversationController::listUrl());
    }
}

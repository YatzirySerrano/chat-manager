<?php

namespace App\Http\Controllers\Contacts;

use App\Actions\Contacts\ContactListQuery;
use App\Actions\Contacts\CreateContact;
use App\Actions\Contacts\DeleteContact;
use App\Actions\Contacts\FindDuplicateContacts;
use App\Actions\Contacts\FlagHiddenDuplicates;
use App\Actions\Contacts\RestoreContact;
use App\Actions\Contacts\UpdateContact;
use App\Enums\ContactOriginType;
use App\Enums\ContactSource;
use App\Enums\Permission;
use App\Enums\RecordState;
use App\Http\Controllers\Controller;
use App\Http\Requests\Contacts\ContactRequest;
use App\Http\Resources\ContactAttributionResource;
use App\Http\Resources\ContactResource;
use App\Models\Connection;
use App\Models\Contact;
use App\Models\ContactNote;
use App\Models\ContactReviewCase;
use App\Models\Conversation;
use App\Models\Tag;
use App\Models\User;
use App\Services\Conversations\ContactConversationHistory;
use App\Services\Exports\ReportExporter;
use App\Services\ResolveContactOrigin;
use App\Support\ProtectedAccounts;
use App\Support\SaveToast;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\Pivot;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class ContactController extends Controller
{
    /**
     * Contacts within the actor's data scope, searched, filtered and paginated
     * (the same query as the contacts report). Deleted (archived) contacts are
     * only listed for people who can consult deleted records and ask for them;
     * provisional contacts (unknown senders not saved into the directory) only
     * when "Incluir no guardados" is on.
     */
    public function index(Request $request, ContactListQuery $list, ReportExporter $exporter): Response
    {
        Gate::authorize('viewAny', Contact::class);

        /** @var User $actor */
        $actor = $request->user();

        $filters = $list->filters($actor, $request->validate(ContactListQuery::rules()));
        $search = $filters['search'];
        $record = $filters['record'];
        $tagIds = $filters['tags'];
        $source = $filters['source'];
        $origin = $filters['origin'];
        $sort = $filters['sort'];
        $includeProvisional = $filters['include_provisional'];

        $contacts = $list->handle($actor, $filters)
            ->with([
                'tags' => fn (BelongsToMany $query) => $query
                    ->select('tags.id', 'tags.name', 'tags.color', 'tags.is_active')
                    ->orderBy('tags.name'),
                'attributions',
            ])
            ->paginate(15)
            ->withQueryString();

        return Inertia::render('contacts/Index', [
            'contacts' => ContactResource::collection($contacts),
            'filters' => [
                'search' => $search,
                'record' => $record->value,
                'tags' => $tagIds,
                'source' => $source,
                'origin' => $origin,
                'sort' => $sort,
                'include_provisional' => $includeProvisional,
            ],
            // The tag catalog is only offered as a filter to users allowed to see it.
            'tags' => $actor->holdsPermission(Permission::ViewTags)
                || $actor->holdsPermission(Permission::AssignTags)
                || $actor->holdsPermission(Permission::ManageTags)
                ? Tag::query()
                    ->hidingDeletedFrom($actor)
                    ->orderByDesc('is_active')
                    ->orderBy('name')
                    ->get(['id', 'name', 'color', 'is_active'])
                    ->map(fn (Tag $tag): array => [
                        'id' => $tag->id,
                        'name' => $tag->name,
                        'color' => $tag->color->value,
                        'is_active' => $tag->is_active,
                    ])
                : null,
            'recordStates' => RecordState::optionsFor($actor),
            'sources' => ContactSource::options(),
            'originTypes' => ContactOriginType::options(),
            'can' => [
                'create' => $actor->can('create', Contact::class),
                'reviewDuplicates' => $actor->can('reviewDuplicates', Contact::class),
                'import' => $actor->holdsPermission(Permission::ImportContacts),
            ],
            'canExport' => $exporter->allowsReport('contacts', $actor),
            'openReviews' => $actor->can('reviewDuplicates', Contact::class)
                ? ContactReviewCase::query()->open()->count()
                : null,
        ]);
    }

    public function store(ContactRequest $request, CreateContact $createContact, FindDuplicateContacts $findDuplicates, FlagHiddenDuplicates $flagHiddenDuplicates): RedirectResponse
    {
        $payload = $request->payload();

        $this->ensureDuplicatesConfirmed($request, $findDuplicates, $payload['phone_e164'], $payload['email']);

        $contact = $createContact->handle($payload, $request->user(), $request->confirmsDuplicate());
        $flagHiddenDuplicates->handle($contact, $request->user(), $payload['phone_e164'], $payload['email']);

        Inertia::flash('toast', ['type' => 'success', 'message' => "Contacto {$contact->name} creado."]);

        return to_route('contacts.show', $contact);
    }

    /**
     * Contact profile: identity, origin, tags, private notes and the
     * conversations the actor is allowed to see.
     */
    public function show(Request $request, Contact $contact, ResolveContactOrigin $resolveOrigin): Response
    {
        Gate::authorize('view', $contact);

        /** @var User $actor */
        $actor = $request->user();

        $contact->load([
            'creator:id,name',
            'identities' => fn ($query) => $query->orderBy('id'),
            'tags' => fn (BelongsToMany $query) => $query
                ->select('tags.id', 'tags.name', 'tags.color', 'tags.is_active')
                ->orderBy('tags.name'),
            'attributions' => fn ($query) => $query->with('recorder:id,name')->latest('captured_at')->latest('id'),
            'connections' => fn (BelongsToMany $query) => $query
                ->select('connections.id', 'connections.name', 'connections.channel', 'connections.display_phone_number')
                ->orderBy('connection_contact.first_interaction_at')
                ->orderBy('connections.id'),
        ]);

        $canManageNotes = $actor->can('manageNotes', $contact);
        $currentOrigin = $resolveOrigin->best($contact->attributions);

        return Inertia::render('contacts/Show', [
            'contact' => ContactResource::make($contact)->resolve($request),
            'origin' => [
                'current' => $currentOrigin === null ? null : ContactAttributionResource::make($currentOrigin)->resolve($request),
                'history' => ContactAttributionResource::collection($contact->attributions)->resolve($request),
                'connections' => $this->receivingConnections($contact),
            ],
            'notes' => $canManageNotes ? $this->notes($contact) : null,
            'conversations' => $this->conversations($contact, $actor),
            'originTypes' => array_values(array_filter(
                ContactOriginType::options(),
                fn (array $option): bool => $option['value'] !== ContactOriginType::Unknown->value
                    && ! ContactOriginType::from($option['value'])->isProviderReported(),
            )),
            'availableTags' => $actor->holdsPermission(Permission::AssignTags)
                ? Tag::query()->where('is_active', true)->orderBy('name')->get(['id', 'name', 'color', 'is_active'])
                    ->map(fn (Tag $tag): array => [
                        'id' => $tag->id,
                        'name' => $tag->name,
                        'color' => $tag->color->value,
                        'is_active' => $tag->is_active,
                    ])
                : [],
            'can' => [
                'update' => $actor->can('update', $contact),
                'delete' => $actor->can('delete', $contact),
                'restore' => $actor->can('restore', $contact),
                'manageNotes' => $canManageNotes,
                'classifyOrigin' => $actor->can('classifyOrigin', $contact),
                'assignTags' => $actor->holdsPermission(Permission::AssignTags),
            ],
        ]);
    }

    public function update(ContactRequest $request, Contact $contact, UpdateContact $updateContact, FindDuplicateContacts $findDuplicates, FlagHiddenDuplicates $flagHiddenDuplicates): RedirectResponse
    {
        $payload = $request->payload();

        $phoneChanged = $payload['phone_e164'] !== null && $payload['phone_e164'] !== $contact->phone_e164;
        $emailChanged = $payload['email'] !== null && $payload['email'] !== mb_strtolower((string) $contact->email);

        $this->ensureDuplicatesConfirmed(
            $request,
            $findDuplicates,
            $phoneChanged ? $payload['phone_e164'] : null,
            $emailChanged ? $payload['email'] : null,
            $contact,
        );

        $changed = $updateContact->handle($contact, $payload, $request->user(), $request->confirmsDuplicate());

        if ($changed) {
            $flagHiddenDuplicates->handle(
                $contact,
                $request->user(),
                $phoneChanged ? $payload['phone_e164'] : null,
                $emailChanged ? $payload['email'] : null,
            );
        }

        SaveToast::flash($changed, 'Contacto actualizado.');

        return back();
    }

    /**
     * Logical deletion (archiving): the history is kept and the person keeps
     * being recognized if they write again.
     */
    public function destroy(Request $request, Contact $contact, DeleteContact $deleteContact): RedirectResponse
    {
        Gate::authorize('delete', $contact);

        $deleteContact->handle($contact, $request->user());

        Inertia::flash('toast', ['type' => 'success', 'message' => "Contacto {$contact->name} eliminado. Su historial se conserva."]);

        // Deleted from its own detail page by someone who cannot consult
        // deleted records: that page no longer exists for them.
        $leavesDetail = ! $request->user()->canViewDeletedRecords()
            && parse_url(url()->previous(), PHP_URL_PATH) === parse_url(route('contacts.show', $contact), PHP_URL_PATH);

        return $leavesDetail ? to_route('contacts.index') : back();
    }

    public function restore(Request $request, Contact $contact, RestoreContact $restoreContact): RedirectResponse
    {
        Gate::authorize('restore', $contact);

        $restoreContact->handle($contact, $request->user());

        Inertia::flash('toast', ['type' => 'success', 'message' => "Contacto {$contact->name} restaurado."]);

        return back();
    }

    /**
     * Stop the save while there are possible duplicates the user has not
     * confirmed. Only contacts inside the actor's scope are considered, so
     * nothing is revealed about contacts the actor cannot see; matches
     * outside it go to administrative review (see FlagHiddenDuplicates).
     *
     * @throws ValidationException
     */
    private function ensureDuplicatesConfirmed(
        ContactRequest $request,
        FindDuplicateContacts $findDuplicates,
        ?string $phone,
        ?string $email,
        ?Contact $ignore = null,
    ): void {
        if ($request->confirmsDuplicate()) {
            return;
        }

        $duplicates = $findDuplicates->handle($phone, $email, $request->user(), $ignore);

        $phoneTaken = array_any($duplicates, fn (array $match): bool => in_array('phone', $match['matches'], true));
        $emailTaken = array_any($duplicates, fn (array $match): bool => in_array('email', $match['matches'], true));

        if (! $phoneTaken && ! $emailTaken) {
            return;
        }

        Inertia::flash('duplicates', ['contacts' => $duplicates]);

        throw ValidationException::withMessages(array_filter([
            'phone' => $phoneTaken ? 'Ya existe un contacto con este teléfono.' : null,
            'email' => $emailTaken ? 'Ya existe un contacto con este correo electrónico.' : null,
            'confirm_duplicate' => 'Revisa los posibles duplicados. Si se trata de otra persona, confirma para guardarlo de todos modos.',
        ]));
    }

    /**
     * @return list<array{id: int, body: string, author: array{id: int|null, name: string}|null, created_at: string|null}>
     */
    private function notes(Contact $contact): array
    {
        return array_values($contact->notes()
            ->with('author:id,name')
            ->limit(200)
            ->get()
            ->map(fn (ContactNote $note): array => [
                'id' => $note->id,
                'body' => $note->body,
                'author' => $note->author === null ? null : ['id' => app(ProtectedAccounts::class)->idForRequest($note->author, request()), 'name' => (string) app(ProtectedAccounts::class)->nameForRequest($note->author, request())],
                'created_at' => $note->created_at?->toIso8601String(),
            ])
            ->all());
    }

    /**
     * Connections (numbers) through which the contact reached the company,
     * in the order it first wrote to each one. No credential is exposed.
     *
     * @return list<array{id: int, name: string, channel_label: string, display_phone_number: string|null, first_interaction_at: string|null, last_interaction_at: string|null}>
     */
    private function receivingConnections(Contact $contact): array
    {
        return array_values($contact->connections
            ->map(fn (Connection $connection): array => [
                'id' => $connection->id,
                'name' => $connection->name,
                'channel_label' => $connection->channel->label(),
                'display_phone_number' => $connection->display_phone_number,
                'first_interaction_at' => $this->pivotTimestamp($connection, 'first_interaction_at'),
                'last_interaction_at' => $this->pivotTimestamp($connection, 'last_interaction_at'),
            ])
            ->all());
    }

    private function pivotTimestamp(Connection $connection, string $column): ?string
    {
        $pivot = $connection->getRelation('pivot');
        $value = $pivot instanceof Pivot ? $pivot->getAttribute($column) : null;

        return $value === null ? null : Carbon::parse($value)->toIso8601String();
    }

    /**
     * Conversations with the contact that the actor may see, newest first.
     *
     * @return list<array<string, mixed>>
     */
    private function conversations(Contact $contact, User $actor): array
    {
        $history = app(ContactConversationHistory::class);

        return array_values($history->query($actor, $contact->id)
            ->with([
                'channelConnection:id,name,display_phone_number',
                'department:id,name,color,is_active',
                'assignee:id,name',
            ])
            ->latest()
            ->latest('id')
            ->limit(50)
            ->get()
            ->map(fn (Conversation $conversation): array => [
                'id' => $conversation->id,
                'status' => $conversation->status->value,
                'status_label' => $conversation->status->label(),
                'connection' => $conversation->channelConnection === null ? null : [
                    'id' => $conversation->channelConnection->id,
                    'name' => $conversation->channelConnection->name,
                    'display_phone_number' => $conversation->channelConnection->display_phone_number,
                ],
                'department' => $conversation->department === null ? null : [
                    'id' => $conversation->department->id,
                    'name' => $conversation->department->name,
                    'color' => $conversation->department->color->value,
                    'is_active' => $conversation->department->is_active,
                ],
                'assignee' => $conversation->assignee === null ? null : [
                    'id' => app(ProtectedAccounts::class)->idFor($conversation->assignee, $actor),
                    'name' => app(ProtectedAccounts::class)->nameFor($conversation->assignee, $actor),
                ],
                'created_at' => $conversation->created_at?->toIso8601String(),
                'last_message_at' => $conversation->last_message_at?->toIso8601String(),
                'closed_at' => $conversation->closed_at?->toIso8601String(),
                ...$history->describe($conversation, $actor),
            ])
            ->all());
    }
}

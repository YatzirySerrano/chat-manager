<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Personalization\SetContactAlias;
use App\Enums\AuditAction;
use App\Enums\ChatAppearancePreset;
use App\Enums\Permission;
use App\Http\Controllers\Controller;
use App\Http\Requests\Personalization\ChatAppearanceRequest;
use App\Http\Requests\Personalization\ContactAliasRequest;
use App\Models\ChatAppearance;
use App\Models\Contact;
use App\Models\ContactAlias;
use App\Models\Department;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\Personalization\ChatAppearances;
use App\Services\Personalization\PersonalItemSupervision;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * "Supervisión de elementos personales": an authorized administrator
 * consults other users' aliases, chat appearances and personal quick
 * replies within their data scope, and with the manage permission edits or
 * deletes aliases and appearances. Nothing seen here is applied to the
 * administrator's own view. Every intervention is audited with the owner
 * and the item, never with the alias text.
 */
class PersonalItemController extends Controller
{
    public const TYPE_ALIAS = 'alias';

    public const TYPE_APPEARANCE = 'appearance';

    private const PER_PAGE = 25;

    public const HIDDEN_CONTACT_LABEL = 'Contacto fuera de tu alcance';

    /**
     * Contacts of the current page the actor may view (withVisibleContacts()).
     *
     * @var array<int, int>
     */
    private array $visibleContactIds = [];

    public function __construct(private PersonalItemSupervision $supervision) {}

    public function index(Request $request, ChatAppearances $appearances): Response
    {
        /** @var User $actor */
        $actor = $request->user();

        abort_unless($this->supervision->canSupervise($actor), 403);

        $validated = $request->validate([
            'type' => ['nullable', Rule::in([self::TYPE_ALIAS, self::TYPE_APPEARANCE])],
            'user' => ['nullable', 'integer', 'min:1'],
            'department' => ['nullable', 'integer', 'min:1'],
        ]);

        $type = $validated['type'] ?? self::TYPE_ALIAS;
        $owners = $this->owners($actor, $validated);
        $canManage = $actor->holdsPermission(Permission::ManagePersonalItems);
        $managed = [];
        $canManageOwner = function (User $owner) use ($actor, $canManage, &$managed): bool {
            return $managed[$owner->id] ??= $canManage && $this->supervision->canManage($actor, $owner);
        };
        // Authority over each owner's account (canManage()) reads their role
        // and departments: loaded with the page, only when it is asked.
        $ownerRelations = $canManage ? ['user.roles.permissions', 'user.departments:departments.id'] : [];

        $items = match ($type) {
            self::TYPE_APPEARANCE => $this->withVisibleContacts($actor, ChatAppearance::query()
                ->whereIn('user_id', $owners)
                ->with(['user:id,name', 'conversation:id,contact_id', 'conversation.contact:id,name', ...$ownerRelations])
                ->latest('updated_at')
                ->paginate(self::PER_PAGE)
                ->withQueryString(), fn (ChatAppearance $appearance): ?int => $appearance->conversation?->contact_id)
                ->through(fn (ChatAppearance $appearance): array => [
                    'id' => $appearance->id,
                    'owner' => ['id' => $appearance->user->id, 'name' => $appearance->user->name],
                    'scope_label' => $appearance->isDefault()
                        ? 'Todas sus conversaciones'
                        : 'Conversación #'.$appearance->conversation_id.($appearance->conversation?->contact === null ? '' : ' · '.$this->contactLabel($appearance->conversation->contact->id, $appearance->conversation->contact->name)),
                    ...($appearances->present($appearance) ?? []),
                    'can' => ['update' => $canManageOwner($appearance->user), 'delete' => $canManageOwner($appearance->user)],
                ]),
            default => $this->withVisibleContacts($actor, ContactAlias::query()
                ->whereIn('user_id', $owners)
                ->with(['user:id,name', 'contact:id,name,profile_name,phone_e164', ...$ownerRelations])
                ->latest('updated_at')
                ->paginate(self::PER_PAGE)
                ->withQueryString(), fn (ContactAlias $alias): int => $alias->contact_id)
                ->through(fn (ContactAlias $alias): array => [
                    'id' => $alias->id,
                    'alias' => $alias->alias,
                    'owner' => ['id' => $alias->user->id, 'name' => $alias->user->name],
                    'contact' => [
                        'id' => $alias->contact->id,
                        'name' => $this->contactLabel($alias->contact->id, $alias->contact->name),
                        'phone_e164' => $this->canSeeContact($alias->contact->id) ? $alias->contact->phone_e164 : null,
                    ],
                    'updated_at' => $alias->updated_at?->toIso8601String(),
                    'can' => ['update' => $canManageOwner($alias->user), 'delete' => $canManageOwner($alias->user)],
                ]),
        };

        return Inertia::render('admin/personal-items/Index', [
            'type' => $type,
            'filters' => [
                'user' => $validated['user'] ?? null,
                'department' => $validated['department'] ?? null,
            ],
            'items' => JsonResource::collection($items),
            'users' => $this->supervision->owners($actor)->hidingDeletedFrom($actor)->orderBy('name')->get(['id', 'name']),
            'departments' => Department::query()->visibleTo($actor)->hidingDeletedFrom($actor)->orderBy('name')->get(['id', 'name']),
            'presets' => ChatAppearancePreset::options(),
            'can' => ['manage' => $canManage],
        ]);
    }

    public function updateAlias(ContactAliasRequest $request, ContactAlias $alias, SetContactAlias $setAlias, AuditLogger $audit): RedirectResponse
    {
        $owner = $alias->user;
        $record = $setAlias->handle($owner, $alias->contact, $request->alias());

        $this->audit($audit, $record === null ? AuditAction::SupervisionPersonalItemDeleted : AuditAction::SupervisionPersonalItemUpdated, $owner, 'contact_alias', $alias->id, ['contact_id' => $alias->contact_id]);

        Inertia::flash('toast', ['type' => 'success', 'message' => $record === null ? 'Alias eliminado.' : 'Alias actualizado.']);

        return back();
    }

    public function destroyAlias(ContactAlias $alias, SetContactAlias $setAlias, AuditLogger $audit): RedirectResponse
    {
        Gate::authorize('delete', $alias);

        $owner = $alias->user;
        $setAlias->handle($owner, $alias->contact, null);

        $this->audit($audit, AuditAction::SupervisionPersonalItemDeleted, $owner, 'contact_alias', $alias->id, ['contact_id' => $alias->contact_id]);

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Alias eliminado.']);

        return back();
    }

    public function updateAppearance(ChatAppearanceRequest $request, ChatAppearance $appearance, ChatAppearances $appearances, AuditLogger $audit): RedirectResponse
    {
        $owner = $appearance->user;

        ['adjusted' => $adjusted] = $appearances->save($owner, $appearance->conversation, $request->preset(), $request->colors());

        $this->audit($audit, AuditAction::SupervisionPersonalItemUpdated, $owner, 'chat_appearance', $appearance->id, ['conversation_id' => $appearance->conversation_id]);

        Inertia::flash('toast', ['type' => 'success', 'message' => $adjusted ? 'Apariencia actualizada. '.ChatAppearances::ADJUSTED_NOTICE : 'Apariencia actualizada.']);

        return back();
    }

    public function destroyAppearance(ChatAppearance $appearance, AuditLogger $audit): RedirectResponse
    {
        Gate::authorize('delete', $appearance);

        $owner = $appearance->user;
        $appearance->delete();

        $this->audit($audit, AuditAction::SupervisionPersonalItemDeleted, $owner, 'chat_appearance', $appearance->id, ['conversation_id' => $appearance->conversation_id]);

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Apariencia restablecida para ese usuario.']);

        return back();
    }

    /**
     * Resolves, with one query for the page, which contacts of the listed
     * items the actor may view (the same rule as ContactPolicy::view():
     * contacts.view, data scope and deleted records). Only those show their
     * name and phone; supervising a user's items never reveals contacts
     * outside the supervisor's own reach.
     *
     * @template TItem of \Illuminate\Database\Eloquent\Model
     *
     * @param  LengthAwarePaginator<int, TItem>  $page
     * @param  Closure(TItem): ?int  $contactOf
     * @return LengthAwarePaginator<int, TItem>
     */
    private function withVisibleContacts(User $actor, LengthAwarePaginator $page, Closure $contactOf): LengthAwarePaginator
    {
        $ids = collect($page->items())->map($contactOf)->filter()->unique()->values()->all();

        $this->visibleContactIds = $ids === [] ? [] : array_flip(array_map('intval', Contact::query()
            ->visibleTo($actor)
            ->hidingDeletedFrom($actor)
            ->whereKey($ids)
            ->pluck('id')
            ->all()));

        return $page;
    }

    private function canSeeContact(int $contactId): bool
    {
        return isset($this->visibleContactIds[$contactId]);
    }

    private function contactLabel(int $contactId, ?string $name): string
    {
        return $this->canSeeContact($contactId) ? (string) $name : self::HIDDEN_CONTACT_LABEL;
    }

    /**
     * Ids of the owners the actor may supervise, narrowed by the filters.
     *
     * @param  array<string, mixed>  $filters
     * @return Builder<User>
     */
    private function owners(User $actor, array $filters): Builder
    {
        return $this->supervision->owners($actor)
            ->when(isset($filters['user']), fn (Builder $query) => $query->whereKey((int) $filters['user']))
            ->when(isset($filters['department']), fn (Builder $query) => $query->whereHas('departments', fn (Builder $departments) => $departments->whereKey((int) $filters['department'])))
            ->select('users.id');
    }

    /**
     * Who intervened (the actor, recorded by the logger), whose item, which
     * item and what happened. The alias text is never recorded.
     *
     * @param  array<string, int|null>  $extra
     */
    private function audit(AuditLogger $audit, AuditAction $action, User $owner, string $itemType, int $itemId, array $extra = []): void
    {
        $audit->record($action, $owner, [
            'owner_user_id' => $owner->id,
            'item_type' => $itemType,
            'item_id' => $itemId,
            ...$extra,
        ]);
    }
}

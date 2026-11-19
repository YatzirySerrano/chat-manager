<?php

namespace App\Http\Controllers\Chatbot;

use App\Actions\Chatbot\ManageChatbotKeywordRules;
use App\Actions\Chatbot\SaveChatbotKeywordRule;
use App\Actions\Chatbot\SaveChatbotMenu;
use App\Enums\ChatbotSessionStatus;
use App\Enums\ConnectionHealth;
use App\Enums\ConnectionProvider;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ConnectionRequest;
use App\Http\Requests\Chatbot\ChatbotKeywordRuleRequest;
use App\Http\Requests\Chatbot\ChatbotMenuRequest;
use App\Models\ChatbotKeywordRule;
use App\Models\ChatbotMenu;
use App\Models\ChatbotMenuOption;
use App\Models\ChatbotSession;
use App\Models\Connection;
use App\Models\Department;
use App\Models\Message;
use App\Models\User;
use App\Services\Automation\AutomaticMessageKind;
use App\Services\Chatbot\ChatbotText;
use App\Support\PhoneNumber;
use App\Support\SaveToast;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * «Chatbot» (Administración): the welcome menu of each connection and the
 * keyword replies. Everything the chatbot does at runtime lives in
 * App\Services\Chatbot and App\Actions\Chatbot; this controller only shows
 * and saves the configuration.
 */
class ChatbotController extends Controller
{
    /** Days of the activity figures shown on the page. */
    public const ACTIVITY_DAYS = 7;

    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', ChatbotKeywordRule::class);

        /** @var User $actor */
        $actor = $request->user();
        $since = now()->subDays(self::ACTIVITY_DAYS);

        $connections = Connection::query()
            ->notDeleted()
            ->with(['departments:id', 'webChatWidget'])
            ->orderBy('name')
            ->orderBy('id')
            ->get();
        $menus = ChatbotMenu::query()
            ->whereIn('connection_id', $connections->modelKeys())
            ->with('allOptions')
            ->get()
            ->keyBy('connection_id');
        $sessions = ChatbotSession::query()
            ->where('created_at', '>=', $since)
            ->selectRaw('connection_id, status, count(*) as total')
            ->groupBy('connection_id', 'status')
            ->get();

        $rules = ChatbotKeywordRule::query()
            ->with(['connections:id', 'departments:id', 'updatedBy:id,name'])
            ->orderBy('position')
            ->orderBy('id')
            ->get();
        $replies = Message::query()
            ->where('created_at', '>=', $since)
            ->where('metadata->automatic_kind', AutomaticMessageKind::KeywordReply->value)
            ->get(['id', 'metadata'])
            ->countBy(fn (Message $message): int => (int) ($message->metadata['chatbot_rule_id'] ?? 0));

        $usedDepartmentIds = $menus->flatMap(fn (ChatbotMenu $menu) => $menu->allOptions->pluck('department_id')->filter())
            ->merge($rules->flatMap(fn (ChatbotKeywordRule $rule) => $rule->departments->modelKeys()))
            ->unique()
            ->all();

        return Inertia::render('chatbot/Index', [
            'connections' => $connections->map(function (Connection $connection) use ($menus, $sessions): array {
                /** @var ChatbotMenu|null $menu */
                $menu = $menus->get($connection->id);
                $activity = $sessions->where('connection_id', $connection->id);
                $count = fn (ChatbotSessionStatus $status): int => (int) $activity->firstWhere('status', $status)?->getAttribute('total');

                return [
                    'id' => $connection->id,
                    'name' => $connection->name,
                    'channel' => ['value' => $connection->channel->value, 'label' => $connection->channel->label()],
                    'is_whatsapp_web' => $connection->provider === ConnectionProvider::WhatsappWeb,
                    'is_active' => $connection->is_active,
                    'phone' => $connection->display_phone_number ?? ($connection->web_session_phone === null ? null : PhoneNumber::format(PhoneNumber::normalize('+'.$connection->web_session_phone) ?? '+'.$connection->web_session_phone)),
                    'status' => $this->status($connection),
                    // Lifecycle messages live in the connection form («Mensajes
                    // automáticos»); the page only links to them.
                    'automatic_messages' => [
                        'welcome' => $connection->welcome_enabled && filled($connection->welcome_message),
                        'away' => $connection->away_enabled && filled($connection->away_message),
                        'farewell' => $connection->farewell_enabled && filled($connection->farewell_message),
                    ],
                    'department_ids' => $connection->departments->modelKeys(),
                    'menu' => [
                        'is_enabled' => $menu->is_enabled ?? false,
                        'greeting' => $menu->greeting ?? '',
                        'retry_message' => $menu->retry_message ?? '',
                        'navigation_hint' => $menu->navigation_hint ?? '',
                        'max_attempts' => $menu->max_attempts ?? 2,
                        'timeout_minutes' => $menu->timeout_minutes ?? ChatbotMenu::DEFAULT_TIMEOUT_MINUTES,
                        'options' => $menu === null ? [] : self::optionTree($menu->allOptions, null),
                        'updated_at' => $menu?->updated_at?->toIso8601String(),
                    ],
                    'activity' => [
                        'routed' => $count(ChatbotSessionStatus::Routed),
                        'answered' => $count(ChatbotSessionStatus::Answered),
                        'fallback' => $count(ChatbotSessionStatus::Fallback),
                        'awaiting' => $count(ChatbotSessionStatus::Awaiting),
                    ],
                    'can' => [
                        'update' => Gate::allows('configureChatbot', $connection),
                        'view' => Gate::allows('view', $connection),
                    ],
                ];
            })->values()->all(),
            'departments' => Department::query()
                ->where(fn ($query) => $query->active()->orWhereIn('id', $usedDepartmentIds))
                ->orderBy('name')
                ->get(['id', 'name', 'color', 'is_active', 'welcome_message', 'away_message'])
                ->map(fn (Department $department): array => [
                    'id' => $department->id,
                    'name' => $department->name,
                    'color' => $department->color->value,
                    'is_active' => $department->is_active,
                    'has_welcome' => trim((string) $department->welcome_message) !== '',
                ])->values()->all(),
            'rules' => $rules->map(fn (ChatbotKeywordRule $rule): array => [
                'id' => $rule->id,
                'name' => $rule->name,
                'keywords' => $rule->keywords,
                'reply_message' => $rule->reply_message,
                'is_active' => $rule->is_active,
                'throttle_minutes' => $rule->throttle_minutes,
                'connection_ids' => $rule->connections->modelKeys(),
                'department_ids' => $rule->departments->modelKeys(),
                'replies' => (int) $replies->get($rule->id, 0),
                'updated_at' => $rule->updated_at?->toIso8601String(),
                'updated_by' => $rule->updatedBy?->name,
            ])->values()->all(),
            'variables' => ChatbotText::CATALOG,
            'limits' => [
                'message' => ConnectionRequest::MESSAGE_MAX_LENGTH,
                'retry' => ChatbotMenuRequest::RETRY_MAX_LENGTH,
                'label' => ChatbotMenuRequest::LABEL_MAX_LENGTH,
                'options' => ChatbotMenuRequest::MAX_OPTIONS,
                'depth' => ChatbotMenuRequest::MAX_DEPTH,
                'navigation' => ChatbotMenuRequest::NAVIGATION_MAX_LENGTH,
                'timeout' => ChatbotMenu::MAX_TIMEOUT_MINUTES,
                'attempts' => ChatbotMenu::MAX_ATTEMPTS_LIMIT,
                'name' => ChatbotKeywordRuleRequest::NAME_MAX_LENGTH,
                'keywords' => ChatbotKeywordRuleRequest::MAX_KEYWORDS,
                'keyword' => ChatbotKeywordRuleRequest::KEYWORD_MAX_LENGTH,
                'throttle' => ChatbotKeywordRuleRequest::MAX_THROTTLE_MINUTES,
            ],
            'defaults' => [
                'retry_message' => ChatbotMenu::DEFAULT_RETRY_MESSAGE,
                'navigation_hint' => ChatbotMenu::DEFAULT_NAVIGATION_HINT,
                'throttle_minutes' => ChatbotKeywordRule::DEFAULT_THROTTLE_MINUTES,
            ],
            'activityDays' => self::ACTIVITY_DAYS,
            'can' => [
                'manage' => $actor->can('create', ChatbotKeywordRule::class),
            ],
        ]);
    }

    /**
     * One level of the menu (null: the main one) with its submenus inside.
     *
     * @param  Collection<int, ChatbotMenuOption>  $all
     * @return list<array<string, mixed>>
     */
    private static function optionTree(Collection $all, ?int $parentId): array
    {
        return array_values($all->filter(fn (ChatbotMenuOption $option): bool => $option->parent_id === $parentId)
            ->sortBy([['position', 'asc'], ['id', 'asc']])
            ->map(fn (ChatbotMenuOption $option): array => [
                'action' => $option->action->value,
                'department_id' => $option->department_id,
                'label' => $option->label ?? '',
                'reply_message' => $option->reply_message ?? '',
                'children' => self::optionTree($all, $option->id),
            ])->all());
    }

    /**
     * State of the connection as the picker shows it: the real session of a
     * QR phone while it is active, the derived health otherwise.
     *
     * @return array{value: string, label: string, description: string, tone: string}
     */
    private function status(Connection $connection): array
    {
        $health = $connection->health();
        $state = $connection->webSessionState();

        if ($state !== null && $health !== ConnectionHealth::Inactive) {
            return [
                'value' => $health->value,
                'label' => $state->label(),
                'description' => $state->description(),
                'tone' => match ($state->tone()) {
                    'info' => 'primary',
                    default => $state->tone(),
                },
            ];
        }

        return [
            'value' => $health->value,
            'label' => $health->label(),
            'description' => $health->description(),
            'tone' => $health->tone(),
        ];
    }

    public function updateMenu(ChatbotMenuRequest $request, Connection $connection, SaveChatbotMenu $saveMenu): RedirectResponse
    {
        /** @var User $actor */
        $actor = $request->user();
        $payload = $request->payload();
        $changed = $saveMenu->handle($connection, $payload, $actor);

        SaveToast::flash($changed, $payload['is_enabled'] ? "Menú de bienvenida de {$connection->name} guardado y activo." : "Menú de bienvenida de {$connection->name} guardado (desactivado).");

        return back();
    }

    public function storeRule(ChatbotKeywordRuleRequest $request, SaveChatbotKeywordRule $saveRule): RedirectResponse
    {
        /** @var User $actor */
        $actor = $request->user();
        $result = $saveRule->handle(null, $request->payload(), $actor);

        Inertia::flash('toast', ['type' => 'success', 'message' => "Palabra clave «{$result['rule']->name}» creada."]);

        return back();
    }

    public function updateRule(ChatbotKeywordRuleRequest $request, ChatbotKeywordRule $rule, SaveChatbotKeywordRule $saveRule): RedirectResponse
    {
        /** @var User $actor */
        $actor = $request->user();
        $result = $saveRule->handle($rule, $request->payload(), $actor);

        SaveToast::flash($result['changed'], 'Palabra clave actualizada.');

        return back();
    }

    public function activateRule(Request $request, ChatbotKeywordRule $rule, ManageChatbotKeywordRules $rules): RedirectResponse
    {
        Gate::authorize('update', $rule);

        $active = (bool) $request->validate(['is_active' => ['required', 'boolean']])['is_active'];

        /** @var User $actor */
        $actor = $request->user();
        $rules->setActive($rule, $active, $actor);

        Inertia::flash('toast', ['type' => 'success', 'message' => $active ? "«{$rule->name}» activada." : "«{$rule->name}» desactivada."]);

        return back();
    }

    public function reorderRules(Request $request, ManageChatbotKeywordRules $rules): RedirectResponse
    {
        Gate::authorize('create', ChatbotKeywordRule::class);

        $ids = $request->validate([
            'ids' => ['required', 'array', 'max:500'],
            'ids.*' => ['integer', 'distinct'],
        ])['ids'];

        /** @var User $actor */
        $actor = $request->user();
        $changed = $rules->reorder(array_values(array_map('intval', $ids)), $actor);

        SaveToast::flash($changed, 'Orden de las palabras clave guardado.');

        return back();
    }

    public function destroyRule(Request $request, ChatbotKeywordRule $rule, ManageChatbotKeywordRules $rules): RedirectResponse
    {
        Gate::authorize('delete', $rule);

        /** @var User $actor */
        $actor = $request->user();
        $rules->delete($rule, $actor);

        Inertia::flash('toast', ['type' => 'success', 'message' => "Palabra clave «{$rule->name}» eliminada."]);

        return back();
    }
}

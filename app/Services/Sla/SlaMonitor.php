<?php

namespace App\Services\Sla;

use App\Enums\Permission;
use App\Models\Connection;
use App\Models\Conversation;
use App\Models\ConversationCategory;
use App\Models\Department;
use App\Models\ResponseCycle;
use App\Models\User;
use App\Services\Attention\AttentionAudience;
use App\Services\BusinessHours;
use App\Services\Conversations\ResponseClock;
use App\Services\ServiceRules;
use App\Support\Personalization\ViewerAlias;
use App\Support\ProtectedAccounts;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\Rule;

/**
 * «Monitoreo de SLA» of «Seguimiento de atención»: the open response cycles
 * and the breach history, always within the viewer's reach (the cycles of
 * conversations they may see, Conversation::visibleTo()).
 *
 * The four states are the ones the badge shows, decided against one "now":
 * «Vencida» (deadline passed), «Por vencer» (warning instant reached), «En
 * tiempo» and «Sin objetivo» (cycle without objective, not measured).
 *
 * Bounded: one aggregate query for the counters, one page of rows with
 * their relations eager loaded, one page of breaches.
 *
 * @phpstan-type MonitorFilters array{state: string|null, department: int|null, category: int|null, agent: int|null, connection: int|null, page: int, from: string, to: string, history_page: int}
 */
class SlaMonitor
{
    public const STATE_ON_TIME = 'on_time';

    public const STATE_WARNING = 'warning';

    public const STATE_BREACHED = 'breached';

    public const STATE_UNMEASURED = 'unmeasured';

    /** Open cycles listed per page. */
    public const PER_PAGE = 25;

    /** Breaches listed per page of the history. */
    public const HISTORY_PER_PAGE = 20;

    /** Longest range of the breach history, in days. */
    public const HISTORY_MAX_DAYS = 92;

    /** Days the history covers when no range is chosen. */
    public const HISTORY_DEFAULT_DAYS = 7;

    public function __construct(
        private AttentionAudience $audience,
        private ProtectedAccounts $protectedAccounts,
        private ResponseClock $clock,
        private BusinessHours $hours,
        private ServiceRules $rules,
    ) {}

    /**
     * Validation rules of the query string of the monitor.
     *
     * @return array<string, list<mixed>>
     */
    public static function rules(): array
    {
        return [
            'tab' => ['nullable', 'string', Rule::in(['attention', 'sla'])],
            'sla_state' => ['nullable', 'string', Rule::in([self::STATE_ON_TIME, self::STATE_WARNING, self::STATE_BREACHED, self::STATE_UNMEASURED])],
            'sla_department' => ['nullable', 'integer', 'min:1'],
            'sla_category' => ['nullable', 'integer', 'min:1'],
            'sla_agent' => ['nullable', 'integer', 'min:1'],
            'sla_connection' => ['nullable', 'integer', 'min:1'],
            'sla_page' => ['nullable', 'integer', 'min:1', 'max:100000'],
            'sla_from' => ['nullable', 'date_format:Y-m-d'],
            'sla_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:sla_from'],
            'sla_history_page' => ['nullable', 'integer', 'min:1', 'max:100000'],
        ];
    }

    /**
     * Normalized filters from validated input. The history range is read in
     * the platform time zone, defaults to the last days and is capped.
     *
     * @param  array<string, mixed>  $validated
     * @return MonitorFilters
     */
    public function filters(array $validated, ?CarbonImmutable $now = null): array
    {
        $timezone = $this->hours->timezone();
        $today = ($now ?? CarbonImmutable::now())->setTimezone($timezone)->startOfDay();
        $date = fn (mixed $value): ?CarbonImmutable => is_string($value) && $value !== '' ? CarbonImmutable::createFromFormat('!Y-m-d', $value, $timezone) : null;

        $to = $date($validated['sla_to'] ?? null) ?? $today;
        $from = $date($validated['sla_from'] ?? null) ?? $to->subDays(self::HISTORY_DEFAULT_DAYS - 1);

        if ($from->greaterThan($to)) {
            $from = $to;
        }

        if ($from->diffInDays($to) > self::HISTORY_MAX_DAYS) {
            $from = $to->subDays(self::HISTORY_MAX_DAYS);
        }

        $int = fn (string $key): ?int => isset($validated[$key]) ? (int) $validated[$key] : null;

        return [
            'state' => isset($validated['sla_state']) ? (string) $validated['sla_state'] : null,
            'department' => $int('sla_department'),
            'category' => $int('sla_category'),
            'agent' => $int('sla_agent'),
            'connection' => $int('sla_connection'),
            'page' => $int('sla_page') ?? 1,
            'from' => $from->format('Y-m-d'),
            'to' => $to->format('Y-m-d'),
            'history_page' => $int('sla_history_page') ?? 1,
        ];
    }

    /**
     * The open cycles within the viewer's reach: counters per state and the
     * requested page, most urgent first.
     *
     * @param  MonitorFilters  $filters
     * @return array{summary: array{on_time: int, warning: int, breached: int, unmeasured: int}, rows: list<array<string, mixed>>, page: int, per_page: int, total: int, last_page: int, configured: bool}
     */
    public function open(User $viewer, array $filters, ?CarbonImmutable $now = null): array
    {
        $now = ($now ?? CarbonImmutable::now())->startOfSecond();
        $base = fn (): Builder => $this->dimensions($this->visible($viewer)->where('response_cycles.active', 1), $filters, 'assigned_user_id');

        $counts = $base()->toBase()
            ->selectRaw('SUM(CASE WHEN response_cycles.target_seconds IS NULL THEN 1 ELSE 0 END) as unmeasured')
            ->selectRaw('SUM(CASE WHEN response_cycles.due_at < ? THEN 1 ELSE 0 END) as breached', [$now])
            ->selectRaw('SUM(CASE WHEN response_cycles.due_at >= ? AND response_cycles.warn_at <= ? THEN 1 ELSE 0 END) as warning', [$now, $now])
            ->selectRaw('SUM(CASE WHEN response_cycles.warn_at > ? THEN 1 ELSE 0 END) as on_time', [$now])
            ->first();

        $summary = [
            self::STATE_ON_TIME => (int) ($counts->on_time ?? 0),
            self::STATE_WARNING => (int) ($counts->warning ?? 0),
            self::STATE_BREACHED => (int) ($counts->breached ?? 0),
            self::STATE_UNMEASURED => (int) ($counts->unmeasured ?? 0),
        ];

        $total = $filters['state'] === null ? array_sum($summary) : $summary[$filters['state']];
        $lastPage = max(1, (int) ceil($total / self::PER_PAGE));
        $page = min($filters['page'], $lastPage);

        /** @var Collection<int, ResponseCycle> $cycles */
        $cycles = $this->state($base(), $filters['state'], $now)
            ->with($this->relations())
            // Measured cycles first, by deadline (the longest overdue on top);
            // then the unmeasured ones, the longest waiting first.
            ->orderByRaw('response_cycles.due_at IS NULL')
            ->orderBy('response_cycles.due_at')
            ->orderBy('response_cycles.started_at')
            ->orderBy('response_cycles.id')
            ->offset(($page - 1) * self::PER_PAGE)
            ->limit(self::PER_PAGE)
            ->get();

        $this->loadAliases($cycles, $viewer);

        return [
            'summary' => $summary,
            'rows' => array_values($cycles->map(fn (ResponseCycle $cycle): array => $this->presentOpen($cycle, $viewer))->all()),
            'page' => $page,
            'per_page' => self::PER_PAGE,
            'total' => $total,
            'last_page' => $lastPage,
            'configured' => $this->hasObjectives(),
        ];
    }

    /**
     * Breaches whose deadline passed inside the range (platform time zone),
     * newest first, with what happened afterwards.
     *
     * @param  MonitorFilters  $filters
     * @return array{rows: list<array<string, mixed>>, page: int, per_page: int, total: int, last_page: int, from: string, to: string}
     */
    public function breaches(User $viewer, array $filters, ?CarbonImmutable $now = null): array
    {
        $now = ($now ?? CarbonImmutable::now())->startOfSecond();
        $timezone = $this->hours->timezone();
        $from = CarbonImmutable::createFromFormat('!Y-m-d', $filters['from'], $timezone)->utc();
        $until = CarbonImmutable::createFromFormat('!Y-m-d', $filters['to'], $timezone)->addDay()->utc();

        $query = $this->dimensions($this->visible($viewer), $filters, 'breach_user_id')
            ->where('response_cycles.breached_at', '>=', $from)
            ->where('response_cycles.breached_at', '<', $until);

        $total = (clone $query)->count();
        $lastPage = max(1, (int) ceil($total / self::HISTORY_PER_PAGE));
        $page = min($filters['history_page'], $lastPage);

        /** @var Collection<int, ResponseCycle> $cycles */
        $cycles = $query
            ->with([...$this->relations(), 'responder:id,name', 'department'])
            ->orderByDesc('response_cycles.breached_at')
            ->orderByDesc('response_cycles.id')
            ->offset(($page - 1) * self::HISTORY_PER_PAGE)
            ->limit(self::HISTORY_PER_PAGE)
            ->get();

        $this->loadAliases($cycles, $viewer);

        $responsible = User::query()
            ->whereKey($cycles->pluck('breach_user_id')->filter()->unique()->values()->all())
            ->get(['id', 'name'])
            ->keyBy('id');

        return [
            'rows' => array_values($cycles->map(function (ResponseCycle $cycle) use ($viewer, $responsible, $now): array {
                $conversation = $cycle->conversation;
                $breachUser = $cycle->breach_user_id === null ? null : $responsible->get($cycle->breach_user_id);

                return [
                    'id' => $cycle->id,
                    'conversation_id' => $cycle->conversation_id,
                    'contact' => $this->contactName($conversation, $viewer),
                    'kind' => $cycle->kind->value,
                    'kind_label' => $cycle->kind->label(),
                    'target_seconds' => $cycle->target_seconds,
                    'business_hours_only' => $cycle->business_hours_only,
                    'started_at' => $cycle->started_at->toIso8601String(),
                    'due_at' => $cycle->due_at?->toIso8601String(),
                    'breached_at' => $cycle->breached_at?->toIso8601String(),
                    // Null: it was waiting in a queue, nobody was responsible yet.
                    'responsible' => $breachUser === null ? null : $this->protectedAccounts->nameFor($breachUser, $viewer),
                    'department' => $this->departmentOf($cycle),
                    'category' => $this->categoryOf($conversation),
                    // Still open: time over the deadline so far.
                    'exceeded_seconds' => $cycle->isActive()
                        ? $this->clock->exceededSeconds($cycle, $now, $cycle->business_hours_only ? $cycle->department : null)
                        : $cycle->exceeded_seconds,
                    'outcome' => $cycle->isActive() ? 'open' : $cycle->outcome,
                    'responded_at' => $cycle->responded_at?->toIso8601String(),
                    'responder' => $cycle->responder === null ? null : $this->protectedAccounts->nameFor($cycle->responder, $viewer),
                    'ended_at' => $cycle->ended_at?->toIso8601String(),
                    'url' => route('conversations.show', $cycle->conversation_id),
                ];
            })->all()),
            'page' => $page,
            'per_page' => self::HISTORY_PER_PAGE,
            'total' => $total,
            'last_page' => $lastPage,
            'from' => $filters['from'],
            'to' => $filters['to'],
        ];
    }

    /**
     * Filter options, limited to what the viewer reaches.
     *
     * @return array{departments: list<array{id: int, name: string}>, categories: list<array{id: int, name: string, color: string}>, agents: list<array{id: int, name: string}>, connections: list<array{id: int, name: string}>, states: list<array{value: string, label: string}>}
     */
    public function options(User $viewer): array
    {
        $agents = $this->audience->agents($viewer)->orderBy('users.name')->get(['users.id', 'users.name']);

        // The viewer may be responsible for requests too (the audience never lists them).
        if ($viewer->holdsPermission(Permission::ReplyConversations)) {
            $agents = $agents->push($viewer)->unique('id')->sortBy('name')->values();
        }

        return [
            'departments' => array_values(Department::query()->visibleTo($viewer)->notDeleted()->orderBy('name')->get(['id', 'name'])
                ->map(fn (Department $department): array => ['id' => $department->id, 'name' => $department->name])->all()),
            'categories' => ConversationCategory::options(),
            'agents' => array_values($agents->map(fn (User $agent): array => ['id' => $agent->id, 'name' => $agent->name])->all()),
            'connections' => array_values(Connection::query()->visibleTo($viewer)->hidingDeletedFrom($viewer)->orderBy('name')->get(['id', 'name'])
                ->map(fn (Connection $connection): array => ['id' => $connection->id, 'name' => $connection->name])->all()),
            'states' => [
                ['value' => self::STATE_BREACHED, 'label' => 'Vencida'],
                ['value' => self::STATE_WARNING, 'label' => 'Por vencer'],
                ['value' => self::STATE_ON_TIME, 'label' => 'En tiempo'],
                ['value' => self::STATE_UNMEASURED, 'label' => 'Sin objetivo'],
            ],
        ];
    }

    /**
     * Whether any response objective exists at all (platform, a department
     * or a category), to explain an empty monitor.
     */
    public function hasObjectives(): bool
    {
        $platform = $this->rules->forIds(null, null);

        if ($platform->firstResponseSeconds() !== null || $platform->responseSeconds() !== null) {
            return true;
        }

        $sources = [
            Department::query()->whereNotNull('service_rules')->get(['id', 'service_rules']),
            ConversationCategory::query()->active()->whereNotNull('service_rules')->get(['id', 'service_rules']),
        ];

        foreach ($sources as $models) {
            foreach ($models as $model) {
                $stored = $model->service_rules;

                if (is_array($stored) && (isset($stored['sla.first_response_minutes']) || isset($stored['sla.response_minutes']))) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Cycles of the live conversations the viewer may see.
     *
     * @return Builder<ResponseCycle>
     */
    private function visible(User $viewer): Builder
    {
        return ResponseCycle::query()->whereIn(
            'response_cycles.conversation_id',
            Conversation::query()->visibleTo($viewer)->live()->notHistoryOnly()->select('conversations.id'),
        );
    }

    /**
     * @param  Builder<ResponseCycle>  $query
     * @param  MonitorFilters  $filters
     * @return Builder<ResponseCycle>
     */
    private function dimensions(Builder $query, array $filters, string $agentColumn): Builder
    {
        return $query
            ->when($filters['department'] !== null, fn (Builder $cycles) => $cycles->where('response_cycles.department_id', $filters['department']))
            ->when($filters['category'] !== null, fn (Builder $cycles) => $cycles->where('response_cycles.category_id', $filters['category']))
            ->when($filters['agent'] !== null, fn (Builder $cycles) => $cycles->where("response_cycles.{$agentColumn}", $filters['agent']))
            ->when($filters['connection'] !== null, fn (Builder $cycles) => $cycles->where('response_cycles.connection_id', $filters['connection']));
    }

    /**
     * @param  Builder<ResponseCycle>  $query
     * @return Builder<ResponseCycle>
     */
    private function state(Builder $query, ?string $state, CarbonImmutable $now): Builder
    {
        return match ($state) {
            self::STATE_UNMEASURED => $query->whereNull('response_cycles.target_seconds'),
            self::STATE_BREACHED => $query->where('response_cycles.due_at', '<', $now),
            self::STATE_WARNING => $query->where('response_cycles.due_at', '>=', $now)->where('response_cycles.warn_at', '<=', $now),
            self::STATE_ON_TIME => $query->where('response_cycles.warn_at', '>', $now),
            default => $query,
        };
    }

    /**
     * @return list<string>
     */
    private function relations(): array
    {
        return [
            'conversation:id,contact_id,assigned_user_id,department_id,category_id,connection_id,status',
            'conversation.contact:id,name,profile_name,phone_e164',
            'conversation.assignee:id,name',
            'conversation.department:id,name,color,is_active',
            'conversation.category:id,name,color',
            'conversation.channelConnection:id,name,channel',
        ];
    }

    /**
     * @param  Collection<int, ResponseCycle>  $cycles
     */
    private function loadAliases(Collection $cycles, User $viewer): void
    {
        /** @var Collection<int, Conversation> $conversations */
        $conversations = new Collection($cycles->map(fn (ResponseCycle $cycle): ?Conversation => $cycle->conversation)->filter()->values()->all());

        ViewerAlias::load($conversations, $viewer);
    }

    /**
     * @return array<string, mixed>
     */
    private function presentOpen(ResponseCycle $cycle, User $viewer): array
    {
        $conversation = $cycle->conversation;

        return [
            'id' => $cycle->id,
            'conversation_id' => $cycle->conversation_id,
            'contact' => $this->contactName($conversation, $viewer),
            'agent' => $conversation?->assignee === null ? null : [
                'id' => $this->protectedAccounts->idFor($conversation->assignee, $viewer),
                'name' => $this->protectedAccounts->nameFor($conversation->assignee, $viewer),
            ],
            'department' => $this->departmentOf($cycle),
            'category' => $this->categoryOf($conversation),
            'connection' => $conversation?->channelConnection === null ? null : [
                'id' => $conversation->channelConnection->id,
                'name' => $conversation->channelConnection->name,
                'channel_label' => $conversation->channelConnection->channel->label(),
            ],
            'status' => $conversation?->status->value,
            'status_label' => $conversation?->status->label(),
            'sla' => $cycle->target_seconds === null ? null : SlaSnapshot::present($cycle),
            'kind_label' => $cycle->kind->label(),
            'started_at' => $cycle->started_at->toIso8601String(),
            'url' => route('conversations.show', $cycle->conversation_id),
        ];
    }

    private function contactName(?Conversation $conversation, User $viewer): string
    {
        return $conversation?->contact === null
            ? 'Cliente'
            : ViewerAlias::displayName($conversation->contact, ViewerAlias::of($conversation->contact, $viewer));
    }

    /**
     * @return array{id: int, name: string, color: string}|null
     */
    private function departmentOf(ResponseCycle $cycle): ?array
    {
        $department = $cycle->relationLoaded('department') ? $cycle->department : $cycle->conversation?->department;

        return $department === null ? null : ['id' => $department->id, 'name' => $department->name, 'color' => $department->color->value];
    }

    /**
     * @return array{id: int, name: string, color: string}|null
     */
    private function categoryOf(?Conversation $conversation): ?array
    {
        $category = $conversation?->category;

        return $category === null ? null : ['id' => $category->id, 'name' => $category->name, 'color' => $category->color->value];
    }
}

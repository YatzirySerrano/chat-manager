<?php

namespace App\Services;

use App\Models\Conversation;
use App\Models\ConversationCategory;
use App\Models\Department;
use Illuminate\Container\Attributes\Scoped;
use Illuminate\Support\Arr;
use InvalidArgumentException;

/**
 * Single source of truth of the service rules and their hierarchy:
 * category > department > platform.
 *
 * - Platform values live in PlatformSettings (groups `sla`, `intake`,
 *   `queue`), always defined.
 * - A department or a category overrides a rule with its `service_rules`
 *   column: a flat map of dotted rule key → value where an absent key (or
 *   null) inherits the next level. A key that may not be overridden at a
 *   level is rejected by validation and ignored here. The overrides of a
 *   logically deleted category do not apply.
 *
 * Rules are read when a decision is taken: a change applies to the
 * decisions taken from then on and never rewrites what was already recorded
 * (for example the deadline frozen in a response cycle).
 *
 * The instance is scoped (one per request or queued job) and memoizes what
 * it resolved; whoever changes rules or categories calls flush().
 *
 * @phpstan-type RuleValue int|bool|null
 * @phpstan-type RuleDefinition array{type: 'integer'|'boolean', nullable: bool, min: int|null, max: int|null, default: int|bool|null, levels: list<string>, label: string, unit: string|null}
 * @phpstan-type RuleDescription array{value: int|bool|null, source: string, inherited_value: int|bool|null, inherited_source: string|null, label: string, unit: string|null, min: int|null, max: int|null, type: string, nullable: bool, overridable: bool}
 * @phpstan-type AlertSettings array{agent_warning: bool, agent_breach: bool, supervisor_warning: bool, supervisor_breach: bool, reminder_minutes: int|null, reminder_limit: int}
 */
#[Scoped]
final class ServiceRules
{
    public const LEVEL_PLATFORM = 'platform';

    public const LEVEL_DEPARTMENT = 'department';

    public const LEVEL_CATEGORY = 'category';

    /**
     * Definition of every rule. `default` is the platform default until an
     * administrator saves a value; `levels` are the levels that may
     * override the platform value. Minutes are whole minutes; a nullable
     * rule set to null at the platform means "no objective".
     *
     * @var array<string, RuleDefinition>
     */
    public const KEYS = [
        'sla.first_response_minutes' => [
            'type' => 'integer', 'nullable' => true, 'min' => 1, 'max' => 43200, 'default' => null,
            'levels' => [self::LEVEL_DEPARTMENT, self::LEVEL_CATEGORY],
            'label' => 'Tiempo máximo para la primera respuesta', 'unit' => 'minutos',
        ],
        'sla.response_minutes' => [
            'type' => 'integer', 'nullable' => true, 'min' => 1, 'max' => 43200, 'default' => null,
            'levels' => [self::LEVEL_DEPARTMENT, self::LEVEL_CATEGORY],
            'label' => 'Tiempo máximo para responder cada mensaje posterior', 'unit' => 'minutos',
        ],
        'sla.resolution_minutes' => [
            'type' => 'integer', 'nullable' => true, 'min' => 1, 'max' => 525600, 'default' => null,
            'levels' => [self::LEVEL_DEPARTMENT, self::LEVEL_CATEGORY],
            'label' => 'Tiempo máximo de resolución', 'unit' => 'minutos',
        ],
        'sla.warning_percent' => [
            'type' => 'integer', 'nullable' => false, 'min' => 50, 'max' => 95, 'default' => 80,
            'levels' => [self::LEVEL_DEPARTMENT, self::LEVEL_CATEGORY],
            'label' => 'Aviso de «próxima a vencer»', 'unit' => '% del plazo',
        ],
        'sla.business_hours_only' => [
            'type' => 'boolean', 'nullable' => false, 'min' => null, 'max' => null, 'default' => true,
            'levels' => [self::LEVEL_DEPARTMENT],
            'label' => 'Contar solo el horario de atención', 'unit' => null,
        ],
        'intake.confirmation_required' => [
            'type' => 'boolean', 'nullable' => false, 'min' => null, 'max' => null, 'default' => false,
            'levels' => [self::LEVEL_DEPARTMENT, self::LEVEL_CATEGORY],
            'label' => 'Exigir confirmación de «Solicitud completa»', 'unit' => null,
        ],
        'queue.min_wait_minutes' => [
            'type' => 'integer', 'nullable' => false, 'min' => 0, 'max' => 1440, 'default' => 0,
            'levels' => [self::LEVEL_DEPARTMENT, self::LEVEL_CATEGORY],
            'label' => 'Tiempo mínimo de espera en la cola', 'unit' => 'minutos',
        ],
        'queue.chronological_order' => [
            'type' => 'boolean', 'nullable' => false, 'min' => null, 'max' => null, 'default' => false,
            'levels' => [self::LEVEL_DEPARTMENT],
            'label' => 'Atender en orden de llegada', 'unit' => null,
        ],
    ];

    /**
     * Platform-only settings of the automatic SLA alerts (PlatformSettings
     * group `sla_alerts`). They are not part of the hierarchy.
     *
     * @var array<string, RuleDefinition>
     */
    public const ALERT_KEYS = [
        'sla_alerts.agent_warning' => [
            'type' => 'boolean', 'nullable' => false, 'min' => null, 'max' => null, 'default' => true, 'levels' => [],
            'label' => 'Avisar al agente cuando una solicitud está próxima a vencer', 'unit' => null,
        ],
        'sla_alerts.agent_breach' => [
            'type' => 'boolean', 'nullable' => false, 'min' => null, 'max' => null, 'default' => true, 'levels' => [],
            'label' => 'Avisar al agente cuando una solicitud vence', 'unit' => null,
        ],
        'sla_alerts.supervisor_warning' => [
            'type' => 'boolean', 'nullable' => false, 'min' => null, 'max' => null, 'default' => false, 'levels' => [],
            'label' => 'Avisar a supervisión cuando una solicitud está próxima a vencer', 'unit' => null,
        ],
        'sla_alerts.supervisor_breach' => [
            'type' => 'boolean', 'nullable' => false, 'min' => null, 'max' => null, 'default' => true, 'levels' => [],
            'label' => 'Avisar a supervisión cuando una solicitud vence', 'unit' => null,
        ],
        'sla_alerts.reminder_minutes' => [
            'type' => 'integer', 'nullable' => true, 'min' => 5, 'max' => 1440, 'default' => null, 'levels' => [],
            'label' => 'Frecuencia de los recordatorios de solicitudes vencidas', 'unit' => 'minutos',
        ],
        'sla_alerts.reminder_limit' => [
            'type' => 'integer', 'nullable' => false, 'min' => 1, 'max' => 10, 'default' => 3, 'levels' => [],
            'label' => 'Máximo de recordatorios por solicitud', 'unit' => null,
        ],
    ];

    /**
     * @var array<string, RuleValue>|null
     */
    private ?array $platform = null;

    /**
     * Overrides of every department that has any, by department id.
     *
     * @var array<int, array<string, int|bool>>|null
     */
    private ?array $departmentOverrides = null;

    /**
     * Overrides of every active category that has any, by category id.
     *
     * @var array<int, array<string, int|bool>>|null
     */
    private ?array $categoryOverrides = null;

    /**
     * @var array<string, ResolvedRules>
     */
    private array $resolved = [];

    public function __construct(private PlatformSettings $settings) {}

    /**
     * The rules in force for the department and category given (either may
     * be null). The models are read as they are, so unsaved or freshly
     * saved overrides are honored.
     */
    public function resolve(?Department $department, ?ConversationCategory $category): ResolvedRules
    {
        return $this->combine(
            $department === null ? [] : $this->overridesOf(self::LEVEL_DEPARTMENT, $department->service_rules),
            $category === null || $category->isDeleted() ? [] : $this->overridesOf(self::LEVEL_CATEGORY, $category->service_rules),
        );
    }

    /**
     * The rules in force for the conversation, from its department_id and
     * category_id. Memoized by that pair and resolved from the overrides of
     * every department and category loaded once (two small queries for the
     * whole request), so calling it for every row of a page never runs a
     * query per row and needs no eager loading.
     */
    public function forConversation(Conversation $conversation): ResolvedRules
    {
        return $this->forIds($conversation->department_id, $conversation->category_id);
    }

    /**
     * The rules in force for a department id and a category id.
     */
    public function forIds(?int $departmentId, ?int $categoryId): ResolvedRules
    {
        $memo = ($departmentId ?? 0).':'.($categoryId ?? 0);

        if (! isset($this->resolved[$memo])) {
            $this->loadOverrides();

            $this->resolved[$memo] = $this->combine(
                $departmentId === null ? [] : ($this->departmentOverrides[$departmentId] ?? []),
                $categoryId === null ? [] : ($this->categoryOverrides[$categoryId] ?? []),
            );
        }

        return $this->resolved[$memo];
    }

    /**
     * Ids of the active categories that override any rule: the only ones
     * whose requests may be ruled differently from their department.
     *
     * @return list<int>
     */
    public function overridingCategoryIds(): array
    {
        $this->loadOverrides();

        return array_keys(array_filter($this->categoryOverrides ?? []));
    }

    /**
     * Warm the overrides before resolving a page of conversations. Optional:
     * forConversation() loads them on first use; this only makes the moment
     * of the two queries explicit.
     *
     * @param  iterable<Conversation>  $conversations
     */
    public function preload(iterable $conversations): void
    {
        $this->loadOverrides();

        foreach ($conversations as $conversation) {
            $this->forConversation($conversation);
        }
    }

    /**
     * Settings of the automatic SLA alerts (platform only).
     *
     * @return AlertSettings
     */
    public function alerts(): array
    {
        $alerts = [];

        foreach (self::ALERT_KEYS as $key => $definition) {
            $stored = $this->settings->get($key);
            $name = substr($key, strlen('sla_alerts.'));
            $alerts[$name] = $stored === null && $definition['nullable']
                ? null
                : ($this->sanitize($definition, $stored) ?? $definition['default']);
        }

        /** @var AlertSettings $alerts */
        return $alerts;
    }

    /**
     * Laravel validation rules of the keys a level may set. `$prefix` is
     * prepended to every dotted key (e.g. "rules." validates
     * `rules.sla.first_response_minutes`).
     *
     * - platform: every rule and every alert setting. A key left out keeps
     *   its value, so older forms stay valid.
     * - department / category: only the keys overridable at that level, all
     *   nullable (null or absent = inherit). The groups only accept those
     *   keys, so a key that may not be overridden there fails validation.
     *
     * @return array<string, list<string>>
     */
    public function validationRules(string $level, string $prefix = ''): array
    {
        $this->assertLevel($level);

        $rules = [];

        if ($level === self::LEVEL_PLATFORM) {
            foreach ([...self::KEYS, ...self::ALERT_KEYS] as $key => $definition) {
                $rules[$prefix.$key] = [
                    ...($definition['nullable'] ? ['nullable'] : ($definition['type'] === 'boolean' ? ['sometimes'] : ['sometimes', 'required'])),
                    ...$this->typeRules($definition),
                ];
            }

            return $rules;
        }

        $groups = [];

        foreach (self::KEYS as $key => $definition) {
            if (! in_array($level, $definition['levels'], true)) {
                continue;
            }

            [$group, $name] = explode('.', $key, 2);
            $groups[$group][] = $name;
            $rules[$prefix.$key] = ['nullable', ...$this->typeRules($definition)];
        }

        foreach ($groups as $group => $names) {
            $rules[$prefix.$group] = ['nullable', 'array:'.implode(',', $names)];
        }

        if ($prefix !== '') {
            $rules[rtrim($prefix, '.')] = ['nullable', 'array:'.implode(',', array_keys($groups))];
        }

        return $rules;
    }

    /**
     * Names of the fields for validation messages, matching validationRules().
     *
     * @return array<string, string>
     */
    public function validationAttributes(string $level, string $prefix = ''): array
    {
        $this->assertLevel($level);

        $attributes = [];

        foreach ($level === self::LEVEL_PLATFORM ? [...self::KEYS, ...self::ALERT_KEYS] : self::KEYS as $key => $definition) {
            $attributes[$prefix.$key] = mb_strtolower($definition['label']);
        }

        return $attributes;
    }

    /**
     * The overrides a level may store, from validated input: a flat map of
     * dotted key → value with only the keys overridable at the level that
     * carry a value. Accepts the nested shape of a form
     * (`['sla' => ['first_response_minutes' => 15]]`) or the flat one.
     *
     * @param  array<array-key, mixed>  $input
     * @return array<string, int|bool>
     */
    public function overridesFrom(string $level, array $input): array
    {
        $this->assertLevel($level);

        $overrides = [];

        foreach (self::KEYS as $key => $definition) {
            if (! in_array($level, $definition['levels'], true)) {
                continue;
            }

            $raw = array_key_exists($key, $input) ? $input[$key] : Arr::get($input, $key);

            if ($definition['type'] === 'boolean') {
                $raw = is_bool($raw) || $raw === null || $raw === '' ? $raw : filter_var($raw, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
            } elseif (is_string($raw) && preg_match('/^\d+$/', $raw) === 1) {
                $raw = (int) $raw;
            }

            $value = $this->sanitize($definition, $raw);

            if ($value !== null) {
                $overrides[$key] = $value;
            }
        }

        return $overrides;
    }

    /**
     * Every rule as a screen needs it, for the level being edited: the
     * platform when both are null, the department when only it is given,
     * otherwise the category (inheriting from the department when given,
     * else from the platform).
     *
     * `value`/`source` are the effective value at that level;
     * `inherited_value`/`inherited_source` are what applies when the level
     * does not override the rule (null at the platform level). `overridable`
     * tells whether the level may set the rule at all.
     *
     * @return array<string, RuleDescription>
     */
    public function describe(?Department $department, ?ConversationCategory $category): array
    {
        $level = match (true) {
            $category !== null => self::LEVEL_CATEGORY,
            $department !== null => self::LEVEL_DEPARTMENT,
            default => self::LEVEL_PLATFORM,
        };

        $platform = $this->platformValues();
        $departmentOverrides = $department === null ? [] : $this->overridesOf(self::LEVEL_DEPARTMENT, $department->service_rules);
        $categoryOverrides = $category === null ? [] : $this->overridesOf(self::LEVEL_CATEGORY, $category->service_rules);

        $rules = [];

        foreach (self::KEYS as $key => $definition) {
            $inherited = ['value' => $platform[$key], 'source' => self::LEVEL_PLATFORM];

            if ($level === self::LEVEL_CATEGORY && array_key_exists($key, $departmentOverrides)) {
                $inherited = ['value' => $departmentOverrides[$key], 'source' => self::LEVEL_DEPARTMENT];
            }

            $own = match ($level) {
                self::LEVEL_CATEGORY => $categoryOverrides,
                self::LEVEL_DEPARTMENT => $departmentOverrides,
                default => [],
            };

            $overridden = array_key_exists($key, $own);

            $rules[$key] = [
                'value' => $overridden ? $own[$key] : $inherited['value'],
                'source' => $overridden ? $level : $inherited['source'],
                'inherited_value' => $level === self::LEVEL_PLATFORM ? null : $inherited['value'],
                'inherited_source' => $level === self::LEVEL_PLATFORM ? null : $inherited['source'],
                'label' => $definition['label'],
                'unit' => $definition['unit'],
                'min' => $definition['min'],
                'max' => $definition['max'],
                'type' => $definition['type'],
                'nullable' => $definition['nullable'],
                'overridable' => $level === self::LEVEL_PLATFORM || in_array($level, $definition['levels'], true),
            ];
        }

        return $rules;
    }

    /**
     * Forget everything memoized. Called whenever platform rules, the
     * overrides of a department or a category, or the catalog change; the
     * next decision reads the new values.
     */
    public function flush(): void
    {
        $this->platform = null;
        $this->departmentOverrides = null;
        $this->categoryOverrides = null;
        $this->resolved = [];
    }

    /**
     * @param  array<string, int|bool>  $departmentOverrides
     * @param  array<string, int|bool>  $categoryOverrides
     */
    private function combine(array $departmentOverrides, array $categoryOverrides): ResolvedRules
    {
        $values = $this->platformValues();
        $sources = array_fill_keys(array_keys($values), self::LEVEL_PLATFORM);

        foreach ([self::LEVEL_DEPARTMENT => $departmentOverrides, self::LEVEL_CATEGORY => $categoryOverrides] as $level => $overrides) {
            foreach ($overrides as $key => $value) {
                $values[$key] = $value;
                $sources[$key] = $level;
            }
        }

        return new ResolvedRules($values, $sources);
    }

    /**
     * Platform value of every rule: the stored one when valid, else the default.
     *
     * @return array<string, RuleValue>
     */
    private function platformValues(): array
    {
        if ($this->platform !== null) {
            return $this->platform;
        }

        $stored = $this->settings->all();
        $values = [];

        foreach (self::KEYS as $key => $definition) {
            $value = Arr::get($stored, $key);

            $values[$key] = $value === null && $definition['nullable']
                ? null
                : ($this->sanitize($definition, $value) ?? $definition['default']);
        }

        return $this->platform = $values;
    }

    /**
     * Before/after of two override maps, for the audit log: one entry per
     * key whose value changed, null meaning "inherits".
     *
     * @param  array<string, int|bool>  $before
     * @param  array<string, int|bool>  $after
     * @return array<string, array{old: int|bool|null, new: int|bool|null}>
     */
    public function diff(array $before, array $after): array
    {
        $changes = [];

        foreach (array_keys(self::KEYS) as $key) {
            $old = $before[$key] ?? null;
            $new = $after[$key] ?? null;

            if ($old !== $new) {
                $changes[$key] = ['old' => $old, 'new' => $new];
            }
        }

        return $changes;
    }

    /**
     * The valid overrides of a stored map for a level; anything else
     * (unknown keys, keys not overridable there, values of the wrong type
     * or out of range, nulls) is ignored and therefore inherits.
     *
     * @return array<string, int|bool>
     */
    public function overridesOf(string $level, mixed $stored): array
    {
        if (! is_array($stored)) {
            return [];
        }

        $overrides = [];

        foreach (self::KEYS as $key => $definition) {
            if (! in_array($level, $definition['levels'], true) || ! array_key_exists($key, $stored)) {
                continue;
            }

            $value = $this->sanitize($definition, $stored[$key]);

            if ($value !== null) {
                $overrides[$key] = $value;
            }
        }

        return $overrides;
    }

    private function loadOverrides(): void
    {
        if ($this->departmentOverrides !== null && $this->categoryOverrides !== null) {
            return;
        }

        $this->departmentOverrides = [];
        $this->categoryOverrides = [];

        foreach (Department::query()->whereNotNull('service_rules')->get(['id', 'service_rules']) as $department) {
            $this->departmentOverrides[$department->id] = $this->overridesOf(self::LEVEL_DEPARTMENT, $department->service_rules);
        }

        foreach (ConversationCategory::query()->active()->whereNotNull('service_rules')->get(['id', 'service_rules']) as $category) {
            $this->categoryOverrides[$category->id] = $this->overridesOf(self::LEVEL_CATEGORY, $category->service_rules);
        }
    }

    /**
     * The value when it has the type and range of the rule, otherwise null.
     *
     * @param  RuleDefinition  $definition
     */
    private function sanitize(array $definition, mixed $value): int|bool|null
    {
        if ($definition['type'] === 'boolean') {
            return is_bool($value) ? $value : null;
        }

        if (! is_int($value) || $value < $definition['min'] || $value > $definition['max']) {
            return null;
        }

        return $value;
    }

    /**
     * @param  RuleDefinition  $definition
     * @return list<string>
     */
    private function typeRules(array $definition): array
    {
        return $definition['type'] === 'boolean'
            ? ['boolean']
            : ['integer', "min:{$definition['min']}", "max:{$definition['max']}"];
    }

    private function assertLevel(string $level): void
    {
        if (! in_array($level, [self::LEVEL_PLATFORM, self::LEVEL_DEPARTMENT, self::LEVEL_CATEGORY], true)) {
            throw new InvalidArgumentException("Unknown service rule level [{$level}].");
        }
    }
}

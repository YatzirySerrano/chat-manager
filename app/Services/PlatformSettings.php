<?php

namespace App\Services;

use App\Enums\ThemePalette;
use App\Models\Setting;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;

/**
 * Typed access to the platform configuration stored in the `settings` table.
 *
 * @phpstan-type BusinessDay array{open: bool, start: string, end: string}
 * @phpstan-type Holiday array{date: string, label: string, recurring: bool}
 * @phpstan-type SettingsShape array{
 *     general: array{platform_name: string, timezone: string},
 *     appearance: array{palette: string},
 *     business_hours: array{enabled: bool, days: array<string, BusinessDay>, holidays: list<Holiday>},
 *     operations: array{default_department_id: int|null, max_open_conversations_per_agent: int|null, auto_close_after_hours: int|null, transfer_reason_required: bool, pending_block_enabled: bool, pending_block_after_minutes: int},
 *     sla: array{first_response_minutes: int|null, resolution_minutes: int|null, response_minutes: int|null, warning_percent: int, business_hours_only: bool},
 *     intake: array{confirmation_required: bool},
 *     queue: array{min_wait_minutes: int, chronological_order: bool},
 *     sla_alerts: array{agent_warning: bool, agent_breach: bool, supervisor_warning: bool, supervisor_breach: bool, reminder_minutes: int|null, reminder_limit: int},
 * }
 */
class PlatformSettings
{
    public const CACHE_KEY = 'platform-settings';

    /**
     * Ordered list of weekdays used for business hours.
     *
     * @var list<string>
     */
    public const WEEKDAYS = ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'];

    /**
     * Get the complete configuration merged with defaults.
     *
     * @return SettingsShape
     */
    public function all(): array
    {
        /** @var array<string, mixed> $stored */
        $stored = Cache::rememberForever(self::CACHE_KEY, fn (): array => Setting::query()
            ->pluck('value', 'key')
            ->all());

        $defaults = $this->defaults();
        $settings = [];

        foreach ($defaults as $group => $values) {
            $storedGroup = $stored[$group] ?? [];
            $settings[$group] = is_array($storedGroup) ? array_replace_recursive($values, $storedGroup) : $values;
        }

        /** @var SettingsShape $settings */
        return $settings;
    }

    /**
     * Read a single value using dot notation (e.g. "general.timezone").
     */
    public function get(string $key, mixed $default = null): mixed
    {
        return Arr::get($this->all(), $key, $default);
    }

    /**
     * Persist the given groups and return the changed values.
     *
     * @param  array<string, array<string, mixed>>  $groups
     * @return array<string, array{old: mixed, new: mixed}>
     */
    public function update(array $groups): array
    {
        $current = $this->all();
        $changes = [];

        foreach ($groups as $group => $values) {
            if (! array_key_exists($group, $current)) {
                continue;
            }

            $merged = array_replace($current[$group], $values);
            $groupChanged = false;

            $before = Arr::dot($current[$group]);
            $after = Arr::dot($merged);

            // Paths of both sides: an item removed from a list is a change too.
            foreach (array_unique([...array_keys($after), ...array_keys($before)]) as $path) {
                $previous = $before[$path] ?? null;
                $value = $after[$path] ?? null;

                if ($previous !== $value) {
                    $changes["{$group}.{$path}"] = ['old' => $previous, 'new' => $value];
                    $groupChanged = true;
                }
            }

            // A group that keeps its values is not written at all.
            if ($groupChanged) {
                Setting::query()->updateOrCreate(['key' => $group], ['value' => $merged]);
            }
        }

        if ($changes !== []) {
            Cache::forget(self::CACHE_KEY);

            // The service rules memoize the platform values they resolved.
            if (app()->resolved(ServiceRules::class)) {
                app(ServiceRules::class)->flush();
            }
        }

        return $changes;
    }

    /**
     * Default configuration used until an administrator saves changes.
     *
     * @return SettingsShape
     */
    public function defaults(): array
    {
        $days = [];

        foreach (self::WEEKDAYS as $day) {
            $isWeekday = ! in_array($day, ['saturday', 'sunday'], true);
            $days[$day] = ['open' => $isWeekday, 'start' => '09:00', 'end' => '18:00'];
        }

        return [
            'general' => [
                'platform_name' => (string) config('app.name'),
                'timezone' => 'America/Mexico_City',
            ],
            'appearance' => [
                'palette' => ThemePalette::DEFAULT->value,
            ],
            'business_hours' => [
                'enabled' => false,
                'days' => $days,
                'holidays' => [],
            ],
            'operations' => [
                'default_department_id' => null,
                'max_open_conversations_per_agent' => null,
                'auto_close_after_hours' => null,
                'transfer_reason_required' => false,
                // Control of new conversations (PendingAttention): an agent
                // with customers waiting for their answer longer than the
                // tolerance cannot accept another one. Off until an
                // administrator turns it on (a stored value always wins).
                'pending_block_enabled' => false,
                'pending_block_after_minutes' => 10,
            ],
            // Platform level of the service rules (App\Services\ServiceRules is
            // the single reader: departments and categories may override
            // them). Objectives are in minutes; null = no objective (no
            // deadline and no compliance figure).
            'sla' => [
                'first_response_minutes' => null,
                'resolution_minutes' => null,
                'response_minutes' => null,
                'warning_percent' => 80,
                'business_hours_only' => true,
            ],
            // «Solicitud completa / Lista para atención».
            'intake' => [
                'confirmation_required' => false,
            ],
            // Minimum wait in the ready queue and order of attention.
            'queue' => [
                'min_wait_minutes' => 0,
                'chronological_order' => false,
            ],
            // Automatic SLA alerts (platform only): who is notified, and the
            // reminders of breached requests (null = no reminders).
            'sla_alerts' => [
                'agent_warning' => true,
                'agent_breach' => true,
                'supervisor_warning' => false,
                'supervisor_breach' => true,
                'reminder_minutes' => null,
                'reminder_limit' => 3,
            ],
        ];
    }
}

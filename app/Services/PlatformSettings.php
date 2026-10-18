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
 * @phpstan-type SettingsShape array{
 *     general: array{platform_name: string, timezone: string},
 *     appearance: array{palette: string},
 *     business_hours: array{enabled: bool, days: array<string, BusinessDay>},
 *     operations: array{default_department_id: int|null, max_open_conversations_per_agent: int|null, auto_close_after_hours: int|null},
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

            foreach (Arr::dot($merged) as $path => $value) {
                $previous = Arr::get($current[$group], $path);

                if ($previous !== $value) {
                    $changes["{$group}.{$path}"] = ['old' => $previous, 'new' => $value];
                }
            }

            Setting::query()->updateOrCreate(['key' => $group], ['value' => $merged]);
        }

        Cache::forget(self::CACHE_KEY);

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
            ],
            'operations' => [
                'default_department_id' => null,
                'max_open_conversations_per_agent' => null,
                'auto_close_after_hours' => null,
            ],
        ];
    }
}

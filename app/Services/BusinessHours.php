<?php

namespace App\Services;

use App\Models\Department;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Evaluates whether customers are being attended at a given moment, in the
 * platform time zone (`general.timezone`).
 *
 * Which schedule applies, first match wins:
 * 1. the department's own schedule when it uses custom hours;
 * 2. the platform schedule when "Aplicar horario de atención" is on;
 * 3. otherwise attention is continuous (always open).
 *
 * Non-working days (`business_hours.holidays`) close every schedule of
 * cases 1 and 2 for the whole day; a recurring one repeats every year on the
 * same month and day. Continuous attention ignores them.
 *
 * @phpstan-type Holiday array{date: string, label: string, recurring: bool}
 * @phpstan-type Day array{open: bool, start: string, end: string}
 */
class BusinessHours
{
    /** Days searched ahead for the next opening. */
    private const HORIZON_DAYS = 400;

    public function __construct(private PlatformSettings $settings) {}

    public function timezone(): string
    {
        return (string) $this->settings->get('general.timezone', 'America/Mexico_City');
    }

    /**
     * "department", "platform" or "continuous".
     */
    public function mode(?Department $department): string
    {
        if ($department !== null && $department->uses_custom_hours && is_array($department->business_hours['days'] ?? null)) {
            return 'department';
        }

        return $this->settings->get('business_hours.enabled') === true ? 'platform' : 'continuous';
    }

    /**
     * The weekly schedule that applies, or null for continuous attention.
     *
     * @return array<string, Day>|null
     */
    public function scheduleFor(?Department $department): ?array
    {
        /** @var array<string, Day>|null $days */
        $days = match ($this->mode($department)) {
            'department' => $department?->business_hours['days'] ?? null,
            'platform' => $this->settings->get('business_hours.days'),
            default => null,
        };

        return $days;
    }

    public function isOpenAt(?Department $department, CarbonInterface $at): bool
    {
        $schedule = $this->scheduleFor($department);

        if ($schedule === null) {
            return true;
        }

        $local = CarbonImmutable::instance($at)->setTimezone($this->timezone());

        if ($this->holidayOn($local) !== null) {
            return false;
        }

        $day = $schedule[strtolower($local->englishDayOfWeek)] ?? null;
        $time = $local->format('H:i');

        return is_array($day) && $day['open'] && $time >= $day['start'] && $time < $day['end'];
    }

    /**
     * The moment attention (re)starts: `$at` itself when open now, null when
     * the schedule never opens.
     */
    public function nextOpening(?Department $department, CarbonInterface $at): ?CarbonImmutable
    {
        $moment = CarbonImmutable::instance($at);

        if ($this->isOpenAt($department, $moment)) {
            return $moment;
        }

        $schedule = $this->scheduleFor($department) ?? [];
        $local = $moment->setTimezone($this->timezone());

        for ($offset = 0; $offset <= self::HORIZON_DAYS; $offset++) {
            $date = $local->startOfDay()->addDays($offset);
            $day = $schedule[strtolower($date->englishDayOfWeek)] ?? null;

            if (! is_array($day) || ! $day['open'] || $this->holidayOn($date) !== null) {
                continue;
            }

            [$hour, $minute] = array_map('intval', explode(':', $day['start']));
            $opening = $date->setTime($hour, $minute);

            if ($opening->greaterThan($local)) {
                return $opening->setTimezone(config('app.timezone'));
            }
        }

        return null;
    }

    /**
     * The non-working day matching the local date, if any.
     *
     * @return Holiday|null
     */
    public function holidayOn(CarbonInterface $localDate): ?array
    {
        $date = $localDate->format('Y-m-d');
        $monthDay = $localDate->format('m-d');

        foreach ($this->holidays() as $holiday) {
            if ($holiday['date'] === $date || ($holiday['recurring'] && substr($holiday['date'], 5) === $monthDay)) {
                return $holiday;
            }
        }

        return null;
    }

    /**
     * Configured non-working days, ordered by date.
     *
     * @return list<Holiday>
     */
    public function holidays(): array
    {
        $stored = $this->settings->get('business_hours.holidays', []);
        $holidays = [];

        foreach (is_array($stored) ? $stored : [] as $holiday) {
            if (is_array($holiday) && is_string($holiday['date'] ?? null) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $holiday['date']) === 1) {
                $holidays[] = [
                    'date' => $holiday['date'],
                    'label' => (string) ($holiday['label'] ?? ''),
                    'recurring' => (bool) ($holiday['recurring'] ?? false),
                ];
            }
        }

        usort($holidays, fn (array $a, array $b): int => strcmp($a['date'], $b['date']));

        return $holidays;
    }

    /**
     * Summary for screens: which schedule applies and whether it is open now.
     *
     * @return array{mode: string, is_open: bool, next_opening: string|null, holiday: string|null, timezone: string}
     */
    public function describe(?Department $department, ?CarbonInterface $at = null): array
    {
        $at = CarbonImmutable::instance($at ?? now());
        $isOpen = $this->isOpenAt($department, $at);
        $holiday = $this->mode($department) === 'continuous' ? null : $this->holidayOn($at->setTimezone($this->timezone()));

        return [
            'mode' => $this->mode($department),
            'is_open' => $isOpen,
            'next_opening' => $isOpen ? null : $this->nextOpening($department, $at)?->toIso8601String(),
            'holiday' => $holiday === null ? null : ($holiday['label'] !== '' ? $holiday['label'] : 'Día no laborable'),
            'timezone' => $this->timezone(),
        ];
    }
}

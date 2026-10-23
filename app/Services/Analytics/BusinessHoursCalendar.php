<?php

namespace App\Services\Analytics;

use App\Models\Department;
use App\Services\BusinessHours;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Business hours questions of the reports. It delegates to the platform
 * service App\Services\BusinessHours (the same rules the automatic away
 * messages follow): the department's own hours while it uses custom hours,
 * otherwise the platform hours while they are enabled, with the configured
 * non-working days closing the whole day. Times are interpreted in the
 * platform time zone, day by day, so daylight saving changes are honored.
 *
 * The schedule format only has one range per day that closes after it
 * opens (validated on save); a stored day whose closing is not after its
 * opening (e.g. a shift crossing midnight edited by hand) counts as closed
 * instead of breaking the computation.
 */
class BusinessHoursCalendar
{
    /**
     * Longest span walked day by day; beyond it the figure is not measured.
     */
    public const MAX_DAYS = 1100;

    public function __construct(private BusinessHours $hours) {}

    /**
     * Whether the moment is outside the business hours, or null when no
     * schedule applies (continuous attention: platform hours disabled and
     * no custom hours), so the figure is "not measurable" instead of zero.
     * The schedule in force when the figure is computed is the one applied.
     */
    public function isOutsideHours(CarbonInterface $moment, ?Department $department): ?bool
    {
        if ($this->hours->scheduleFor($department) === null) {
            return null;
        }

        return ! $this->hours->isOpenAt($department, $moment);
    }

    /**
     * Seconds of [from, to) inside the attention hours of the department.
     * With continuous attention (no schedule applies) every second is an
     * attention second, so it equals the calendar time. Null when the span
     * is longer than MAX_DAYS.
     */
    public function businessSecondsBetween(CarbonInterface $from, CarbonInterface $to, ?Department $department): ?int
    {
        $start = CarbonImmutable::instance($from);
        $end = CarbonImmutable::instance($to);

        if ($end <= $start) {
            return 0;
        }

        $schedule = $this->hours->scheduleFor($department);

        if ($schedule === null) {
            return (int) $start->diffInSeconds($end);
        }

        $timezone = $this->hours->timezone();
        $day = $start->setTimezone($timezone)->startOfDay();
        $lastDay = $end->setTimezone($timezone)->startOfDay();

        if ($day->diffInDays($lastDay) > self::MAX_DAYS) {
            return null;
        }

        $seconds = 0;

        // Compared by local date and normalized every iteration: where daylight
        // saving starts at midnight the day begins at 01:00 and addDay() would
        // keep that hour, skipping the last day against a 00:00 $lastDay.
        for (; $day->toDateString() <= $lastDay->toDateString(); $day = $day->addDay()->startOfDay()) {
            $hours = $schedule[strtolower($day->englishDayOfWeek)] ?? null;

            if (! is_array($hours) || ! $hours['open'] || $hours['end'] <= $hours['start'] || $this->hours->holidayOn($day) !== null) {
                continue;
            }

            $opening = $this->at($day, $hours['start']);
            $closing = $this->at($day, $hours['end']);
            $overlapStart = $opening->greaterThan($start) ? $opening : $start;
            $overlapEnd = $closing->lessThan($end) ? $closing : $end;

            if ($overlapEnd > $overlapStart) {
                $seconds += (int) $overlapStart->diffInSeconds($overlapEnd);
            }
        }

        return $seconds;
    }

    private function at(CarbonImmutable $localDay, string $time): CarbonImmutable
    {
        [$hour, $minute] = array_map('intval', explode(':', $time));

        return $localDay->setTime($hour, $minute);
    }
}

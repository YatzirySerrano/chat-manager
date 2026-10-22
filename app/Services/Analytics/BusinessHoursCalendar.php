<?php

namespace App\Services\Analytics;

use App\Models\Department;
use App\Services\BusinessHours;
use Carbon\CarbonInterface;

/**
 * Answers "was this moment inside the business hours?" for the reports. It
 * delegates to the platform service App\Services\BusinessHours (the same
 * rules the automatic away messages follow): the department's own hours
 * while it uses custom hours, otherwise the platform hours while they are
 * enabled, with the configured non-working days closing the whole day.
 * Times are interpreted in the platform time zone.
 *
 * The schedule in force when the figure is computed is the one applied:
 * rebuilding the metrics after editing the hours reclassifies history.
 */
class BusinessHoursCalendar
{
    public function __construct(private BusinessHours $hours) {}

    /**
     * Whether the moment is outside the business hours, or null when no
     * schedule applies (continuous attention: platform hours disabled and
     * no custom hours), so the figure is "not measurable" instead of zero.
     */
    public function isOutsideHours(CarbonInterface $moment, ?Department $department): ?bool
    {
        if ($this->hours->scheduleFor($department) === null) {
            return null;
        }

        return ! $this->hours->isOpenAt($department, $moment);
    }
}

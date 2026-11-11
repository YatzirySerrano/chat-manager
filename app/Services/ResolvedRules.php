<?php

namespace App\Services;

use InvalidArgumentException;

/**
 * The service rules in force for one department/category combination, as
 * resolved by ServiceRules (category > department > platform). Immutable:
 * a decision reads it once and never sees a half-applied change.
 */
final readonly class ResolvedRules
{
    /**
     * @param  array<string, int|bool|null>  $values  Effective value of every rule key.
     * @param  array<string, string>  $sources  Level each value comes from: platform, department or category.
     */
    public function __construct(
        private array $values,
        private array $sources,
    ) {}

    /**
     * Objective for the first answer of the team, or null when none is set.
     */
    public function firstResponseSeconds(): ?int
    {
        return $this->seconds('sla.first_response_minutes');
    }

    /**
     * Objective for the answer to each later customer message, or null.
     */
    public function responseSeconds(): ?int
    {
        return $this->seconds('sla.response_minutes');
    }

    /**
     * Objective for closing the request, or null when none is set.
     */
    public function resolutionSeconds(): ?int
    {
        return $this->seconds('sla.resolution_minutes');
    }

    /**
     * Share of the deadline consumed at which a request is «próxima a vencer».
     */
    public function warningPercent(): int
    {
        return (int) $this->values['sla.warning_percent'];
    }

    /**
     * Whether the objectives count only business time.
     */
    public function businessHoursOnly(): bool
    {
        return (bool) $this->values['sla.business_hours_only'];
    }

    /**
     * Whether a request must be confirmed as complete before it can be taken.
     */
    public function confirmationRequired(): bool
    {
        return (bool) $this->values['intake.confirmation_required'];
    }

    /**
     * Minimum time in the ready queue before a request can be accepted (0 = off).
     */
    public function minWaitSeconds(): int
    {
        return (int) $this->values['queue.min_wait_minutes'] * 60;
    }

    /**
     * Whether agents must take the oldest eligible request of the department first.
     */
    public function chronologicalOrder(): bool
    {
        return (bool) $this->values['queue.chronological_order'];
    }

    /**
     * Raw effective value of a rule key (minutes, percent or boolean).
     */
    public function value(string $key): int|bool|null
    {
        if (! array_key_exists($key, $this->values)) {
            throw new InvalidArgumentException("Unknown service rule [{$key}].");
        }

        return $this->values[$key];
    }

    /**
     * Level the value of the rule comes from: platform, department or category.
     */
    public function source(string $key): string
    {
        return $this->sources[$key] ?? throw new InvalidArgumentException("Unknown service rule [{$key}].");
    }

    /**
     * @return array<string, array{value: int|bool|null, source: string}>
     */
    public function toArray(): array
    {
        $rules = [];

        foreach ($this->values as $key => $value) {
            $rules[$key] = ['value' => $value, 'source' => $this->sources[$key]];
        }

        return $rules;
    }

    private function seconds(string $key): ?int
    {
        $minutes = $this->values[$key];

        return is_int($minutes) ? $minutes * 60 : null;
    }
}

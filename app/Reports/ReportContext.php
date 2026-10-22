<?php

namespace App\Reports;

use App\Models\User;
use Carbon\CarbonImmutable;

/**
 * Everything a definition needs to build one export: who asks, with which
 * validated filters, and how the platform presents dates and its name.
 */
final readonly class ReportContext
{
    /**
     * @param  array<string, mixed>  $filters  Validated and normalized filters.
     */
    public function __construct(
        public User $actor,
        public array $filters,
        public CarbonImmutable $generatedAt,
        public string $platformName,
        public string $timezone,
    ) {}

    public function filter(string $key, mixed $default = null): mixed
    {
        return $this->filters[$key] ?? $default;
    }
}

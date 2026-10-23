<?php

namespace App\Reports;

use App\Models\User;
use Carbon\CarbonImmutable;

/**
 * Everything a definition needs to build one export: who asks, with which
 * validated filters, and how the platform presents dates and its name.
 *
 * `requestedAt` is when the user asked for the export; a queued export is
 * generated later (`generatedAt`) but relative periods ("today", "last 7
 * days") are resolved against asOf(), the moment of the request.
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
        public ?CarbonImmutable $requestedAt = null,
    ) {}

    /**
     * Moment the relative filters are resolved against: the request.
     */
    public function asOf(): CarbonImmutable
    {
        return $this->requestedAt ?? $this->generatedAt;
    }

    public function filter(string $key, mixed $default = null): mixed
    {
        return $this->filters[$key] ?? $default;
    }
}

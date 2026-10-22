<?php

namespace App\Services\Operations;

/**
 * One verification of the system health (or of the production preflight).
 * The summary is Spanish, for administrators; details hold only
 * non-sensitive scalar values (counts, ages, names of drivers).
 */
final readonly class HealthCheck
{
    /**
     * @param  array<string, scalar|null>  $details
     */
    public function __construct(
        public string $key,
        public string $label,
        public HealthStatus $status,
        public string $summary,
        public array $details = [],
    ) {}

    /**
     * @param  array<string, scalar|null>  $details
     */
    public static function ok(string $key, string $label, string $summary, array $details = []): self
    {
        return new self($key, $label, HealthStatus::Ok, $summary, $details);
    }

    /**
     * @param  array<string, scalar|null>  $details
     */
    public static function warning(string $key, string $label, string $summary, array $details = []): self
    {
        return new self($key, $label, HealthStatus::Warning, $summary, $details);
    }

    /**
     * @param  array<string, scalar|null>  $details
     */
    public static function fail(string $key, string $label, string $summary, array $details = []): self
    {
        return new self($key, $label, HealthStatus::Fail, $summary, $details);
    }

    public static function skipped(string $key, string $label, string $summary): self
    {
        return new self($key, $label, HealthStatus::Skipped, $summary);
    }

    /**
     * @return array{key: string, label: string, status: string, status_label: string, summary: string, details: array<string, scalar|null>}
     */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'label' => $this->label,
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'summary' => $this->summary,
            'details' => $this->details,
        ];
    }
}

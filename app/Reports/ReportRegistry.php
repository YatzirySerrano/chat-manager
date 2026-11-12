<?php

namespace App\Reports;

use App\Support\Features;
use Illuminate\Contracts\Container\Container;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/**
 * Catalog of exportable reports declared in config/exports.php.
 */
class ReportRegistry
{
    /**
     * Reports of phase two (SLA compliance from response cycles, per-agent
     * productivity): unknown (404) while FEATURE_PHASE_TWO is off.
     *
     * @var list<string>
     */
    public const PHASE_TWO_KEYS = ['agent-productivity', 'sla-compliance'];

    public function __construct(private Container $container) {}

    /**
     * The definition registered under the key, or null when there is none.
     *
     * @return ReportDefinition<Model>|null
     */
    public function find(string $key): ?ReportDefinition
    {
        $class = $this->definitions()[$key] ?? null;

        if ($class === null || (! Features::phaseTwo() && in_array($key, self::PHASE_TWO_KEYS, true))) {
            return null;
        }

        $definition = $this->container->make($class);

        if (! $definition instanceof ReportDefinition) {
            throw new InvalidArgumentException("The report [{$key}] must extend ".ReportDefinition::class.'.');
        }

        /** @var ReportDefinition<Model> $definition */
        return $definition;
    }

    /**
     * Human title of a report key, falling back to the key itself.
     */
    public function title(string $key): string
    {
        return $this->find($key)?->title() ?? $key;
    }

    /**
     * @return list<string>
     */
    public function keys(): array
    {
        return array_values(array_filter(
            array_keys($this->definitions()),
            fn (string $key): bool => Features::phaseTwo() || ! in_array($key, self::PHASE_TWO_KEYS, true),
        ));
    }

    /**
     * @return array<string, class-string>
     */
    private function definitions(): array
    {
        /** @var array<string, class-string> $definitions */
        $definitions = config('exports.reports', []);

        return $definitions;
    }
}

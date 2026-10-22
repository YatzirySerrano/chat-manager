<?php

namespace App\Reports;

use Illuminate\Contracts\Container\Container;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/**
 * Catalog of exportable reports declared in config/exports.php.
 */
class ReportRegistry
{
    public function __construct(private Container $container) {}

    /**
     * The definition registered under the key, or null when there is none.
     *
     * @return ReportDefinition<Model>|null
     */
    public function find(string $key): ?ReportDefinition
    {
        $class = $this->definitions()[$key] ?? null;

        if ($class === null) {
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
        return array_keys($this->definitions());
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

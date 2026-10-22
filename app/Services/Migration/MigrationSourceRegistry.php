<?php

namespace App\Services\Migration;

use App\Services\Migration\MigrationSourceAdapter as Adapter;
use InvalidArgumentException;

/**
 * The source adapters the importer offers. Only the adapters listed here
 * exist: a source is never resolved from arbitrary input.
 *
 * @phpstan-import-type SourceDetection from MigrationSourceAdapter
 */
class MigrationSourceRegistry
{
    /**
     * @var list<class-string<Adapter>>
     */
    private const ADAPTERS = [
        GenericContactsAdapter::class,
        WhaticketContactsAdapter::class,
    ];

    /**
     * @return array<string, Adapter>
     */
    public function all(): array
    {
        $adapters = [];

        foreach (self::ADAPTERS as $class) {
            $adapter = app($class);
            $adapters[$adapter->key()] = $adapter;
        }

        return $adapters;
    }

    /**
     * @return list<string>
     */
    public function keys(): array
    {
        return array_keys($this->all());
    }

    public function find(string $key): Adapter
    {
        return $this->all()[$key] ?? throw new InvalidArgumentException("Unknown import source [{$key}].");
    }

    /**
     * The adapter of a stored key, falling back to the generic one (imports
     * created before source adapters existed).
     */
    public function resolve(?string $key): Adapter
    {
        return $this->all()[(string) $key] ?? $this->find(GenericContactsAdapter::KEY);
    }

    /**
     * Detection of every adapter, best first (the generic one wins ties).
     *
     * @param  list<string>  $headers
     * @return list<array{key: string, label: string, score: int, matched: list<string>, signals: list<string>}>
     */
    public function detect(array $headers): array
    {
        $results = [];

        foreach ($this->all() as $key => $adapter) {
            $results[] = ['key' => $key, 'label' => $adapter->label(), ...$adapter->detect($headers)];
        }

        usort($results, fn (array $a, array $b): int => ($b['score'] <=> $a['score'])
            ?: (($b['key'] === GenericContactsAdapter::KEY) <=> ($a['key'] === GenericContactsAdapter::KEY)));

        return $results;
    }

    /**
     * Options for the UI.
     *
     * @return list<array{value: string, label: string, description: string, validated: bool, validation_note: string|null}>
     */
    public function options(): array
    {
        return array_values(array_map(fn (Adapter $adapter): array => [
            'value' => $adapter->key(),
            'label' => $adapter->label(),
            'description' => $adapter->description(),
            'validated' => $adapter->isValidated(),
            'validation_note' => $adapter->validationNote(),
        ], $this->all()));
    }
}

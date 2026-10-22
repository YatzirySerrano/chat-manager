<?php

namespace App\Enums;

use App\Models\User;

/**
 * Which records of a list to show according to their logical deletion.
 *
 * Deleted records are only revealed to people holding
 * records.view-deleted: for everybody else every list, counter, search and
 * select behaves as if they did not exist, whatever the query string asks.
 */
enum RecordState: string
{
    case Active = 'active';
    case Deleted = 'deleted';
    case All = 'all';

    public function label(): string
    {
        return match ($this) {
            self::Active => 'Activos',
            self::Deleted => 'Eliminados',
            self::All => 'Todos',
        };
    }

    /**
     * The state the actor may list: the requested one only when the actor
     * can consult deleted records, otherwise always the active ones.
     */
    public static function resolveFor(User $actor, mixed $requested): self
    {
        if (! $actor->canViewDeletedRecords()) {
            return self::Active;
        }

        return is_string($requested) ? (self::tryFrom($requested) ?? self::Active) : self::Active;
    }

    /**
     * Options of the "Estado del registro" filter, empty when the actor
     * cannot consult deleted records (the filter is then not offered).
     *
     * @return list<array{value: string, label: string}>
     */
    public static function optionsFor(User $actor): array
    {
        if (! $actor->canViewDeletedRecords()) {
            return [];
        }

        return array_map(fn (self $state): array => ['value' => $state->value, 'label' => $state->label()], self::cases());
    }
}

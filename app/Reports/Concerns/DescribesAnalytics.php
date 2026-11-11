<?php

namespace App\Reports\Concerns;

use App\Enums\ConnectionChannel;
use App\Enums\ConversationStatus;
use App\Models\Connection;
use App\Models\ConversationCategory;
use App\Models\Department;
use App\Models\User;
use App\Services\Analytics\AnalyticsFilters;

/**
 * Wording shared by the analytics exports (reports, dashboard, productivity
 * and service level): the applied filters, the bucket units and durations.
 */
trait DescribesAnalytics
{
    /**
     * Period, comparison and every dimension filter in force, as shown in
     * the header of the document and stored in the audit log.
     *
     * @param  'all'|'departments'|'own'  $reach
     * @return list<string>
     */
    protected function describeAnalyticsFilters(AnalyticsFilters $filters, User $actor, string $reach, bool $withComparison = true): array
    {
        $period = $filters->period;
        $descriptions = ["Periodo: {$period->label()} ({$period->timezone})"];

        if ($withComparison) {
            $comparison = $period->comparison();
            $descriptions[] = $period->inProgress()
                ? "Comparado con: el mismo tramo transcurrido del periodo anterior ({$comparison->label()}, hasta las {$comparison->to->format('H:i')})"
                : "Comparado con: {$comparison->label()}";
        }

        $descriptions[] = 'Incluye: '.match ($reach) {
            'all' => 'toda la organización',
            'departments' => 'tus departamentos',
            default => 'tus conversaciones',
        };

        if ($filters->departmentId !== null) {
            $descriptions[] = 'Departamento: '.Department::query()->whereKey($filters->departmentId)->value('name');
        }

        if ($filters->agentId !== null) {
            $descriptions[] = 'Agente: '.User::query()->whereKey($filters->agentId)->withoutProtectedFor($actor)->value('name');
        }

        if ($filters->connectionId !== null) {
            $descriptions[] = 'Conexión: '.Connection::query()->whereKey($filters->connectionId)->value('name');
        }

        if ($filters->channel !== null) {
            $descriptions[] = 'Canal: '.(ConnectionChannel::tryFrom($filters->channel)?->label() ?? $filters->channel);
        }

        if ($filters->categoryId !== null) {
            $descriptions[] = 'Categoría: '.ConversationCategory::query()->whereKey($filters->categoryId)->value('name');
        }

        if ($filters->status !== null) {
            $descriptions[] = 'Estado actual: '.(ConversationStatus::tryFrom($filters->status)?->label() ?? $filters->status);
        }

        return $descriptions;
    }

    /**
     * @param  'hour'|'day'|'week'|'month'  $granularity
     */
    protected function bucketUnit(string $granularity): string
    {
        return match ($granularity) {
            'hour' => 'hora',
            'day' => 'día',
            'week' => 'semana',
            'month' => 'mes',
        };
    }

    /**
     * Seconds as minutes with one decimal (a real number, operable in Excel).
     */
    protected function toMinutes(?int $seconds): ?float
    {
        return $seconds === null ? null : round($seconds / 60, 1);
    }

    /**
     * "45 s", "12 min 5 s", "3 h 20 min".
     */
    protected function spellDuration(?int $seconds): ?string
    {
        if ($seconds === null) {
            return null;
        }

        $seconds = max(0, $seconds);

        return match (true) {
            $seconds < 60 => "{$seconds} s",
            $seconds < 3600 => intdiv($seconds, 60).' min '.($seconds % 60).' s',
            default => intdiv($seconds, 3600).' h '.intdiv($seconds % 3600, 60).' min',
        };
    }

    /**
     * "82.5 %" of a rate already expressed in percent; null stays null.
     */
    protected function spellRate(?float $rate): ?string
    {
        return $rate === null ? null : rtrim(rtrim(number_format($rate, 1), '0'), '.').' %';
    }
}

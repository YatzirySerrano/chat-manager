<?php

namespace App\Actions\Audit;

use App\Enums\AuditAction;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

/**
 * The audit log as the audit screen lists it: only the entries the viewer
 * may read (AuditLog::visibleTo: restricted entries only for the root
 * authority, own entries only without the global scope), by action, actor
 * and date range, newest first.
 *
 * Shared by the audit screen and the audit report.
 *
 * @phpstan-type AuditFilters array{action: string|null, actor: int|null, from: string|null, to: string|null}
 */
class AuditLogQuery
{
    /**
     * Validation rules of the filters (query string).
     *
     * @return array<string, mixed>
     */
    public static function rules(): array
    {
        return [
            'action' => ['nullable', Rule::enum(AuditAction::class)],
            'actor' => ['nullable', 'integer'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
        ];
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return AuditFilters
     */
    public function filters(array $validated): array
    {
        $string = fn (string $key): ?string => is_string($validated[$key] ?? null) && $validated[$key] !== '' ? $validated[$key] : null;

        return [
            'action' => $string('action'),
            'actor' => is_numeric($validated['actor'] ?? null) ? (int) $validated['actor'] : null,
            'from' => $string('from'),
            'to' => $string('to'),
        ];
    }

    /**
     * @param  AuditFilters  $filters
     * @return Builder<AuditLog>
     */
    public function handle(User $viewer, array $filters): Builder
    {
        return AuditLog::query()
            ->visibleTo($viewer)
            ->when($filters['action'], fn (Builder $query, string $action) => $query->where('action', $action))
            ->when($filters['actor'], fn (Builder $query, int $actor) => $query->where('user_id', $actor))
            ->when($filters['from'], fn (Builder $query, string $from) => $query->where('created_at', '>=', Carbon::parse($from)->startOfDay()))
            ->when($filters['to'], fn (Builder $query, string $to) => $query->where('created_at', '<=', Carbon::parse($to)->endOfDay()))
            ->orderByDesc('created_at')
            ->orderByDesc('id');
    }
}

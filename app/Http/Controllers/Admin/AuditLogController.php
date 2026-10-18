<?php

namespace App\Http\Controllers\Admin;

use App\Enums\AuditAction;
use App\Enums\DataScope;
use App\Http\Controllers\Controller;
use App\Http\Resources\AuditLogResource;
use App\Models\AuditLog;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class AuditLogController extends Controller
{
    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', AuditLog::class);

        $filters = $request->validate([
            'action' => ['nullable', Rule::enum(AuditAction::class)],
            'actor' => ['nullable', 'integer'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
        ]);

        $ownOnly = $request->user()?->dataScope() !== DataScope::Global;
        $actorId = $request->user()?->id;

        $logs = AuditLog::query()
            ->when($ownOnly, fn (Builder $query) => $query->where('user_id', $actorId))
            ->when($filters['action'] ?? null, fn (Builder $query, string $action) => $query->where('action', $action))
            ->when($filters['actor'] ?? null, fn (Builder $query, int|string $actor) => $query->where('user_id', (int) $actor))
            ->when($filters['from'] ?? null, fn (Builder $query, string $from) => $query->where('created_at', '>=', Carbon::parse($from)->startOfDay()))
            ->when($filters['to'] ?? null, fn (Builder $query, string $to) => $query->where('created_at', '<=', Carbon::parse($to)->endOfDay()))
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate(25)
            ->withQueryString();

        return Inertia::render('admin/audit/Index', [
            'logs' => AuditLogResource::collection($logs),
            'filters' => [
                'action' => $filters['action'] ?? null,
                'actor' => isset($filters['actor']) ? (int) $filters['actor'] : null,
                'from' => $filters['from'] ?? null,
                'to' => $filters['to'] ?? null,
            ],
            'actions' => AuditAction::options(),
            'scope' => $ownOnly ? 'own' : 'all',
            'actors' => AuditLog::query()
                ->whereNotNull('user_id')
                ->when($ownOnly, fn (Builder $query) => $query->where('user_id', $actorId))
                ->select(['user_id', 'actor_name'])
                ->distinct()
                ->orderBy('actor_name')
                ->limit(200)
                ->get()
                ->unique('user_id')
                ->map(fn (AuditLog $log): array => ['id' => (int) $log->user_id, 'name' => (string) $log->actor_name])
                ->values(),
        ]);
    }
}

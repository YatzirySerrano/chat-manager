<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Audit\AuditLogQuery;
use App\Actions\Audit\PruneAuditLogs;
use App\Enums\AuditAction;
use App\Enums\DataScope;
use App\Enums\Permission;
use App\Http\Controllers\Controller;
use App\Http\Resources\AuditLogResource;
use App\Models\AuditLog;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\Exports\ReportExporter;
use App\Support\Audit\AuditPresenter;
use App\Support\ProtectedAccounts;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class AuditLogController extends Controller
{
    /**
     * The entries the viewer may read (the same query as the audit report).
     */
    public function index(Request $request, AuditLogQuery $audit, ReportExporter $exporter, AuditPresenter $presenter): Response
    {
        Gate::authorize('viewAny', AuditLog::class);

        $filters = $audit->filters($request->validate(AuditLogQuery::rules()));

        /** @var User $viewer */
        $viewer = $request->user();
        $ownOnly = $viewer->dataScope() !== DataScope::Global;

        $logs = $audit->handle($viewer, $filters)
            ->paginate(25)
            ->withQueryString();

        $presenter->loadSubjects($logs->getCollection());

        return Inertia::render('admin/audit/Index', [
            'logs' => AuditLogResource::collection($logs),
            'filters' => [
                'action' => $filters['action'] ?? null,
                'actor' => $filters['actor'],
                'from' => $filters['from'] ?? null,
                'to' => $filters['to'] ?? null,
            ],
            'actions' => AuditAction::options(),
            'scope' => $ownOnly ? 'own' : 'all',
            'actors' => AuditLog::query()
                ->visibleTo($viewer)
                ->whereNotNull('user_id')
                // Protected accounts (root role) are no option for anyone else.
                ->when(! $viewer->hasRootAuthority(), fn ($query) => $query->whereNotIn('user_id', app(ProtectedAccounts::class)->ids()))
                ->select(['user_id', 'actor_name'])
                ->distinct()
                ->orderBy('actor_name')
                ->limit(200)
                ->get()
                ->unique('user_id')
                ->map(fn (AuditLog $log): array => ['id' => (int) $log->user_id, 'name' => (string) $log->actor_name])
                ->values(),
            'canExport' => $exporter->allowsReport('audit', $viewer),
            // «Eliminar registros de auditoría» (Superadministrador).
            'canPurge' => $viewer->holdsPermission(Permission::PurgeAuditLog),
        ]);
    }

    /**
     * «Eliminar registros de auditoría» (Superadministrador): deletes entries
     * older than a chosen date, or every entry when no date is given. Besides
     * this manual purge, the system prunes old entries by itself every day
     * (audit:prune). Confirmed by typing ELIMINAR.
     */
    public function destroy(Request $request, PruneAuditLogs $prune, AuditLogger $audit): RedirectResponse
    {
        /** @var User $actor */
        $actor = $request->user();

        abort_unless($actor->holdsPermission(Permission::PurgeAuditLog), 403);

        $validated = $request->validate([
            'before' => ['nullable', 'date_format:Y-m-d'],
            'confirmation' => ['required', 'string', fn (string $attribute, mixed $value, callable $fail) => mb_strtoupper(trim((string) $value)) === 'ELIMINAR'
                ? null
                : $fail('Escribe ELIMINAR para confirmar que quieres borrar los registros de auditoría.')],
        ], ['confirmation.required' => 'Escribe ELIMINAR para confirmar que quieres borrar los registros de auditoría.']);

        $before = isset($validated['before']) ? Carbon::createFromFormat('Y-m-d', $validated['before'])->startOfDay() : null;

        $deleted = $prune->purge($before);

        // A trace of the purge stays in the log (a fresh entry, after the delete).
        $audit->record(AuditAction::AuditLogPurged, null, [
            'deleted' => $deleted,
            'before' => $before?->toDateString(),
        ], $request->user());

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => $deleted === 0
                ? 'No había registros de auditoría que eliminar.'
                : "Se eliminaron {$deleted} registros de auditoría.",
        ]);

        return back();
    }
}

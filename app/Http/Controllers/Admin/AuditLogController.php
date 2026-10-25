<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Audit\AuditLogQuery;
use App\Enums\AuditAction;
use App\Enums\DataScope;
use App\Http\Controllers\Controller;
use App\Http\Resources\AuditLogResource;
use App\Models\AuditLog;
use App\Models\User;
use App\Services\Exports\ReportExporter;
use App\Support\Audit\AuditPresenter;
use Illuminate\Http\Request;
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
                ->select(['user_id', 'actor_name'])
                ->distinct()
                ->orderBy('actor_name')
                ->limit(200)
                ->get()
                ->unique('user_id')
                ->map(fn (AuditLog $log): array => ['id' => (int) $log->user_id, 'name' => (string) $log->actor_name])
                ->values(),
            'canExport' => $exporter->allowsReport('audit', $viewer),
        ]);
    }
}

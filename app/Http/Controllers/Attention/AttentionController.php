<?php

namespace App\Http\Controllers\Attention;

use App\Enums\AttentionAlertPriority;
use App\Enums\AttentionAlertSound;
use App\Enums\ColorSwatch;
use App\Enums\Permission;
use App\Http\Controllers\Controller;
use App\Http\Requests\Attention\StoreAttentionAlertRequest;
use App\Models\Department;
use App\Models\User;
use App\Services\Attention\AttentionOverview;
use App\Services\Conversations\PendingAttention;
use App\Services\Sla\SlaMonitor;
use App\Support\Features;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * «Seguimiento de atención»: what each agent within the viewer's reach is
 * attending, which customers are waiting for an answer and since when,
 * (with attention.alert) the alerts sent to the team, and the «Monitoreo de
 * SLA» (SlaMonitor: open response cycles and breach history). Authorized on the
 * server for every request (gate view-attention); every prop is lazy, so
 * the page's periodic and real-time refreshes reload only what changed.
 */
class AttentionController extends Controller
{
    public function index(Request $request, AttentionOverview $overview, PendingAttention $pending, SlaMonitor $sla): Response
    {
        Gate::authorize('view-attention');

        /** @var User $viewer */
        $viewer = $request->user();
        $canSend = $viewer->can('send-attention-alerts');

        // The «Monitoreo de SLA» is phase two (FEATURE_PHASE_TWO): while it
        // is off its tab does not exist and no SLA prop is sent.
        abort_if(! Features::phaseTwo() && $request->query('tab') === 'sla', 404);

        $slaFilters = Features::phaseTwo() ? $sla->filters($request->validate(SlaMonitor::rules())) : null;
        $slaProps = $slaFilters === null ? [] : [
            'sla' => Inertia::defer(fn (): array => $sla->open($viewer, $slaFilters), 'sla'),
            'slaBreaches' => Inertia::defer(fn (): array => $sla->breaches($viewer, $slaFilters), 'sla-history'),
            'slaFilters' => fn (): array => $slaFilters,
            'slaOptions' => fn (): array => $sla->options($viewer),
        ];

        return Inertia::render('attention/Index', [
            'agents' => fn (): array => $overview->agents($viewer),
            'waiting' => fn (): array => $overview->waiting($viewer),
            'queue' => fn (): array => $overview->queue($viewer),
            'alerts' => fn (): array => $canSend ? $overview->alerts($viewer) : [],
            'generatedAt' => fn (): string => now()->toIso8601String(),
            'departments' => fn () => Department::query()->visibleTo($viewer)->notDeleted()->orderBy('name')->get(['id', 'name']),
            'control' => fn (): array => [
                'enabled' => $pending->isEnabled(),
                'tolerance_minutes' => $pending->toleranceMinutes(),
            ],
            // «Monitoreo de SLA»: open response cycles and breach history of the viewer's reach.
            'tab' => fn (): string => $request->query('tab') === 'sla' ? 'sla' : 'attention',
            ...$slaProps,
            // Anchor of the shared countdown clock (useServerNow).
            'serverTime' => fn (): string => now()->toIso8601String(),
            'can' => [
                'send' => $canSend,
                'settings' => $viewer->holdsPermission(Permission::UpdateSettings),
            ],
            'settingsUrl' => fn (): ?string => $viewer->holdsPermission(Permission::UpdateSettings) ? route('admin.settings.edit') : null,
            'options' => [
                'priorities' => AttentionAlertPriority::options(),
                'sounds' => AttentionAlertSound::options(),
                'colors' => array_map(fn (ColorSwatch $color): array => ['value' => $color->value, 'label' => $color->label()], ColorSwatch::cases()),
                'title_max' => StoreAttentionAlertRequest::TITLE_MAX_LENGTH,
                'body_max' => StoreAttentionAlertRequest::BODY_MAX_LENGTH,
            ],
        ]);
    }
}

<?php

namespace App\Http\Controllers\Attention;

use App\Enums\AttentionAlertPriority;
use App\Enums\AttentionAlertSound;
use App\Enums\ColorSwatch;
use App\Enums\Permission;
use App\Http\Controllers\Controller;
use App\Http\Requests\Attention\StoreAttentionAlertRequest;
use App\Http\Requests\Attention\UpdateAttentionAutomationRequest;
use App\Models\Department;
use App\Models\User;
use App\Services\Attention\AttentionOverview;
use App\Services\Conversations\PendingAttention;
use App\Services\PlatformSettings;
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
    public function index(Request $request, AttentionOverview $overview, PendingAttention $pending, SlaMonitor $sla, PlatformSettings $settings): Response
    {
        Gate::authorize('view-attention');

        /** @var User $viewer */
        $viewer = $request->user();
        $canSend = $viewer->can('send-attention-alerts');
        $canAutomate = $viewer->holdsPermission(Permission::UpdateSettings);

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
                'settings' => $canAutomate,
                'automation' => $canAutomate,
            ],
            'settingsUrl' => fn (): ?string => $canAutomate ? route('admin.settings.edit') : null,
            // «Alertas automáticas» (time_alerts): current escalation steps and
            // completed broadcast, only for whoever may change the settings.
            'automation' => fn (): ?array => $canAutomate ? [
                'queue_enabled' => (bool) $settings->get('time_alerts.queue_enabled', true),
                'queue_steps' => $this->steps($settings->get('time_alerts.queue_steps'), (int) $settings->get('time_alerts.queue_minutes', 5)),
                'attention_enabled' => (bool) $settings->get('time_alerts.attention_enabled', true),
                'attention_steps' => $this->steps($settings->get('time_alerts.attention_steps'), (int) $settings->get('time_alerts.attention_minutes', 15)),
                'completed_enabled' => (bool) $settings->get('time_alerts.completed_enabled', true),
                'completed_priority' => (string) $settings->get('time_alerts.completed_priority', 'informative'),
                'completed_message' => $settings->get('time_alerts.completed_message'),
            ] : null,
            'options' => [
                'priorities' => AttentionAlertPriority::options(),
                'sounds' => AttentionAlertSound::options(),
                'colors' => array_map(fn (ColorSwatch $color): array => ['value' => $color->value, 'label' => $color->label()], ColorSwatch::cases()),
                'title_max' => StoreAttentionAlertRequest::TITLE_MAX_LENGTH,
                'body_max' => StoreAttentionAlertRequest::BODY_MAX_LENGTH,
                // Urgency of each automatic alert.
                'alert_levels' => [
                    ['value' => 'urgent', 'label' => 'Requiere acción'],
                    ['value' => 'informative', 'label' => 'Informativa'],
                ],
                'message_max' => UpdateAttentionAutomationRequest::MESSAGE_MAX,
                'max_steps' => UpdateAttentionAutomationRequest::MAX_STEPS,
            ],
        ]);
    }

    /**
     * Normalize the stored escalation steps for the form, falling back to a
     * single step at `$fallbackMinutes` when none are stored (the old
     * single-threshold configuration).
     *
     * @param  mixed  $stored
     * @return list<array{minutes: int, priority: string, message: string|null}>
     */
    private function steps($stored, int $fallbackMinutes): array
    {
        if (! is_array($stored) || $stored === []) {
            $stored = [['minutes' => max(1, $fallbackMinutes), 'priority' => 'urgent', 'message' => null]];
        }

        $steps = [];

        foreach ($stored as $step) {
            if (! is_array($step)) {
                continue;
            }

            $steps[] = [
                'minutes' => max(1, (int) ($step['minutes'] ?? $fallbackMinutes)),
                'priority' => ($step['priority'] ?? null) === 'informative' ? 'informative' : 'urgent',
                'message' => is_string($step['message'] ?? null) && trim($step['message']) !== '' ? trim($step['message']) : null,
            ];
        }

        return $steps;
    }
}

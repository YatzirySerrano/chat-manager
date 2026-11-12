<?php

use App\Actions\Conversations\QueueOutboundMessage;
use App\Enums\AuditAction;
use App\Enums\ConversationEventType;
use App\Enums\ConversationReadiness;
use App\Enums\ConversationStatus;
use App\Enums\Permission;
use App\Http\Resources\Api\V1\ConversationResource as ApiConversationResource;
use App\Http\Resources\ConversationResource;
use App\Jobs\DeliverOutboundMessage;
use App\Models\AuditLog;
use App\Models\Connection;
use App\Models\Conversation;
use App\Models\ConversationCategory;
use App\Models\ConversationEvent;
use App\Models\Department;
use App\Models\ResponseCycle;
use App\Models\User;
use App\Notifications\NotificationKind;
use App\Services\Analytics\AnalyticsFilters;
use App\Services\Analytics\AnalyticsScope;
use App\Services\Analytics\OperationalAnalytics;
use App\Services\Conversations\PendingAttention;
use App\Services\Conversations\ResponseClock;
use App\Services\DashboardMetrics;
use App\Services\PlatformSettings;
use App\Services\ServiceRules;
use App\Services\Sla\SlaEvaluator;
use App\Support\Features;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Event as ScheduledEvent;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

/*
| FEATURE_PHASE_TWO (config/features.php, App\Support\Features): off by
| default, the application behaves as phase one. The phase-two endpoints do
| not exist, its permissions are not granted, the service rules resolve to
| their phase-one values, the SLA evaluator and the waiting queues are inert,
| and the payloads, reports and settings hide what belongs to phase two,
| while the stored data and configuration are kept for when it is turned on.
*/

beforeEach(function () {
    Queue::fake([DeliverOutboundMessage::class]);
    $this->travelTo(CarbonImmutable::parse('2026-11-09 16:00:00', 'UTC'));

    $this->department = Department::factory()->create(['name' => 'Soporte']);
    $this->admin = User::factory()->administrator()->inDepartments($this->department)->create(['name' => 'Ana']);
    $this->supervisor = User::factory()->supervisor()->inDepartments($this->department)->create(['name' => 'Sofía']);
    $this->connection = Connection::factory()->operational()->create(['default_department_id' => $this->department->id]);
});

function phaseTwoEnable(bool $enabled): void
{
    config(['features.phase_two' => $enabled]);
    app(ServiceRules::class)->flush();
}

/**
 * A conversation opened by a customer message through the real inbound
 * pipeline and attended by the agent.
 */
function phaseTwoAttendedConversation(Connection $connection, User $agent): Conversation
{
    $conversation = receiveWhatsapp($connection, '5215512345678', 'Hola', CarbonImmutable::now())->conversation;
    $conversation->forceFill(['assigned_user_id' => $agent->id, 'assigned_at' => now(), 'status' => ConversationStatus::Open])->save();
    app(ResponseClock::class)->sync($conversation);

    return $conversation;
}

function phaseTwoScheduled(string $command): ScheduledEvent
{
    return collect(app(Schedule::class)->events())
        ->first(fn (ScheduledEvent $event): bool => str_contains((string) $event->command, $command));
}

test('the flag is off by default and only read from the configuration', function () {
    expect(Features::phaseTwo())->toBeFalse();

    phaseTwoEnable(true);

    expect(Features::phaseTwo())->toBeTrue();
});

test('the phase-two endpoints do not exist while the flag is off and work when it is on', function () {
    $category = ConversationCategory::factory()->create();
    $conversation = Conversation::factory()->inDepartment($this->department)->create(['readiness' => ConversationReadiness::NeedsInfo]);

    $this->actingAs($this->admin)->put(route('departments.service-rules.update', $this->department), ['rules' => ['queue' => ['min_wait_minutes' => 7]]])->assertNotFound();
    $this->actingAs($this->admin)->put(route('categories.rules.update', $category), ['rules' => ['queue' => ['min_wait_minutes' => 5]]])->assertNotFound();
    $this->actingAs($this->admin)->postJson(route('conversations.readiness.update', $conversation), ['readiness' => 'ready', 'version' => 0])->assertNotFound();
    $this->actingAs($this->admin)->get(route('attention.index', ['tab' => 'sla']))->assertNotFound();

    expect($this->department->refresh()->service_rules)->toBeNull()
        ->and($category->refresh()->service_rules)->toBeNull()
        ->and($conversation->refresh()->readiness)->toBe(ConversationReadiness::NeedsInfo);

    phaseTwoEnable(true);

    $this->actingAs($this->admin)->put(route('departments.service-rules.update', $this->department), ['rules' => ['queue' => ['min_wait_minutes' => 7]]])->assertSessionHasNoErrors();
    $this->actingAs($this->admin)->put(route('categories.rules.update', $category), ['rules' => ['queue' => ['min_wait_minutes' => 5]]])->assertSessionHasNoErrors();
    $this->actingAs($this->admin)->get(route('attention.index', ['tab' => 'sla']))->assertOk();

    expect($this->department->refresh()->service_rules)->toBe(['queue.min_wait_minutes' => 7])
        ->and($category->refresh()->service_rules)->toBe(['queue.min_wait_minutes' => 5]);
});

test('the categories catalog and the attention module stay available while the flag is off', function () {
    $this->actingAs($this->admin)->post(route('categories.store'), ['name' => 'Facturación', 'color' => 'blue'])->assertSessionHasNoErrors();

    $this->actingAs($this->admin)->get(route('attention.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('attention/Index')
            ->where('tab', 'attention')
            ->missing('sla')
            ->missing('slaBreaches')
            ->missing('slaFilters')
            ->missing('slaOptions'));

    expect(ConversationCategory::query()->where('name', 'Facturación')->exists())->toBeTrue();
});

test('the phase-two permissions are not granted while the flag is off', function () {
    expect($this->supervisor->holdsPermission(Permission::ConfirmConversationReady))->toBeFalse()
        ->and($this->supervisor->holdsPermission(Permission::OverrideQueueRules))->toBeFalse()
        ->and($this->supervisor->effectivePermissions())->not->toContain(Permission::ConfirmConversationReady->value, Permission::OverrideQueueRules->value)
        // Everything else of the role is untouched.
        ->and($this->supervisor->holdsPermission(Permission::AssignConversations))->toBeTrue()
        ->and($this->admin->holdsPermission(Permission::SendTemplates))->toBeTrue()
        ->and($this->admin->holdsPermission(Permission::ManageCategories))->toBeTrue();

    phaseTwoEnable(true);

    expect($this->supervisor->holdsPermission(Permission::ConfirmConversationReady))->toBeTrue()
        ->and($this->supervisor->holdsPermission(Permission::OverrideQueueRules))->toBeTrue();
});

test('the service rules resolve to their phase-one values while the flag is off, keeping what is stored', function () {
    app(PlatformSettings::class)->update(['sla' => ['first_response_minutes' => 15, 'response_minutes' => 30, 'resolution_minutes' => 120]]);
    $this->department->forceFill(['service_rules' => ['intake.confirmation_required' => true, 'queue.min_wait_minutes' => 10, 'queue.chronological_order' => true]])->save();
    app(ServiceRules::class)->flush();

    $rules = app(ServiceRules::class)->forIds($this->department->id, null);

    expect($rules->confirmationRequired())->toBeFalse()
        ->and($rules->minWaitSeconds())->toBe(0)
        ->and($rules->chronologicalOrder())->toBeFalse()
        ->and($rules->firstResponseSeconds())->toBeNull()
        ->and($rules->responseSeconds())->toBeNull()
        // Legacy report objectives are read from the platform settings as before.
        ->and(app(PlatformSettings::class)->get('sla.first_response_minutes'))->toBe(15)
        ->and(app(PlatformSettings::class)->get('sla.resolution_minutes'))->toBe(120)
        ->and($this->department->refresh()->service_rules)->toBe(['intake.confirmation_required' => true, 'queue.min_wait_minutes' => 10, 'queue.chronological_order' => true]);

    phaseTwoEnable(true);
    $rules = app(ServiceRules::class)->forIds($this->department->id, null);

    expect($rules->confirmationRequired())->toBeTrue()
        ->and($rules->minWaitSeconds())->toBe(600)
        ->and($rules->chronologicalOrder())->toBeTrue()
        ->and($rules->firstResponseSeconds())->toBe(900)
        ->and($rules->responseSeconds())->toBe(1800);
});

test('a customer message keeps opening a cycle while the flag is off, without objective, and turning it on later raises no alert for it', function () {
    app(PlatformSettings::class)->update(['sla' => ['first_response_minutes' => 10, 'business_hours_only' => false]]);

    $conversation = phaseTwoAttendedConversation($this->connection, $this->admin);
    $cycle = ResponseCycle::query()->where('conversation_id', $conversation->id)->sole();

    expect($cycle->target_seconds)->toBeNull()
        ->and($cycle->due_at)->toBeNull()
        ->and($cycle->isActive())->toBeTrue();

    phaseTwoEnable(true);
    $this->travel(2)->hours();

    expect(app(SlaEvaluator::class)->run())->toBe(['breached' => 0, 'warned' => 0, 'reminded' => 0])
        ->and(DB::table('notifications')->where('kind', 'like', 'sla.%')->count())->toBe(0);
});

test('the SLA evaluator and the waiting queues are inert while the flag is off, also when run by hand', function () {
    $conversation = Conversation::factory()->inDepartment($this->department)->assignedTo($this->admin)->create(['connection_id' => $this->connection->id]);
    $cycle = ResponseCycle::factory()->withTarget(600)->create(['conversation_id' => $conversation->id]);
    $this->travel(1)->hours();

    expect(app(SlaEvaluator::class)->run())->toBe(['breached' => 0, 'warned' => 0, 'reminded' => 0])
        ->and($cycle->refresh()->breached_at)->toBeNull()
        ->and($cycle->warned_at)->toBeNull()
        ->and(phaseTwoScheduled('sla:evaluate')->filtersPass(app()))->toBeFalse()
        ->and(phaseTwoScheduled('conversations:offer-waiting-queues')->filtersPass(app()))->toBeFalse();

    $this->artisan('sla:evaluate')->expectsOutputToContain('0 vencidas')->assertSuccessful();
    $this->artisan('conversations:offer-waiting-queues')->expectsOutputToContain('desactivadas')->assertSuccessful();

    phaseTwoEnable(true);

    expect(phaseTwoScheduled('sla:evaluate')->filtersPass(app()))->toBeTrue()
        ->and(phaseTwoScheduled('conversations:offer-waiting-queues')->filtersPass(app()))->toBeTrue()
        ->and(app(SlaEvaluator::class)->run()['breached'])->toBe(1);
});

test('a targeted cycle opened while the flag was on ends without breach, event, audit or alert once it is off', function () {
    phaseTwoEnable(true);
    app(PlatformSettings::class)->update(['sla' => ['first_response_minutes' => 10, 'business_hours_only' => false]]);

    $conversation = phaseTwoAttendedConversation($this->connection, $this->admin);
    $cycle = ResponseCycle::query()->where('conversation_id', $conversation->id)->sole();

    expect($cycle->target_seconds)->toBe(600);

    phaseTwoEnable(false);
    $this->travel(30)->minutes();

    app(SlaEvaluator::class)->run();
    app(QueueOutboundMessage::class)->handle($conversation->fresh(), $this->admin, 'Con gusto le ayudo.', (string) Str::uuid());

    expect($cycle->refresh())
        ->outcome->toBe(ResponseCycle::OUTCOME_ANSWERED)
        ->breached_at->toBeNull()
        ->exceeded_seconds->toBeNull()
        ->and(ConversationEvent::query()->where('type', ConversationEventType::SlaBreached)->exists())->toBeFalse()
        ->and(AuditLog::query()->where('action', AuditAction::ConversationSlaBreached->value)->exists())->toBeFalse()
        ->and(DB::table('notifications')->where('kind', 'like', 'sla.%')->count())->toBe(0);
});

test('payloads report every request as ready and hide the queue clock and the SLA while the flag is off', function () {
    $conversation = Conversation::factory()->inDepartment($this->department)->assignedTo($this->admin)->create([
        'connection_id' => $this->connection->id,
        'readiness' => ConversationReadiness::NeedsInfo,
        'queued_at' => now()->subMinutes(5),
    ]);
    ResponseCycle::factory()->withTarget(600)->create(['conversation_id' => $conversation->id]);
    $request = Request::create('/');

    $web = (new ConversationResource($conversation->fresh()))->resolve($request);
    $api = (new ApiConversationResource($conversation->fresh()))->resolve($request);

    expect($web['readiness'])->toMatchArray(['value' => 'ready', 'tracked' => false])
        ->and($web['queued_at'])->toBeNull()
        ->and($web['sla'])->toBeNull()
        ->and($api['readiness'])->toBe('ready')
        ->and($api['queued_at'])->toBeNull();

    $this->actingAs($this->admin)->getJson(route('conversations.preview', $conversation))
        ->assertOk()
        ->assertJsonPath('conversation.readiness.value', 'ready');

    phaseTwoEnable(true);

    $web = (new ConversationResource($conversation->fresh()))->resolve($request);

    expect($web['readiness']['value'])->toBe('needs_info')
        ->and($web['queued_at'])->not->toBeNull()
        ->and($web['sla'])->not->toBeNull();
});

test('the phase-two exports are rejected while the flag is off', function (string $report) {
    $this->actingAs($this->admin)->post(route('exports.generate', [$report, 'xlsx']))->assertNotFound();

    phaseTwoEnable(true);

    $this->actingAs($this->admin)->post(route('exports.generate', [$report, 'xlsx']))->assertOk();
})->with(['agent-productivity', 'sla-compliance']);

test('reports and dashboard omit SLA compliance and productivity while the flag is off, on cache keys of their own', function () {
    $filters = fn (): AnalyticsFilters => AnalyticsFilters::from([], $this->admin, app(AnalyticsScope::class), 'America/Mexico_City');

    $report = app(OperationalAnalytics::class)->report($this->admin, $filters());
    $charts = app(DashboardMetrics::class)->chartsFor($this->admin);

    expect($report['sla'])->toBeNull()
        ->and($report['productivity'])->toBeNull()
        // Phase-one figures are still there.
        ->and($report['summary'])->toHaveKey('received')
        ->and($charts['sla'])->toBeNull();

    // Without flushing any cache: the other state is computed, never served from this one.
    phaseTwoEnable(true);

    expect(app(OperationalAnalytics::class)->report($this->admin, $filters())['sla'])->toBeArray()
        ->and(app(OperationalAnalytics::class)->report($this->admin, $filters())['productivity'])->toBeArray()
        ->and(app(DashboardMetrics::class)->chartsFor($this->admin)['sla'])->toBeArray();
});

test('settings only show and accept the two report objectives while the flag is off, keeping the stored phase-two values', function () {
    $settings = app(PlatformSettings::class);
    $settings->update([
        'sla' => ['response_minutes' => 30, 'warning_percent' => 70],
        'intake' => ['confirmation_required' => true],
        'queue' => ['min_wait_minutes' => 5],
        'sla_alerts' => ['agent_warning' => false],
    ]);

    $this->actingAs($this->admin)->get(route('admin.settings.edit'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('serviceRules', fn ($rules): bool => collect($rules)->keys()->all() === ServiceRules::PHASE_ONE_KEYS));

    $payload = array_replace_recursive($settings->defaults(), [
        'sla' => ['first_response_minutes' => 20, 'resolution_minutes' => 240, 'response_minutes' => 999999, 'warning_percent' => 1],
        'intake' => ['confirmation_required' => false],
        'queue' => ['min_wait_minutes' => 60, 'chronological_order' => true],
        'sla_alerts' => ['agent_warning' => true, 'reminder_limit' => 0],
    ]);

    $this->actingAs($this->admin)->put(route('admin.settings.update'), $payload)->assertSessionHasNoErrors();

    expect($settings->get('sla'))->toMatchArray(['first_response_minutes' => 20, 'resolution_minutes' => 240, 'response_minutes' => 30, 'warning_percent' => 70])
        ->and($settings->get('intake.confirmation_required'))->toBeTrue()
        ->and($settings->get('queue'))->toMatchArray(['min_wait_minutes' => 5, 'chronological_order' => false])
        ->and($settings->get('sla_alerts.agent_warning'))->toBeFalse();

    // The two objectives keep their bounds.
    $this->actingAs($this->admin)->put(route('admin.settings.update'), array_replace_recursive($payload, ['sla' => ['first_response_minutes' => 0]]))
        ->assertSessionHasErrors('sla.first_response_minutes');
});

test('the automatic SLA notification kinds are not offered while the flag is off', function () {
    expect(NotificationKind::SlaWarning->concerns($this->supervisor))->toBeFalse()
        ->and(NotificationKind::SlaBreach->concerns($this->supervisor))->toBeFalse();

    $this->actingAs($this->supervisor)->get(route('notifications.preferences.edit'))
        ->assertInertia(fn (Assert $page) => $page->where('kinds', fn ($kinds): bool => collect($kinds)->pluck('value')->intersect(['sla.warning', 'sla.breach'])->isEmpty()));

    phaseTwoEnable(true);

    expect(NotificationKind::SlaBreach->concerns($this->supervisor))->toBeTrue();
});

test('the interface receives the flag', function () {
    $this->actingAs($this->admin)->get(route('attention.index'))
        ->assertInertia(fn (Assert $page) => $page->where('features.phaseTwo', false));

    phaseTwoEnable(true);

    $this->actingAs($this->admin)->get(route('attention.index'))
        ->assertInertia(fn (Assert $page) => $page->where('features.phaseTwo', true));
});

test('the pending-attention control is off by default, independently of the flag, and a stored choice is respected', function () {
    expect(app(PlatformSettings::class)->defaults()['operations']['pending_block_enabled'])->toBeFalse()
        ->and(app(PendingAttention::class)->isEnabled())->toBeFalse();

    app(PlatformSettings::class)->update(['operations' => ['pending_block_enabled' => true]]);

    expect(app(PendingAttention::class)->isEnabled())->toBeTrue();
});

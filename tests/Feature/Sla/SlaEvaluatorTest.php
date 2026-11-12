<?php

use App\Actions\Conversations\AssignConversation;
use App\Actions\Conversations\QueueOutboundMessage;
use App\Enums\AuditAction;
use App\Enums\ConversationEventType;
use App\Events\Broadcasts\RealtimeSignal;
use App\Jobs\DeliverOutboundMessage;
use App\Models\AuditLog;
use App\Models\Connection;
use App\Models\Conversation;
use App\Models\ConversationEvent;
use App\Models\Department;
use App\Models\ResponseCycle;
use App\Models\User;
use App\Notifications\NotificationKind;
use App\Notifications\NotificationPreferences;
use App\Notifications\NotificationPresenter;
use App\Services\PlatformSettings;
use App\Services\Sla\SlaEvaluator;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Event as ScheduledEvent;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

/*
| `sla:evaluate`: warnings at warn_at, breaches at due_at and bounded
| reminders, each transition claimed atomically so it happens once whatever
| the number of runs; and the automatic notifications it raises.
*/

beforeEach(function () {
    // Phase-two behavior (FEATURE_PHASE_TWO): docs/reglas-de-servicio.md#fase-dos-detrás-de-feature_phase_two.
    config(['features.phase_two' => true]);
    Queue::fake([DeliverOutboundMessage::class]);
    $this->travelTo(CarbonImmutable::parse('2026-11-09 16:00:00', 'UTC'));

    $this->settings = app(PlatformSettings::class);
    $this->department = Department::factory()->create(['name' => 'Soporte']);
    $this->connection = Connection::factory()->operational()->create();
    $this->agent = User::factory()->agent()->inDepartments($this->department)->create(['name' => 'Ana']);
    $this->supervisor = User::factory()->supervisor()->inDepartments($this->department)->create(['name' => 'Sofía']);
});

/**
 * A conversation attended by the agent whose customer started waiting now,
 * with a 10 minute objective in calendar time (warning at 8 minutes).
 */
function evaluatedCycle(Department $department, Connection $connection, ?User $agent, ?int $targetSeconds = 600): ResponseCycle
{
    $factory = Conversation::factory()->inDepartment($department);
    $conversation = ($agent === null ? $factory : $factory->assignedTo($agent))->create([
        'connection_id' => $connection->id,
        'last_inbound_at' => now(),
    ]);

    $cycle = ResponseCycle::factory();

    return ($targetSeconds === null ? $cycle : $cycle->withTarget($targetSeconds))->create(['conversation_id' => $conversation->id]);
}

/**
 * @return array{breached: int, warned: int, reminded: int}
 */
function evaluateSlaNow(): array
{
    return app(SlaEvaluator::class)->run();
}

/**
 * @return list<string>
 */
function slaKindsOf(User $user): array
{
    return DB::table('notifications')->where('notifiable_id', $user->id)->orderBy('created_at')->orderBy('id')->pluck('kind')->all();
}

test('a request is warned at its warning instant and breached once its deadline passed', function () {
    $cycle = evaluatedCycle($this->department, $this->connection, $this->agent);

    $this->travel(7)->minutes();
    expect(evaluateSlaNow())->toBe(['breached' => 0, 'warned' => 0, 'reminded' => 0]);

    $this->travel(1)->minutes();
    expect(evaluateSlaNow())->toBe(['breached' => 0, 'warned' => 1, 'reminded' => 0])
        ->and($cycle->refresh()->warned_at->toIso8601String())->toBe('2026-11-09T16:08:00+00:00')
        ->and(slaKindsOf($this->agent))->toBe(['sla.warning'])
        // Supervision is not warned by default, only told about breaches.
        ->and(slaKindsOf($this->supervisor))->toBe([]);

    // Exactly at the deadline the objective is still met.
    $this->travel(2)->minutes();
    expect(evaluateSlaNow()['breached'])->toBe(0);

    $this->travel(1)->seconds();
    expect(evaluateSlaNow())->toBe(['breached' => 1, 'warned' => 0, 'reminded' => 0]);

    $cycle->refresh();

    expect($cycle->breached_at->toIso8601String())->toBe('2026-11-09T16:10:00+00:00')
        ->and($cycle)->breach_user_id->toBe($this->agent->id)->active->toBe(1)
        ->and(slaKindsOf($this->agent))->toBe(['sla.warning', 'sla.breach'])
        ->and(slaKindsOf($this->supervisor))->toBe(['sla.breach']);
});

test('running it again, or claiming the same cycle twice, never repeats a transition', function () {
    $cycle = evaluatedCycle($this->department, $this->connection, $this->agent);
    $evaluator = app(SlaEvaluator::class);

    $this->travel(8)->minutes();
    evaluateSlaNow();
    expect(evaluateSlaNow())->toBe(['breached' => 0, 'warned' => 0, 'reminded' => 0])
        ->and($evaluator->claimWarning($cycle->id, CarbonImmutable::now()))->toBeFalse();

    $this->travel(3)->minutes();
    evaluateSlaNow();
    expect(evaluateSlaNow())->toBe(['breached' => 0, 'warned' => 0, 'reminded' => 0])
        ->and($evaluator->claimBreach($cycle->id, CarbonImmutable::now()))->toBeFalse()
        ->and(slaKindsOf($this->agent))->toBe(['sla.warning', 'sla.breach'])
        ->and(slaKindsOf($this->supervisor))->toBe(['sla.breach'])
        ->and(DB::table('notifications')->sum('occurrences'))->toEqual(3);
});

test('of two claims of the same breach only one wins and leaves a single audit entry and timeline event', function () {
    $cycle = evaluatedCycle($this->department, $this->connection, $this->agent);
    $evaluator = app(SlaEvaluator::class);
    $this->travel(11)->minutes();

    $claims = [$evaluator->claimBreach($cycle->id, CarbonImmutable::now()), $evaluator->claimBreach($cycle->id, CarbonImmutable::now())];
    evaluateSlaNow();

    $event = ConversationEvent::query()->where('conversation_id', $cycle->conversation_id)->where('type', ConversationEventType::SlaBreached)->sole();
    $audit = AuditLog::query()->where('action', AuditAction::ConversationSlaBreached)->sole();

    expect($claims)->toBe([true, false])
        ->and($event)->actor_user_id->toBeNull()->from_user_id->toBe($this->agent->id)
        ->and($event->metadata)->toMatchArray(['cycle_id' => $cycle->id, 'reason' => 'first_response', 'target_seconds' => 600])
        ->and($audit->auditable_id)->toBe($cycle->conversation_id)
        ->and($audit->actor_name)->toBe('Sistema')
        ->and($audit->properties)->toMatchArray([
            'cycle_id' => $cycle->id,
            'objective' => 'Primera respuesta',
            'target_minutes' => 10,
            'responsible_user_id' => $this->agent->id,
            'detected_on' => 'evaluator',
        ]);
});

test('a breach refreshes the inboxes that list the conversation in real time', function () {
    $cycle = evaluatedCycle($this->department, $this->connection, $this->agent);
    $this->travel(11)->minutes();
    Event::fake([RealtimeSignal::class]);

    evaluateSlaNow();

    Event::assertDispatched(RealtimeSignal::class, fn (RealtimeSignal $signal): bool => $signal->event === 'inbox.changed'
        && ($signal->payload['conversation_id'] ?? null) === $cycle->conversation_id
        && ($signal->payload['reason'] ?? null) === 'sla_breached');
});

test('a late answer before the evaluator ran is recorded as a breach once, and the evaluator adds nothing', function () {
    $cycle = evaluatedCycle($this->department, $this->connection, $this->agent);
    $this->travel(15)->minutes();

    app(QueueOutboundMessage::class)->handle($cycle->conversation, $this->agent, 'Disculpe la demora.', (string) Str::uuid());
    $result = evaluateSlaNow();

    $cycle->refresh();

    expect($result)->toBe(['breached' => 0, 'warned' => 0, 'reminded' => 0])
        ->and($cycle)->active->toBeNull()->breach_user_id->toBe($this->agent->id)->exceeded_seconds->toBe(300)
        ->and($cycle->breached_at->toIso8601String())->toBe('2026-11-09T16:10:00+00:00')
        ->and(ConversationEvent::query()->where('type', ConversationEventType::SlaBreached)->count())->toBe(1)
        ->and(AuditLog::query()->where('action', AuditAction::ConversationSlaBreached)->sole()->properties['detected_on'])->toBe('answer')
        ->and(slaKindsOf($this->agent))->toBe([]);
});

test('a cycle found already past its deadline is announced as breached, never as about to expire', function () {
    evaluatedCycle($this->department, $this->connection, $this->agent);
    $this->travel(30)->minutes();

    expect(evaluateSlaNow())->toBe(['breached' => 1, 'warned' => 0, 'reminded' => 0])
        ->and(slaKindsOf($this->agent))->toBe(['sla.breach'])
        ->and(evaluateSlaNow())->toBe(['breached' => 0, 'warned' => 0, 'reminded' => 0]);
});

test('a breach that cannot be claimed is reported and the rest of the run goes on', function () {
    Exceptions::fake();
    $failing = evaluatedCycle($this->department, $this->connection, $this->agent);
    $breached = evaluatedCycle($this->department, $this->connection, $this->agent);
    $this->travel(3)->minutes();
    $warned = evaluatedCycle($this->department, $this->connection, $this->agent);

    ConversationEvent::creating(function (ConversationEvent $event) use ($failing): void {
        if ($event->conversation_id === $failing->conversation_id && $event->type === ConversationEventType::SlaBreached) {
            throw new RuntimeException('Claim failed.');
        }
    });

    $this->travel(8)->minutes();

    expect(evaluateSlaNow())->toBe(['breached' => 1, 'warned' => 1, 'reminded' => 0])
        ->and($failing->refresh()->breached_at)->toBeNull()
        ->and($breached->refresh()->breached_at)->not->toBeNull()
        ->and($warned->refresh()->warned_at)->not->toBeNull();

    Exceptions::assertReported(fn (RuntimeException $exception): bool => $exception->getMessage() === 'Claim failed.');
});

test('cycles without objective and cycles already ended raise nothing', function () {
    evaluatedCycle($this->department, $this->connection, $this->agent, null);
    $answered = evaluatedCycle($this->department, $this->connection, $this->agent);
    $answered->forceFill(['active' => null, 'outcome' => ResponseCycle::OUTCOME_ANSWERED, 'ended_at' => now(), 'responded_at' => now()])->save();

    $this->travel(2)->hours();

    expect(evaluateSlaNow())->toBe(['breached' => 0, 'warned' => 0, 'reminded' => 0])
        ->and(DB::table('notifications')->count())->toBe(0)
        ->and($answered->refresh()->breached_at)->toBeNull();
});

test('reminders of a breached request are sent at the configured pace and stop at the limit', function () {
    $this->settings->update(['sla_alerts' => ['reminder_minutes' => 5, 'reminder_limit' => 2]]);
    $cycle = evaluatedCycle($this->department, $this->connection, $this->agent);

    $this->travel(11)->minutes();
    evaluateSlaNow();

    $this->travel(4)->minutes();
    expect(evaluateSlaNow()['reminded'])->toBe(0);

    $this->travel(1)->minutes();
    expect(evaluateSlaNow()['reminded'])->toBe(1)
        ->and(evaluateSlaNow()['reminded'])->toBe(0);

    $this->travel(5)->minutes();
    expect(evaluateSlaNow()['reminded'])->toBe(1);

    $this->travel(60)->minutes();
    expect(evaluateSlaNow()['reminded'])->toBe(0)
        ->and($cycle->refresh()->reminders_count)->toBe(2)
        ->and(app(SlaEvaluator::class)->claimReminder($cycle->id, 1, CarbonImmutable::now()))->toBeFalse();

    // The agent keeps ONE unread notification that counted the reminders.
    $notification = $this->agent->notifications()->sole();

    expect($notification->getAttribute('kind'))->toBe('sla.breach')
        ->and((int) $notification->getAttribute('occurrences'))->toBe(3)
        ->and($notification->data['reminder'])->toBe(2);
});

test('no reminder is sent unless a frequency is configured, nor after the request is answered', function () {
    $cycle = evaluatedCycle($this->department, $this->connection, $this->agent);
    $this->travel(11)->minutes();
    evaluateSlaNow();

    $this->travel(3)->hours();
    expect(evaluateSlaNow()['reminded'])->toBe(0);

    $this->settings->update(['sla_alerts' => ['reminder_minutes' => 5]]);
    app(QueueOutboundMessage::class)->handle($cycle->conversation, $this->agent, 'Listo.', (string) Str::uuid());

    expect(evaluateSlaNow()['reminded'])->toBe(0);
});

test('reassigning keeps the deadline; the breach freezes who was responsible when it happened', function () {
    $beto = User::factory()->agent()->inDepartments($this->department)->create(['name' => 'Beto']);
    $admin = User::factory()->administrator()->create();
    $cycle = evaluatedCycle($this->department, $this->connection, $this->agent);
    $due = $cycle->due_at;

    $this->travel(5)->minutes();
    app(AssignConversation::class)->handle($cycle->conversation, $beto, $admin);

    $this->travel(6)->minutes();
    evaluateSlaNow();

    expect($cycle->refresh())->breach_user_id->toBe($beto->id)->assigned_user_id->toBe($beto->id)
        ->and($cycle->due_at->equalTo($due))->toBeTrue()
        ->and(slaKindsOf($beto))->toContain('sla.breach')
        ->and(slaKindsOf($this->agent))->not->toContain('sla.breach');

    app(AssignConversation::class)->handle($cycle->conversation->refresh(), $this->agent, $admin);

    expect($cycle->refresh())->breach_user_id->toBe($beto->id)->assigned_user_id->toBe($this->agent->id)->active->toBe(1);
});

test('the alert settings decide whether the agent and supervision are told', function () {
    $this->settings->update(['sla_alerts' => ['agent_warning' => false, 'agent_breach' => false, 'supervisor_warning' => true, 'supervisor_breach' => false]]);
    evaluatedCycle($this->department, $this->connection, $this->agent);

    $this->travel(8)->minutes();
    evaluateSlaNow();
    $this->travel(3)->minutes();
    evaluateSlaNow();

    expect(slaKindsOf($this->agent))->toBe([])
        ->and(slaKindsOf($this->supervisor))->toBe(['sla.warning']);
});

test('only people who supervise and can see the conversation are told, and the responsible agent only once', function () {
    $otherDepartment = Department::factory()->create(['name' => 'Ventas']);
    $outsider = User::factory()->supervisor()->inDepartments($otherDepartment)->create(['name' => 'Otro supervisor']);
    $inactive = User::factory()->supervisor()->inactive()->inDepartments($this->department)->create();
    $colleague = User::factory()->agent()->inDepartments($this->department)->create(['name' => 'Compañero']);
    $administrator = User::factory()->administrator()->create(['name' => 'Admin']);
    // The responsible person is a supervisor too.
    evaluatedCycle($this->department, $this->connection, $this->supervisor);

    $this->travel(11)->minutes();
    evaluateSlaNow();

    expect(slaKindsOf($this->supervisor))->toBe(['sla.breach'])
        ->and($this->supervisor->notifications()->sole()->data['supervisor'])->toBeFalse()
        ->and(slaKindsOf($administrator))->toBe(['sla.breach'])
        ->and(slaKindsOf($outsider))->toBe([])
        ->and(slaKindsOf($inactive))->toBe([])
        ->and(slaKindsOf($colleague))->toBe([]);
});

test('a request waiting in the queue has nobody responsible and only supervision is told', function () {
    $cycle = evaluatedCycle($this->department, $this->connection, null);

    $this->travel(11)->minutes();
    evaluateSlaNow();

    expect($cycle->refresh())->breach_user_id->toBeNull()->breached_at->not->toBeNull()
        ->and(slaKindsOf($this->supervisor))->toBe(['sla.breach'])
        ->and(slaKindsOf($this->agent))->toBe([]);
});

test('supervision gets one notification per department that gathers the breached requests', function () {
    $first = evaluatedCycle($this->department, $this->connection, $this->agent);
    evaluatedCycle($this->department, $this->connection, $this->agent);
    evaluatedCycle($this->department, $this->connection, null);

    $this->travel(11)->minutes();
    evaluateSlaNow();

    $notification = $this->supervisor->notifications()->sole();
    $presenter = app(NotificationPresenter::class);
    $presented = $presenter->presentMany([$notification], $this->supervisor)[0];

    expect((int) $notification->getAttribute('occurrences'))->toBe(3)
        ->and($notification->data['cycle_ids'])->toHaveCount(3)
        ->and($presented)
        ->title->toBe('3 solicitudes vencieron en Soporte')
        ->body->toBe('Aviso automático de SLA. 3 siguen sin respuesta. Revisa el monitoreo de SLA.')
        ->level->toBe('urgent')
        ->and($presented['url'])->toContain('tab=sla');

    app(QueueOutboundMessage::class)->handle($first->conversation, $this->agent, 'Listo.', (string) Str::uuid());

    expect($presenter->presentMany([$notification->refresh()], $this->supervisor)[0]['body'])
        ->toBe('Aviso automático de SLA. 2 siguen sin respuesta. Revisa el monitoreo de SLA.');
});

test('the notification text follows the request: once answered it no longer asks for an action', function () {
    $cycle = evaluatedCycle($this->department, $this->connection, $this->agent);
    $cycle->conversation->contact->forceFill(['name' => 'Laura Pérez'])->save();
    $this->travel(11)->minutes();
    evaluateSlaNow();

    $notification = $this->agent->notifications()->sole();
    $presenter = app(NotificationPresenter::class);
    $pending = $presenter->presentMany([$notification], $this->agent)[0];

    expect($pending)
        ->kind->toBe('sla.breach')
        ->level->toBe('urgent')
        ->title->toBe('Solicitud vencida: Laura Pérez')
        ->body->toBe('Aviso automático de SLA. Se agotó el tiempo máximo para la primera respuesta y el cliente sigue esperando.')
        ->url->toBe(route('conversations.show', $cycle->conversation_id))
        ->conversation_id->toBe($cycle->conversation_id);

    app(QueueOutboundMessage::class)->handle($cycle->conversation, $this->agent, 'Listo.', (string) Str::uuid());
    $resolved = app(NotificationPresenter::class)->presentMany([$notification->refresh()], $this->agent)[0];

    expect($resolved)
        ->level->toBe('informative')
        ->title->toBe('Solicitud vencida ya respondida: Laura Pérez')
        ->body->toBe('Aviso automático de SLA. Ana respondió después del plazo.')
        ->url->toBe(route('conversations.show', $cycle->conversation_id));
});

test('someone who lost access to the conversation gets no detail from its SLA notification', function () {
    $cycle = evaluatedCycle($this->department, $this->connection, $this->agent);
    $this->travel(11)->minutes();
    evaluateSlaNow();

    $other = Department::factory()->create();
    $cycle->conversation->forceFill(['department_id' => $other->id, 'assigned_user_id' => null])->save();

    $presented = app(NotificationPresenter::class)->presentMany([$this->agent->notifications()->sole()], $this->agent->refresh())[0];

    expect($presented)
        ->accessible->toBeFalse()
        ->url->toBeNull()
        ->title->toBe('Solicitud vencida (SLA)');
});

test('a user who turned the SLA kinds off receives nothing, and both kinds are offered as preferences', function () {
    app(NotificationPreferences::class)->save($this->agent, [
        'sla.warning' => ['in_app' => false, 'browser' => false, 'sound' => false],
        'sla.breach' => ['in_app' => false, 'browser' => false, 'sound' => false],
    ]);
    evaluatedCycle($this->department, $this->connection, $this->agent);

    $this->travel(8)->minutes();
    evaluateSlaNow();
    $this->travel(3)->minutes();
    evaluateSlaNow();

    expect(slaKindsOf($this->agent))->toBe([])
        ->and(slaKindsOf($this->supervisor))->toBe(['sla.breach'])
        ->and(NotificationKind::SlaBreach->concerns($this->agent))->toBeTrue()
        ->and(NotificationKind::SlaWarning->concerns($this->supervisor))->toBeTrue()
        ->and(NotificationKind::SlaBreach->level()->value)->toBe('urgent')
        ->and(NotificationKind::SlaWarning->level()->value)->toBe('informative')
        ->and(NotificationKind::SlaWarning->defaults()['sound'])->toBeTrue();
});

test('the command runs every minute without overlapping', function () {
    $event = collect(app(Schedule::class)->events())
        ->first(fn (ScheduledEvent $event): bool => str_contains((string) $event->command, 'sla:evaluate'));

    expect($event)->not->toBeNull()
        ->and($event->expression)->toBe('* * * * *')
        ->and($event->withoutOverlapping)->toBeTrue();

    $this->artisan('sla:evaluate')->assertSuccessful();
});

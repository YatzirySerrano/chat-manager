<?php

use App\Actions\Conversations\ReceiveInboundMessage;
use App\Actions\Conversations\ResumeAwaitingInformation;
use App\Enums\AuditAction;
use App\Enums\ConnectionChannel;
use App\Enums\ConnectionProvider;
use App\Enums\ConversationEventType;
use App\Enums\ConversationReadiness;
use App\Enums\ConversationStatus;
use App\Enums\DistributionMode;
use App\Enums\InboundMessageOutcome;
use App\Enums\MessageType;
use App\Models\AuditLog;
use App\Models\Connection;
use App\Models\Conversation;
use App\Models\Department;
use App\Models\User;
use App\Support\InboundMessage;
use App\Support\InboundMessageReceipt;
use App\Support\InboundSender;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/*
| «Esperando información del cliente»: a request a person returns for missing
| information leaves «Pendientes» (its list, its counter and the general
| inbox's pending branch) and waits in its own chip, sorted by the moment it
| was returned, without ever blocking the chronological order. The
| customer's next message (any type) brings it back to «Pendientes» in the
| same conversation, ready and with a new queue clock; a person may also
| confirm it complete. A request born «Necesita información» by the
| confirmation rule is not affected.
*/

beforeEach(function () {
    // Phase-two behavior (FEATURE_PHASE_TWO): docs/reglas-de-servicio.md#fase-dos-detrás-de-feature_phase_two.
    config(['features.phase_two' => true]);
    $this->travelTo(CarbonImmutable::parse('2026-11-27 17:20:00', 'UTC'));

    $this->department = Department::factory()->create(['name' => 'Créditos', 'service_rules' => ['queue.chronological_order' => true]]);
    $this->agent = User::factory()->agent()->available()->inDepartments($this->department)->create(['name' => 'Ana']);
    $this->supervisor = User::factory()->supervisor()->available()->inDepartments($this->department)->create(['name' => 'Sofía']);
    $this->connection = Connection::factory()->create();
    // Media downloads run on the sync queue: Meta is never called.
    Http::fake();
});

/**
 * A customer message through the real receipt (webhook path).
 */
function awaitingInbound(Connection $connection, string $waId, MessageType $type = MessageType::Text, ?string $body = 'Aquí está mi INE'): InboundMessageReceipt
{
    // A webhook has no signed-in user.
    auth()->forgetGuards();

    return app(ReceiveInboundMessage::class)->handle($connection, new InboundMessage(
        phoneNumberId: (string) $connection->phone_number_id,
        sender: new InboundSender(
            channel: ConnectionChannel::Whatsapp,
            provider: ConnectionProvider::MetaCloudApi,
            externalId: $waId,
            profileName: 'Laura Pérez',
            messageId: 'wamid.'.Str::random(20),
            occurredAt: CarbonImmutable::now(),
        ),
        type: $type,
        body: $type === MessageType::Text ? $body : null,
        content: $type === MessageType::Text ? null : ['id' => 'media-'.Str::random(8), 'mime_type' => 'image/jpeg'],
    ));
}

/**
 * A request of the department, born through the receipt, that a person then
 * accepted and returned for missing information (open → «Pendiente»).
 */
function returnedForInformation(object $test, string $waId = '5215512345678'): Conversation
{
    $conversation = awaitingInbound($test->connection, $waId, body: 'Quiero un crédito')->conversation;
    $conversation->forceFill([
        'department_id' => $test->department->id,
        'status' => ConversationStatus::Open,
        'assigned_user_id' => $test->agent->id,
        'assigned_at' => now(),
        'queued_at' => now(),
    ])->save();

    $test->actingAs($test->supervisor)
        ->postJson(route('conversations.readiness.update', $conversation), ['readiness' => 'needs_info', 'reason' => 'Falta la foto del INE'])
        ->assertRedirect();

    return $conversation->fresh();
}

/**
 * @param  array<string, mixed>  $query
 * @return list<int>
 */
function awaitingListed(object $test, User $viewer, array $query): array
{
    return collect($test->actingAs($viewer)->getJson(route('conversations.page', [...$query, 'limit' => 100]))->assertOk()->json('data'))
        ->pluck('id')->all();
}

test('marking a request as needing information takes it out of «Pendientes» into «Esperando información»', function () {
    $returned = returnedForInformation($this);
    $this->travel(2)->minutes();
    $newer = Conversation::factory()->inDepartment($this->department)->create(['queued_at' => now()]);

    expect($returned)
        ->status->toBe(ConversationStatus::Pending)
        ->assigned_user_id->toBeNull()
        ->readiness->toBe(ConversationReadiness::NeedsInfo)
        ->awaiting_info_at->toEqual(CarbonImmutable::parse('2026-11-27 17:20:00', 'UTC'));

    foreach ([$this->agent, $this->supervisor] as $viewer) {
        $counts = $this->actingAs($viewer)->get(route('conversations.index'))->viewData('page')['props']['counts'];

        expect(awaitingListed($this, $viewer, ['status' => 'pending']))->toBe([$newer->id])
            ->and(awaitingListed($this, $viewer, ['status' => 'awaiting_info']))->toBe([$returned->id])
            ->and($counts['pending'])->toBe(1)
            ->and($counts['awaiting_info'])->toBe(1);
    }

    // Nor in the general inbox's pending branch (its active list).
    expect(awaitingListed($this, $this->agent, ['status' => 'active']))->not->toContain($returned->id);

    // The row says since when it waits for the customer.
    $row = collect($this->actingAs($this->agent)->get(route('conversations.index', ['status' => 'awaiting_info']))->viewData('page')['props']['conversations']['data'])->firstWhere('id', $returned->id);
    expect($row['awaiting_info_at'])->toBe('2026-11-27T17:20:00+00:00');
});

test('a request waiting for the customer never blocks the chronological order', function () {
    returnedForInformation($this);
    $this->travel(2)->minutes();
    $newer = Conversation::factory()->inDepartment($this->department)->create(['queued_at' => now()]);

    $this->actingAs($this->agent)->postJson(route('conversations.accept', $newer))->assertRedirect();

    expect($newer->fresh())->status->toBe(ConversationStatus::Open)->assigned_user_id->toBe($this->agent->id);
});

test('«Esperando información» is sorted and paged by the moment each request was returned', function () {
    $first = returnedForInformation($this, '5215500000001');
    $this->travel(5)->minutes();
    $second = returnedForInformation($this, '5215500000002');
    // Latest activity in the reverse order: the list must not follow it.
    $first->forceFill(['last_message_at' => now()->addMinute()])->save();

    $ids = [];
    $after = null;

    do {
        $page = $this->actingAs($this->agent)
            ->getJson(route('conversations.page', ['status' => 'awaiting_info', 'sort' => 'oldest', 'limit' => 1, ...($after === null ? [] : ['after' => $after])]))
            ->json();
        $ids = [...$ids, ...array_column($page['data'], 'id')];
        $after = $page['next_cursor'];
    } while ($page['has_more']);

    expect($ids)->toBe([$first->id, $second->id]);
});

test('the customer writing again brings it back to «Pendientes», ready, with a new queue clock, in the same conversation', function (MessageType $type) {
    $this->department->forceFill(['distribution_mode' => DistributionMode::Shared])->save();
    $returned = returnedForInformation($this);
    $this->travel(30)->minutes();
    $conversations = Conversation::query()->count();

    $receipt = awaitingInbound($this->connection, '5215512345678', $type);

    expect($receipt->outcome)->toBe(InboundMessageOutcome::Appended)
        ->and($receipt->conversation->id)->toBe($returned->id)
        ->and(Conversation::query()->count())->toBe($conversations)
        ->and($returned->fresh())
        ->status->toBe(ConversationStatus::Pending)
        ->assigned_user_id->toBeNull()
        ->readiness->toBe(ConversationReadiness::Ready)
        ->awaiting_info_at->toBeNull()
        ->queued_at->toEqual(CarbonImmutable::parse('2026-11-27 17:50:00', 'UTC'));

    $event = $returned->events()->where('type', ConversationEventType::ReadinessConfirmed)->sole();
    $audit = AuditLog::query()->where('action', AuditAction::ConversationReadinessConfirmed->value)->sole();

    expect($event->actor_user_id)->toBeNull()
        ->and($event->note)->toBe(ResumeAwaitingInformation::NOTE)
        ->and($event->metadata)->toBe(['previous' => 'needs_info', 'new' => 'ready', 'trigger' => 'customer_info'])
        ->and($audit->user_id)->toBeNull()
        ->and($audit->actor_name)->toBe('Sistema')
        ->and(awaitingListed($this, $this->agent, ['status' => 'pending']))->toBe([$returned->id])
        ->and(awaitingListed($this, $this->agent, ['status' => 'awaiting_info']))->toBe([]);

    // It takes its place behind the requests that arrived meanwhile.
    $this->actingAs($this->agent)->postJson(route('conversations.accept', $returned))->assertRedirect();
})->with([
    'text' => [MessageType::Text],
    'image' => [MessageType::Image],
]);

test('coming back, it waits behind the requests that arrived while it was waiting for the customer', function () {
    $returned = returnedForInformation($this);
    $this->travel(2)->minutes();
    $newer = Conversation::factory()->inDepartment($this->department)->create(['queued_at' => now()]);
    $this->travel(5)->minutes();

    awaitingInbound($this->connection, '5215512345678');

    $this->actingAs($this->agent)->postJson(route('conversations.accept', $returned))->assertStatus(409)->assertJsonPath('code', 'queue_order');
    $this->actingAs($this->agent)->postJson(route('conversations.accept', $newer))->assertRedirect();
});

test('in an automatic department the request that comes back is offered to the queue again', function () {
    $returned = returnedForInformation($this);
    $this->department->forceFill(['distribution_mode' => DistributionMode::Automatic])->save();

    awaitingInbound($this->connection, '5215512345678');

    expect($returned->fresh())->status->toBe(ConversationStatus::Open)->assigned_user_id->not->toBeNull();
});

test('confirming it complete by hand clears the wait and restarts its queue clock', function () {
    $returned = returnedForInformation($this);
    $this->travel(10)->minutes();

    $this->actingAs($this->supervisor)
        ->postJson(route('conversations.readiness.update', $returned), ['readiness' => 'ready'])
        ->assertRedirect();

    expect($returned->fresh())
        ->readiness->toBe(ConversationReadiness::Ready)
        ->awaiting_info_at->toBeNull()
        ->queued_at->toEqual(CarbonImmutable::parse('2026-11-27 17:30:00', 'UTC'))
        ->and(awaitingListed($this, $this->agent, ['status' => 'pending']))->toBe([$returned->id]);
});

test('a request returned while it waited in the queue also waits for the customer', function () {
    $pending = Conversation::factory()->inDepartment($this->department)->create(['queued_at' => now()]);

    $this->actingAs($this->supervisor)
        ->postJson(route('conversations.readiness.update', $pending), ['readiness' => 'needs_info', 'reason' => 'Falta el comprobante'])
        ->assertRedirect();

    expect($pending->fresh()->awaiting_info_at)->toEqual(now())
        ->and(awaitingListed($this, $this->agent, ['status' => 'awaiting_info']))->toBe([$pending->id]);
});

test('a request born needing information by the confirmation rule keeps waiting for a person', function () {
    $this->department->forceFill(['service_rules' => ['intake.confirmation_required' => true]])->save();
    $born = awaitingInbound($this->connection, '5215512345678', body: 'Hola')->conversation;
    $born->forceFill(['department_id' => $this->department->id, 'readiness' => ConversationReadiness::NeedsInfo, 'queued_at' => null])->save();
    $this->travel(5)->minutes();

    awaitingInbound($this->connection, '5215512345678');

    expect($born->fresh())
        ->readiness->toBe(ConversationReadiness::NeedsInfo)
        ->awaiting_info_at->toBeNull()
        ->queued_at->toBeNull()
        ->and($born->events()->where('type', ConversationEventType::ReadinessConfirmed)->exists())->toBeFalse()
        ->and(awaitingListed($this, $this->supervisor, ['status' => 'pending']))->toContain($born->id)
        ->and(awaitingListed($this, $this->supervisor, ['status' => 'awaiting_info']))->toBe([]);
});

test('taking a waiting request clears the wait', function () {
    $returned = returnedForInformation($this);
    $admin = User::factory()->administrator()->available()->create();

    $this->actingAs($admin)->postJson(route('conversations.assign', $returned), [
        'user_id' => $this->agent->id,
        'version' => $returned->version,
        'override_reason' => 'El cliente ya envió la foto por correo',
    ])->assertRedirect();

    expect($returned->fresh())->status->toBe(ConversationStatus::Open)->awaiting_info_at->toBeNull();
});

test('the open conversation tells what the customer was asked for', function () {
    $returned = returnedForInformation($this);

    $conversation = $this->actingAs($this->agent)->get(route('conversations.index', ['c' => $returned->id, 'status' => 'awaiting_info']))->viewData('page')['props']['conversation'];

    expect($conversation['awaiting_info_at'])->toBe('2026-11-27T17:20:00+00:00')
        ->and($conversation['awaiting_info_missing'])->toBe(['Falta la foto del INE']);
});

test('the backfill stamps the requests a person already returned, and only them', function () {
    $marked = Conversation::factory()->inDepartment($this->department)->create([
        'readiness' => ConversationReadiness::NeedsInfo,
        'readiness_changed_by_user_id' => $this->supervisor->id,
        'readiness_changed_at' => CarbonImmutable::parse('2026-11-27 10:00:00', 'UTC'),
        'queued_at' => null,
    ]);
    $born = Conversation::factory()->inDepartment($this->department)->create(['readiness' => ConversationReadiness::NeedsInfo, 'queued_at' => null]);
    $attended = Conversation::factory()->inDepartment($this->department)->assignedTo($this->agent)->create([
        'readiness' => ConversationReadiness::NeedsInfo,
        'readiness_changed_by_user_id' => $this->supervisor->id,
    ]);

    $migration = require database_path('migrations/2026_11_27_110000_backfill_awaiting_info_at_on_conversations.php');
    $migration->up();
    $migration->up();

    expect($marked->fresh()->awaiting_info_at)->toEqual(CarbonImmutable::parse('2026-11-27 10:00:00', 'UTC'))
        ->and($born->fresh()->awaiting_info_at)->toBeNull()
        ->and($attended->fresh()->awaiting_info_at)->toBeNull()
        ->and(DB::table('conversations')->whereNotNull('awaiting_info_at')->count())->toBe(1);
});

test('«Pendientes» opens oldest arrival first and pages in that order; the one that came back goes to the bottom', function () {
    $returned = returnedForInformation($this, '5215500000009');
    $this->travel(1)->minutes();
    $older = Conversation::factory()->inDepartment($this->department)->create(['queued_at' => now(), 'last_message_at' => now()->addMinutes(30)]);
    $this->travel(2)->minutes();
    $newer = Conversation::factory()->inDepartment($this->department)->create(['queued_at' => now(), 'last_message_at' => now()]);
    $this->travel(5)->minutes();

    // The customer sends what was missing: it re-enters with the time of that message.
    awaitingInbound($this->connection, '5215500000009');

    // Default order of «Pendientes» (no sort asked): order of arrival, not latest activity.
    expect(awaitingListed($this, $this->agent, ['status' => 'pending']))->toBe([$older->id, $newer->id, $returned->id]);

    $ids = [];
    $after = null;

    do {
        $page = $this->actingAs($this->agent)
            ->getJson(route('conversations.page', ['status' => 'pending', 'limit' => 1, ...($after === null ? [] : ['after' => $after])]))
            ->json();
        $ids = [...$ids, ...array_column($page['data'], 'id')];
        $after = $page['next_cursor'];
    } while ($page['has_more']);

    expect($ids)->toBe([$older->id, $newer->id, $returned->id]);

    // Asked explicitly, the newest arrival can still go on top.
    expect(awaitingListed($this, $this->agent, ['status' => 'pending', 'sort' => 'recent']))->toBe([$returned->id, $newer->id, $older->id]);
});

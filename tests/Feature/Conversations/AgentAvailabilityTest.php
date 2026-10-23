<?php

use App\Actions\Conversations\AcceptConversation;
use App\Actions\Conversations\AssignAutomatically;
use App\Actions\Conversations\ChangeAgentAvailability;
use App\Enums\AgentAvailability;
use App\Enums\ConversationEventType;
use App\Enums\ConversationStatus;
use App\Enums\DistributionMode;
use App\Enums\Permission;
use App\Models\Connection;
use App\Models\Conversation;
use App\Models\Department;
use App\Models\User;
use App\Services\PlatformSettings;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Inertia\Testing\AssertableInertia as Assert;

/*
| Availability policy. Available: may accept pending conversations and takes
| part in automatic distribution. Unavailable: neither, but keeps and manages
| the conversations already assigned. Handing a conversation by hand to an
| unavailable agent is an exception reserved to users who can assign, with
| explicit confirmation, recorded in the event.
*/

beforeEach(function () {
    $this->department = Department::factory()->create(['name' => 'Ventas']);
    $this->admin = User::factory()->administrator()->create();
    $this->unavailable = User::factory()->agent()->inDepartments($this->department)->create(['name' => 'Ana', 'availability' => AgentAvailability::Unavailable]);
});

test('an unavailable agent sees the queue but cannot accept from it', function () {
    $conversation = Conversation::factory()->inDepartment($this->department)->create();

    $this->actingAs($this->unavailable)
        ->get(route('conversations.index', ['view' => 'queue']))
        ->assertInertia(fn (Assert $page) => $page
            ->where('availability.value', 'unavailable')
            ->where('conversations.data.0.id', $conversation->id));

    $this->actingAs($this->unavailable)
        ->from(route('conversations.index', ['view' => 'queue']))
        ->post(route('conversations.accept', $conversation))
        ->assertRedirect(route('conversations.index', ['view' => 'queue']))
        ->assertSessionHasErrors(['conversation' => 'Estás como No disponible. Cambia tu disponibilidad a Disponible para aceptar conversaciones.']);

    $this->actingAs($this->unavailable)
        ->postJson(route('conversations.accept', $conversation))
        ->assertStatus(409)
        ->assertExactJson(['message' => 'Estás como No disponible. Cambia tu disponibilidad a Disponible para aceptar conversaciones.']);

    expect($conversation->refresh())
        ->status->toBe(ConversationStatus::Pending)
        ->assigned_user_id->toBeNull()
        ->and($conversation->events()->count())->toBe(0);
});

test('after switching to available the agent accepts', function () {
    $conversation = Conversation::factory()->inDepartment($this->department)->create();

    $this->actingAs($this->unavailable)->put(route('conversations.availability.update'), ['availability' => 'available'])->assertSessionHasNoErrors();
    $this->actingAs($this->unavailable)->post(route('conversations.accept', $conversation))->assertSessionHasNoErrors();

    expect($conversation->refresh()->assigned_user_id)->toBe($this->unavailable->id);
});

test('an unavailable agent keeps managing the conversations already assigned', function () {
    $conversation = Conversation::factory()->inDepartment($this->department)->assignedTo($this->unavailable)->create();

    $this->actingAs($this->unavailable)
        ->get(route('conversations.index', ['c' => $conversation->id]))
        ->assertInertia(fn (Assert $page) => $page
            ->where('availability.value', 'unavailable')
            ->where('conversation.can.reply', true)
            ->where('conversation.can.close', true));

    $this->actingAs($this->unavailable)->post(route('conversations.hold', $conversation), ['version' => 0])->assertSessionHasNoErrors();
    $this->actingAs($this->unavailable)->post(route('conversations.resume', $conversation), ['version' => 1])->assertSessionHasNoErrors();
    $this->actingAs($this->unavailable)->post(route('conversations.close', $conversation), ['version' => 2, 'reason' => 'Resuelto'])->assertSessionHasNoErrors();

    expect($conversation->refresh()->status)->toBe(ConversationStatus::Closed);
});

test('assigning to an unavailable agent requires an explicit confirmation', function (string $route, array $input) {
    $conversation = Conversation::factory()->inDepartment($this->department)->create();

    $this->actingAs($this->admin)
        ->from(route('conversations.show', $conversation))
        ->post(route($route, $conversation), [...$input, 'user_id' => $this->unavailable->id, 'version' => 0])
        ->assertSessionHasErrors(['user_id' => 'Ana está como No disponible. Confirma que quieres asignarle la conversación de todas formas; su disponibilidad no cambiará.']);

    expect($conversation->refresh()->assigned_user_id)->toBeNull()
        ->and($conversation->events()->count())->toBe(0);

    $this->actingAs($this->admin)
        ->post(route($route, $conversation), [...$input, 'user_id' => $this->unavailable->id, 'version' => 0, 'confirm_unavailable' => true])
        ->assertSessionHasNoErrors();

    $event = $conversation->events()->sole();

    expect($conversation->refresh())
        ->assigned_user_id->toBe($this->unavailable->id)
        ->status->toBe(ConversationStatus::Open)
        ->and($event)
        ->actor_user_id->toBe($this->admin->id)
        ->to_user_id->toBe($this->unavailable->id)
        ->metadata->toBe(['assigned_while_unavailable' => true])
        ->and($this->unavailable->refresh()->availability)->toBe(AgentAvailability::Unavailable);
})->with([
    'assign' => ['conversations.assign', []],
    'transfer' => ['conversations.transfer', ['reason' => 'Cliente frecuente de Ana']],
]);

test('assigning to an available agent needs no confirmation and records no exception', function () {
    $available = User::factory()->agent()->available()->inDepartments($this->department)->create();
    $conversation = Conversation::factory()->inDepartment($this->department)->create();

    $this->actingAs($this->admin)
        ->post(route('conversations.assign', $conversation), ['user_id' => $available->id, 'version' => 0])
        ->assertSessionHasNoErrors();

    expect($conversation->events()->sole()->metadata)->toBeNull();
});

test('only users who can assign may hand a conversation to an unavailable colleague', function () {
    $responsible = User::factory()->agent()->available()->inDepartments($this->department)->create();
    $conversation = Conversation::factory()->inDepartment($this->department)->assignedTo($responsible)->create();

    $this->actingAs($responsible)
        ->from(route('conversations.show', $conversation))
        ->post(route('conversations.transfer', $conversation), ['user_id' => $this->unavailable->id, 'confirm_unavailable' => true, 'version' => 0])
        ->assertSessionHasErrors(['user_id' => 'Ana está como No disponible. Transfiérela a la cola del departamento o pide a quien asigna conversaciones que lo autorice.']);

    expect($conversation->refresh()->assigned_user_id)->toBe($responsible->id)
        ->and($conversation->events()->count())->toBe(0);
});

test('the destinations show each agent\'s availability and whether they reached their limit', function () {
    app(PlatformSettings::class)->update(['operations' => ['max_open_conversations_per_agent' => 1]]);
    $available = User::factory()->agent()->available()->inDepartments($this->department)->create();
    Conversation::factory()->inDepartment($this->department)->assignedTo($this->unavailable)->create();
    $conversation = Conversation::factory()->inDepartment($this->department)->create();

    $this->actingAs($this->admin)
        ->getJson(route('conversations.destinations', $conversation))
        ->assertOk()
        ->assertJsonPath('allows_exceptions', true)
        ->assertJsonPath('departments.0.agents', fn ($agents) => collect($agents)->keyBy('id')->map(fn ($agent) => [$agent['availability'], $agent['at_capacity']])->only([$this->unavailable->id, $available->id])->all() == [
            $this->unavailable->id => ['unavailable', true],
            $available->id => ['available', false],
        ]);
});

test('the destinations tell someone who cannot assign that unavailable colleagues are not an option', function () {
    $responsible = User::factory()->agent()->available()->inDepartments($this->department)->create();
    $conversation = Conversation::factory()->inDepartment($this->department)->assignedTo($responsible)->create();

    $this->actingAs($responsible)
        ->getJson(route('conversations.destinations', $conversation))
        ->assertOk()
        ->assertJsonPath('allows_exceptions', false);
});

test('inactive users and non-members never receive a conversation, confirmed or not', function (Closure $makeTarget, string $message) {
    $conversation = Conversation::factory()->inDepartment($this->department)->create();
    $target = $makeTarget($this);

    $this->actingAs($this->admin)
        ->from(route('conversations.show', $conversation))
        ->post(route('conversations.assign', $conversation), ['user_id' => $target->id, 'version' => 0, 'confirm_unavailable' => true])
        ->assertSessionHasErrors(['conversation' => str_replace(':name', $target->name, $message)]);

    expect($conversation->refresh()->assigned_user_id)->toBeNull();
})->with([
    'deactivated' => [fn ($test) => User::factory()->agent()->inactive()->inDepartments($test->department)->create(), 'La cuenta de :name está eliminada.'],
    'not a member' => [fn ($test) => User::factory()->agent()->inDepartments(Department::factory()->create())->create(), ':name no pertenece al departamento Ventas.'],
]);

test('automatic distribution only picks available, active, member agents that can attend', function () {
    $department = Department::factory()->distribution(DistributionMode::Automatic)->create();
    User::factory()->agent()->inDepartments($department)->create(['availability' => AgentAvailability::Unavailable]);
    User::factory()->agent()->available()->inactive()->inDepartments($department)->create();
    User::factory()->agent()->available()->inDepartments(Department::factory()->create())->create();
    conversationUser([Permission::ViewConversations], departments: [$department])->forceFill(['availability' => AgentAvailability::Available])->save();
    $conversation = Conversation::factory()->inDepartment($department)->create();

    $assigned = DB::transaction(fn () => app(AssignAutomatically::class)->handle(Conversation::query()->lockForUpdate()->findOrFail($conversation->id)));

    expect($assigned)->toBeNull()
        ->and($conversation->refresh()->status)->toBe(ConversationStatus::Pending)
        ->and($conversation->events()->count())->toBe(0);

    $eligible = User::factory()->agent()->available()->inDepartments($department)->create();

    $assigned = DB::transaction(fn () => app(AssignAutomatically::class)->handle(Conversation::query()->lockForUpdate()->findOrFail($conversation->id)));

    expect($assigned?->id)->toBe($eligible->id)
        ->and($conversation->events()->sole()->type)->toBe(ConversationEventType::AutoAssigned);
});

test('an already assigned conversation is never distributed again', function () {
    $department = Department::factory()->distribution(DistributionMode::Automatic)->create();
    $owner = User::factory()->agent()->available()->inDepartments($department)->create();
    User::factory()->agent()->available()->inDepartments($department)->create();
    $conversation = Conversation::factory()->inDepartment($department)->assignedTo($owner)->create();

    $assigned = DB::transaction(fn () => app(AssignAutomatically::class)->handle(Conversation::query()->lockForUpdate()->findOrFail($conversation->id)));

    expect($assigned)->toBeNull()
        ->and($conversation->refresh()->assigned_user_id)->toBe($owner->id)
        ->and($conversation->events()->count())->toBe(0);
});

test('shared inbox departments never assign automatically, even with available agents', function () {
    User::factory()->agent()->available()->inDepartments($this->department)->create();
    $connection = Connection::factory()->create(['default_department_id' => $this->department->id]);

    $conversation = receiveWhatsapp($connection)->conversation;

    expect($this->department->distribution_mode)->toBe(DistributionMode::Shared)
        ->and($conversation->status)->toBe(ConversationStatus::Pending)
        ->and($conversation->assigned_user_id)->toBeNull();
});

test('going unavailable keeps every assigned conversation and moves nothing', function () {
    $department = Department::factory()->distribution(DistributionMode::Automatic)->create();
    $agent = User::factory()->agent()->available()->inDepartments($department)->create();
    User::factory()->agent()->available()->inDepartments($department)->create();
    $owned = Conversation::factory()->count(2)->inDepartment($department)->assignedTo($agent)->create();

    app(ChangeAgentAvailability::class)->handle($agent, AgentAvailability::Unavailable);

    expect(Conversation::query()->whereKey($owned->modelKeys())->pluck('assigned_user_id')->unique()->all())->toBe([$agent->id])
        ->and(Conversation::query()->whereKey($owned->modelKeys())->withCount('events')->get()->sum('events_count'))->toBe(0);
});

test('reassigning and transferring never extend the customer service window', function () {
    $available = User::factory()->agent()->available()->inDepartments($this->department)->create();
    $support = Department::factory()->create();
    $lastInbound = now()->subHours(20)->startOfSecond();
    $conversation = Conversation::factory()->inDepartment($this->department)->assignedTo($available)->create(['last_inbound_at' => $lastInbound]);

    $this->actingAs($this->admin)->post(route('conversations.assign', $conversation), ['user_id' => $this->unavailable->id, 'version' => 0, 'confirm_unavailable' => true])->assertSessionHasNoErrors();
    $this->actingAs($this->admin)->post(route('conversations.transfer', $conversation), ['department_id' => $support->id, 'version' => 1])->assertSessionHasNoErrors();

    expect($conversation->refresh()->last_inbound_at->toDateTimeString())->toBe($lastInbound->toDateTimeString());
});

test('expected conflicts are logged as information, never as errors', function () {
    Log::spy();
    $conversation = Conversation::factory()->inDepartment($this->department)->create();

    $this->actingAs($this->unavailable)
        ->postJson(route('conversations.accept', $conversation))
        ->assertStatus(409);

    Log::shouldHaveReceived('info')->once()->withArgs(fn (string $message, array $context) => $context === [
        'reason' => 'Estás como No disponible. Cambia tu disponibilidad a Disponible para aceptar conversaciones.',
        'conversation_id' => $conversation->id,
        'user_id' => $this->unavailable->id,
        'route' => 'conversations.accept',
    ]);
    Log::shouldNotHaveReceived('error');
});

test('a deadlock answered as a conflict is logged as a warning, not as an error', function () {
    Log::spy();
    $conversation = Conversation::factory()->inDepartment($this->department)->create();
    $this->mock(AcceptConversation::class)->shouldReceive('handle')->andThrow(
        new QueryException('mariadb', 'update conversations', [], new PDOException('SQLSTATE[40001]: Serialization failure: 1213 Deadlock found when trying to get lock')),
    );

    $this->actingAs($this->unavailable)->postJson(route('conversations.accept', $conversation))->assertStatus(409);

    Log::shouldHaveReceived('warning')->once();
    Log::shouldNotHaveReceived('error');
});

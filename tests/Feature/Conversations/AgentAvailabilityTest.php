<?php

use App\Actions\Conversations\AcceptConversation;
use App\Actions\Conversations\AssignAutomatically;
use App\Enums\AgentAvailability;
use App\Enums\ConversationEventType;
use App\Enums\ConversationStatus;
use App\Enums\DataScope;
use App\Enums\DistributionMode;
use App\Enums\Permission;
use App\Models\Connection;
use App\Models\Conversation;
use App\Models\Department;
use App\Models\Role;
use App\Models\User;
use App\Services\PlatformSettings;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Inertia\Testing\AssertableInertia as Assert;

/*
| Who may attend, now that the manual availability ("Disponible / No
| disponible") was removed (DESIGN decision 2). Accepting, assigning,
| transferring and the automatic distribution only depend on: active account,
| active role, permission, department membership, active department and the
| capacity limit. The legacy users.availability column is never read.
*/

beforeEach(function () {
    $this->department = Department::factory()->create(['name' => 'Ventas']);
    $this->admin = User::factory()->administrator()->create();
    // The legacy column says "unavailable" (the old default): it must not matter.
    $this->agent = User::factory()->agent()->inDepartments($this->department)->create(['name' => 'Ana', 'availability' => AgentAvailability::Unavailable]);
});

test('an agent accepts from the queue without declaring any availability', function () {
    $conversation = Conversation::factory()->inDepartment($this->department)->create();

    $this->actingAs($this->agent)
        ->get(route('conversations.index', ['view' => 'queue']))
        ->assertInertia(fn (Assert $page) => $page
            ->missing('availability')
            ->where('conversations.data.0.id', $conversation->id)
            ->where('conversations.data.0.can.accept', true));

    $this->actingAs($this->agent)->post(route('conversations.accept', $conversation))->assertSessionHasNoErrors();

    expect($conversation->refresh())
        ->assigned_user_id->toBe($this->agent->id)
        ->status->toBe(ConversationStatus::Open)
        ->and($conversation->events()->sole()->type)->toBe(ConversationEventType::Accepted);
});

test('the availability endpoint no longer exists', function () {
    $response = $this->actingAs($this->agent)->putJson('/conversations/availability', ['availability' => 'available']);

    expect($response->status())->toBeIn([404, 405])
        ->and($this->agent->refresh()->availability)->toBe(AgentAvailability::Unavailable);
});

test('accepting still respects the capacity limit', function () {
    app(PlatformSettings::class)->update(['operations' => ['max_open_conversations_per_agent' => 1]]);
    Conversation::factory()->inDepartment($this->department)->assignedTo($this->agent)->create();
    $conversation = Conversation::factory()->inDepartment($this->department)->create();

    $this->actingAs($this->agent)
        ->postJson(route('conversations.accept', $conversation))
        ->assertStatus(409)
        ->assertJsonPath('message', 'Ya atiendes el máximo de 1 conversaciones permitido. Cierra o libera alguna antes de aceptar otra.');

    expect($conversation->refresh()->assigned_user_id)->toBeNull();
});

test('only active members with an active role and the accept permission can accept', function (Closure $makeAgent) {
    $conversation = Conversation::factory()->inDepartment($this->department)->create();
    $agent = $makeAgent($this);

    expect(fn () => app(AcceptConversation::class)->handle($conversation, $agent))->toThrow(AuthorizationException::class)
        ->and($conversation->refresh()->assigned_user_id)->toBeNull()
        ->and($conversation->events()->count())->toBe(0);
})->with([
    'deleted account' => [fn ($test) => User::factory()->agent()->available()->inactive()->inDepartments($test->department)->create()],
    'inactive role' => [fn ($test) => User::factory()->withRole(Role::factory()->inactive()->scope(DataScope::Assigned)->withPermissions([Permission::ViewConversations, Permission::ReplyConversations, Permission::AcceptConversations])->create())->available()->inDepartments($test->department)->create()],
    'without accept permission' => [fn ($test) => conversationUser([Permission::ViewConversations, Permission::ReplyConversations], departments: [$test->department])],
    'not a member' => [fn ($test) => User::factory()->agent()->available()->inDepartments(Department::factory()->create())->create()],
]);

test('assigning or transferring to any eligible agent needs no availability confirmation and records no exception', function (string $route, array $input) {
    $conversation = Conversation::factory()->inDepartment($this->department)->create();

    $this->actingAs($this->admin)
        ->post(route($route, $conversation), [...$input, 'user_id' => $this->agent->id, 'version' => 0])
        ->assertSessionHasNoErrors();

    expect($conversation->refresh())
        ->assigned_user_id->toBe($this->agent->id)
        ->status->toBe(ConversationStatus::Open)
        ->and($conversation->events()->sole()->metadata)->toBeNull();
})->with([
    'assign' => ['conversations.assign', []],
    'transfer' => ['conversations.transfer', ['reason' => 'Cliente frecuente de Ana']],
]);

test('a responsible agent transfers to a colleague whatever their legacy availability', function () {
    $responsible = User::factory()->agent()->inDepartments($this->department)->create();
    $conversation = Conversation::factory()->inDepartment($this->department)->assignedTo($responsible)->create();

    $this->actingAs($responsible)
        ->post(route('conversations.transfer', $conversation), ['user_id' => $this->agent->id, 'version' => 0])
        ->assertSessionHasNoErrors();

    expect($conversation->refresh()->assigned_user_id)->toBe($this->agent->id);
});

test('the destinations show whether each agent reached their limit, never an availability', function () {
    app(PlatformSettings::class)->update(['operations' => ['max_open_conversations_per_agent' => 1]]);
    $free = User::factory()->agent()->inDepartments($this->department)->create();
    Conversation::factory()->inDepartment($this->department)->assignedTo($this->agent)->create();
    $conversation = Conversation::factory()->inDepartment($this->department)->create();

    $this->actingAs($this->admin)
        ->getJson(route('conversations.destinations', $conversation))
        ->assertOk()
        ->assertJsonPath('allows_exceptions', true)
        ->assertJsonPath('departments.0.agents', fn ($agents) => collect($agents)->keyBy('id')->only([$this->agent->id, $free->id])->all() == [
            $this->agent->id => ['id' => $this->agent->id, 'name' => 'Ana', 'at_capacity' => true],
            $free->id => ['id' => $free->id, 'name' => $free->name, 'at_capacity' => false],
        ]);
});

test('the destinations tell someone who cannot assign that agents at their limit are not an option', function () {
    $responsible = User::factory()->agent()->inDepartments($this->department)->create();
    $conversation = Conversation::factory()->inDepartment($this->department)->assignedTo($responsible)->create();

    $this->actingAs($responsible)
        ->getJson(route('conversations.destinations', $conversation))
        ->assertOk()
        ->assertJsonPath('allows_exceptions', false);
});

test('inactive users and non-members never receive a conversation', function (Closure $makeTarget, string $message) {
    $conversation = Conversation::factory()->inDepartment($this->department)->create();
    $target = $makeTarget($this);

    $this->actingAs($this->admin)
        ->from(route('conversations.show', $conversation))
        ->post(route('conversations.assign', $conversation), ['user_id' => $target->id, 'version' => 0])
        ->assertSessionHasErrors(['conversation' => str_replace(':name', $target->name, $message)]);

    expect($conversation->refresh()->assigned_user_id)->toBeNull();
})->with([
    'deactivated' => [fn ($test) => User::factory()->agent()->inactive()->inDepartments($test->department)->create(), 'La cuenta de :name está eliminada.'],
    'not a member' => [fn ($test) => User::factory()->agent()->inDepartments(Department::factory()->create())->create(), ':name no pertenece al departamento Ventas.'],
]);

test('automatic distribution only picks active members that can attend, ignoring the legacy availability', function () {
    $department = Department::factory()->distribution(DistributionMode::Automatic)->create();
    User::factory()->agent()->available()->inactive()->inDepartments($department)->create();
    User::factory()->agent()->available()->inDepartments(Department::factory()->create())->create();
    conversationUser([Permission::ViewConversations], departments: [$department])->forceFill(['availability' => AgentAvailability::Available])->save();
    User::factory()->withRole(Role::factory()->inactive()->withPermissions([Permission::ViewConversations, Permission::ReplyConversations])->create())->available()->inDepartments($department)->create();
    $conversation = Conversation::factory()->inDepartment($department)->create();

    $assigned = DB::transaction(fn () => app(AssignAutomatically::class)->handle(Conversation::query()->lockForUpdate()->findOrFail($conversation->id)));

    expect($assigned)->toBeNull()
        ->and($conversation->refresh()->status)->toBe(ConversationStatus::Pending)
        ->and($conversation->events()->count())->toBe(0);

    $eligible = User::factory()->agent()->inDepartments($department)->create(['availability' => AgentAvailability::Unavailable]);

    $assigned = DB::transaction(fn () => app(AssignAutomatically::class)->handle(Conversation::query()->lockForUpdate()->findOrFail($conversation->id)));

    expect($assigned?->id)->toBe($eligible->id)
        ->and($conversation->events()->sole()->type)->toBe(ConversationEventType::AutoAssigned);
});

test('automatic distribution still respects the capacity limit', function () {
    $department = Department::factory()->distribution(DistributionMode::Automatic)->create(['max_open_conversations_per_agent' => 1]);
    $agent = User::factory()->agent()->inDepartments($department)->create();
    Conversation::factory()->inDepartment($department)->assignedTo($agent)->create();
    $conversation = Conversation::factory()->inDepartment($department)->create();

    expect(app(AssignAutomatically::class)->distributeQueue($department))->toBe(0)
        ->and($conversation->refresh()->assigned_user_id)->toBeNull();
});

test('freed capacity is offered to the agent without any availability switch', function () {
    $department = Department::factory()->distribution(DistributionMode::Automatic)->create(['max_open_conversations_per_agent' => 1]);
    $agent = User::factory()->agent()->inDepartments($department)->create();
    $attended = Conversation::factory()->inDepartment($department)->assignedTo($agent)->create();
    $waiting = Conversation::factory()->inDepartment($department)->create();

    $this->actingAs($agent)->post(route('conversations.close', $attended), ['version' => 0])->assertSessionHasNoErrors();

    expect($waiting->refresh()->assigned_user_id)->toBe($agent->id);
});

test('an already assigned conversation is never distributed again', function () {
    $department = Department::factory()->distribution(DistributionMode::Automatic)->create();
    $owner = User::factory()->agent()->inDepartments($department)->create();
    User::factory()->agent()->inDepartments($department)->create();
    $conversation = Conversation::factory()->inDepartment($department)->assignedTo($owner)->create();

    $assigned = DB::transaction(fn () => app(AssignAutomatically::class)->handle(Conversation::query()->lockForUpdate()->findOrFail($conversation->id)));

    expect($assigned)->toBeNull()
        ->and($conversation->refresh()->assigned_user_id)->toBe($owner->id)
        ->and($conversation->events()->count())->toBe(0);
});

test('shared inbox departments never assign automatically', function () {
    User::factory()->agent()->inDepartments($this->department)->create();
    $connection = Connection::factory()->create(['default_department_id' => $this->department->id]);

    $conversation = receiveWhatsapp($connection)->conversation;

    expect($this->department->distribution_mode)->toBe(DistributionMode::Shared)
        ->and($conversation->status)->toBe(ConversationStatus::Pending)
        ->and($conversation->assigned_user_id)->toBeNull();
});

test('reassigning and transferring never extend the customer service window', function () {
    $owner = User::factory()->agent()->inDepartments($this->department)->create();
    $support = Department::factory()->create();
    $lastInbound = now()->subHours(20)->startOfSecond();
    $conversation = Conversation::factory()->inDepartment($this->department)->assignedTo($owner)->create(['last_inbound_at' => $lastInbound]);

    $this->actingAs($this->admin)->post(route('conversations.assign', $conversation), ['user_id' => $this->agent->id, 'version' => 0])->assertSessionHasNoErrors();
    $this->actingAs($this->admin)->post(route('conversations.transfer', $conversation), ['department_id' => $support->id, 'version' => 1])->assertSessionHasNoErrors();

    expect($conversation->refresh()->last_inbound_at->toDateTimeString())->toBe($lastInbound->toDateTimeString());
});

test('expected conflicts are logged as information, never as errors', function () {
    Log::spy();
    app(PlatformSettings::class)->update(['operations' => ['max_open_conversations_per_agent' => 1]]);
    Conversation::factory()->inDepartment($this->department)->assignedTo($this->agent)->create();
    $conversation = Conversation::factory()->inDepartment($this->department)->create();

    $this->actingAs($this->agent)
        ->postJson(route('conversations.accept', $conversation))
        ->assertStatus(409);

    Log::shouldHaveReceived('info')->once()->withArgs(fn (string $message, array $context) => $context === [
        'reason' => 'Ya atiendes el máximo de 1 conversaciones permitido. Cierra o libera alguna antes de aceptar otra.',
        'conversation_id' => $conversation->id,
        'user_id' => $this->agent->id,
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

    $this->actingAs($this->agent)->postJson(route('conversations.accept', $conversation))->assertStatus(409);

    Log::shouldHaveReceived('warning')->once();
    Log::shouldNotHaveReceived('error');
});

<?php

use App\Enums\ConversationEventType;
use App\Enums\DataScope;
use App\Enums\Permission;
use App\Models\Connection;
use App\Models\Conversation;
use App\Models\Department;
use App\Models\Role;
use App\Models\User;

/*
| Who can be chosen as the receiver of an assignment or transfer. The list
| follows eligibility (active account, active role that views and replies,
| membership or organization-wide scope, active and authorized department),
| not the actor's visibility: choosing a colleague never requires seeing
| their conversations, and the engine validates everything again.
*/

beforeEach(function () {
    $this->connection = Connection::factory()->create();
    $this->sales = Department::factory()->create(['name' => 'Ventas']);
    $this->support = Department::factory()->create(['name' => 'Soporte']);
    $this->connection->departments()->attach([$this->sales->id, $this->support->id]);

    $this->agent = User::factory()->agent()->available()->inDepartments($this->sales)->create(['name' => 'Ana']);
    $this->technician = User::factory()->agent()->available()->inDepartments($this->support)->create(['name' => 'Tomás']);
    $this->conversation = Conversation::factory()->for($this->connection, 'channelConnection')->inDepartment($this->sales)->assignedTo($this->agent)->create();
});

/**
 * @return array<int, list<int>> department id => agent ids offered
 */
function offeredAgents(User $actor, Conversation $conversation): array
{
    return collect(test()->actingAs($actor)->getJson(route('conversations.destinations', $conversation))->assertOk()->json('departments'))
        ->mapWithKeys(fn (array $department): array => [$department['id'] => collect($department['agents'])->pluck('id')->sort()->values()->all()])
        ->all();
}

test('agents of another authorized department are offered to whoever transfers, whatever their scope', function () {
    $supervisor = User::factory()->supervisor()->inDepartments($this->sales)->create();

    expect(offeredAgents($this->agent, $this->conversation)[$this->support->id])->toBe([$this->technician->id])
        ->and(offeredAgents($supervisor, $this->conversation)[$this->support->id])->toBe([$this->technician->id]);
});

test('people who could not attend the department are never offered', function () {
    User::factory()->agent()->inactive()->inDepartments($this->support)->create();
    User::factory()->withRole(Role::factory()->inactive()->withPermissions([Permission::ViewConversations, Permission::ReplyConversations])->create())->inDepartments($this->support)->create();
    conversationUser([Permission::ViewConversations], departments: [$this->support]);
    $global = conversationUser([Permission::ViewConversations, Permission::ReplyConversations], DataScope::Global);

    $inactive = Department::factory()->inactive()->create();
    $unauthorized = Department::factory()->create();
    $this->connection->departments()->attach($inactive->id);
    User::factory()->agent()->inDepartments($inactive)->create();
    User::factory()->agent()->inDepartments($unauthorized)->create();

    $offered = offeredAgents($this->agent, $this->conversation);

    expect(array_keys($offered))->toEqualCanonicalizing([$this->sales->id, $this->support->id])
        ->and($offered[$this->support->id])->toBe(collect([$this->technician->id, $global->id])->sort()->values()->all());
});

test('the destinations are only answered to whoever may transfer or assign that conversation', function () {
    $queued = Conversation::factory()->for($this->connection, 'channelConnection')->inDepartment($this->sales)->create();
    $outsider = Conversation::factory()->for($this->connection, 'channelConnection')->inDepartment($this->support)->create();

    $this->actingAs($this->agent)->getJson(route('conversations.destinations', $queued))->assertForbidden();
    $this->actingAs($this->agent)->getJson(route('conversations.destinations', $outsider))->assertNotFound();
});

test('an agent transfers their conversation to a colleague of another department they cannot see', function () {
    $this->actingAs($this->agent)
        ->post(route('conversations.transfer', $this->conversation), [
            'department_id' => $this->support->id,
            'user_id' => $this->technician->id,
            'version' => 0,
        ])
        ->assertSessionHasNoErrors();

    expect($this->conversation->refresh()->assigned_user_id)->toBe($this->technician->id)
        ->and($this->conversation->events()->sole())
        ->type->toBe(ConversationEventType::Transferred)
        ->to_user_id->toBe($this->technician->id)
        ->to_department_id->toBe($this->support->id);
});

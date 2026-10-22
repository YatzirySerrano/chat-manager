<?php

use App\Actions\Conversations\TransferConversation;
use App\Actions\Roles\SaveRole;
use App\Broadcasting\ConversationChannel;
use App\Broadcasting\UserChannel;
use App\Enums\DataScope;
use App\Enums\DistributionMode;
use App\Enums\Permission;
use App\Models\Conversation;
use App\Models\Department;
use App\Models\User;
use Tests\Feature\Realtime\RealtimeTestSupport;

beforeEach(fn () => RealtimeTestSupport::useReverb());

function subscribeTo(string $channel): array
{
    return ['socket_id' => '1234.5678', 'channel_name' => "private-{$channel}"];
}

test('a user may only subscribe to their own personal channel', function () {
    $user = User::factory()->agent()->create();
    $other = User::factory()->agent()->create();

    $this->actingAs($user)->postJson('/broadcasting/auth', subscribeTo("users.{$user->id}"))
        ->assertOk()
        ->assertJsonStructure(['auth']);

    $this->actingAs($user)->postJson('/broadcasting/auth', subscribeTo("users.{$other->id}"))
        ->assertForbidden();
});

test('guests cannot authorize any channel', function () {
    $user = User::factory()->agent()->create();

    $this->postJson('/broadcasting/auth', subscribeTo("users.{$user->id}"))->assertUnauthorized();
});

test('malformed channel identifiers are refused', function (string $channel) {
    $user = User::factory()->superAdministrator()->create();

    $this->actingAs($user)->postJson('/broadcasting/auth', subscribeTo(str_replace('{id}', (string) $user->id, $channel)))
        ->assertForbidden();
})->with([
    'leading zero' => 'users.0{id}',
    'not a number' => 'conversations.abc',
    'negative' => 'inbox.departments.-1',
    'unknown channel' => 'secrets.{id}',
]);

test('a deleted account is refused even with a stale in-memory user', function () {
    $user = User::factory()->agent()->create();
    $stale = clone $user;
    $user->forceFill(['is_active' => false])->save();

    expect(app(UserChannel::class)->join($stale, (string) $stale->id))->toBeFalse();

    $this->actingAs($stale)->postJson('/broadcasting/auth', subscribeTo("users.{$stale->id}"))->assertForbidden();
});

test('conversation channels follow conversation visibility', function () {
    $sales = Department::factory()->create();
    $support = Department::factory()->create();
    $agent = User::factory()->agent()->inDepartments($sales)->create();
    $colleague = User::factory()->agent()->inDepartments($sales)->create();
    $supervisor = User::factory()->supervisor()->inDepartments($sales)->create();
    $foreignSupervisor = User::factory()->supervisor()->inDepartments($support)->create();
    $admin = User::factory()->administrator()->create();

    $assigned = Conversation::factory()->inDepartment($sales)->assignedTo($agent)->create();
    $queued = Conversation::factory()->inDepartment($sales)->create();

    $allowed = fn (User $user, Conversation $conversation): int => $this->actingAs($user)
        ->postJson('/broadcasting/auth', subscribeTo("conversations.{$conversation->id}"))
        ->status();

    expect($allowed($agent, $assigned))->toBe(200)
        ->and($allowed($colleague, $assigned))->toBe(403)
        ->and($allowed($colleague, $queued))->toBe(200)
        ->and($allowed($supervisor, $assigned))->toBe(200)
        ->and($allowed($foreignSupervisor, $assigned))->toBe(403)
        ->and($allowed($admin, $assigned))->toBe(200);
});

test('closed conversations need the history permission', function () {
    $department = Department::factory()->create();
    $agent = conversationUser([Permission::ViewConversations, Permission::ReplyConversations], DataScope::Assigned, [$department]);
    $closed = Conversation::factory()->inDepartment($department)->assignedTo($agent)->closed()->create();

    expect(app(ConversationChannel::class)->join($agent, (string) $closed->id))->toBeFalse();
});

test('a transferred conversation stops being available to the previous department agent', function () {
    $sales = Department::factory()->create();
    $support = Department::factory()->create();
    $agent = User::factory()->agent()->available()->inDepartments($sales)->create();
    $supervisor = User::factory()->supervisor()->inDepartments([$sales, $support])->create();
    $conversation = Conversation::factory()->inDepartment($sales)->assignedTo($agent)->create();

    expect(app(ConversationChannel::class)->join($agent, (string) $conversation->id))->toBeTrue();

    app(TransferConversation::class)->handle($conversation, $support, null, $supervisor, 'Cambio de área', $conversation->version);

    expect(app(ConversationChannel::class)->join($agent, (string) $conversation->id))->toBeFalse();
});

test('revoking a permission takes effect on the next subscription of an open session', function () {
    $department = Department::factory()->create();
    $agent = conversationUser([Permission::ViewConversations, Permission::ReplyConversations, Permission::AcceptConversations], DataScope::Assigned, [$department]);
    $conversation = Conversation::factory()->inDepartment($department)->assignedTo($agent)->create();
    $admin = User::factory()->superAdministrator()->create();

    $this->actingAs($agent)->postJson('/broadcasting/auth', subscribeTo("conversations.{$conversation->id}"))->assertOk();

    // Publishing is not under test here (see TransferAndDeliveryTest).
    config(['broadcasting.default' => 'null']);

    app(SaveRole::class)->update($agent->role(), [
        'label' => $agent->role()->label,
        'description' => null,
        'scope' => DataScope::Assigned,
        'permissions' => [Permission::ViewDashboard->value],
    ], $admin);

    RealtimeTestSupport::useReverb();

    $this->actingAs($agent)->postJson('/broadcasting/auth', subscribeTo("conversations.{$conversation->id}"))->assertForbidden();
});

test('department team inboxes are for global users and members with a departments scope', function () {
    $sales = Department::factory()->create();
    $support = Department::factory()->create();

    $status = fn (User $user, string $channel): int => $this->actingAs($user)
        ->postJson('/broadcasting/auth', subscribeTo($channel))
        ->status();

    expect($status(User::factory()->supervisor()->inDepartments($sales)->create(), "inbox.departments.{$sales->id}"))->toBe(200)
        ->and($status(User::factory()->supervisor()->inDepartments($support)->create(), "inbox.departments.{$sales->id}"))->toBe(403)
        ->and($status(User::factory()->agent()->inDepartments($sales)->create(), "inbox.departments.{$sales->id}"))->toBe(403)
        ->and($status(User::factory()->administrator()->create(), "inbox.departments.{$sales->id}"))->toBe(200)
        ->and($status(User::factory()->administrator()->create(), 'inbox.departments.999999'))->toBe(403);
});

test('department queues are for whoever may accept from them now', function () {
    $shared = Department::factory()->create();
    $manual = Department::factory()->distribution(DistributionMode::Manual)->create();
    $deleted = Department::factory()->inactive()->create();
    $agent = User::factory()->agent()->inDepartments([$shared, $manual, $deleted])->create();
    $outsider = User::factory()->agent()->create();

    $status = fn (User $user, Department $department): int => $this->actingAs($user)
        ->postJson('/broadcasting/auth', subscribeTo("inbox.queues.{$department->id}"))
        ->status();

    expect($status($agent, $shared))->toBe(200)
        ->and($status($agent, $manual))->toBe(403)
        ->and($status($agent, $deleted))->toBe(403)
        ->and($status($outsider, $shared))->toBe(403);
});

test('the global inbox is only for users with a global scope', function () {
    $department = Department::factory()->create();

    $this->actingAs(User::factory()->administrator()->create())
        ->postJson('/broadcasting/auth', subscribeTo('inbox.global'))
        ->assertOk();

    $this->actingAs(User::factory()->supervisor()->inDepartments($department)->create())
        ->postJson('/broadcasting/auth', subscribeTo('inbox.global'))
        ->assertForbidden();
});

<?php

use App\Enums\ConversationStatus;
use App\Enums\DataScope;
use App\Enums\Permission;
use App\Http\Middleware\HandleInertiaRequests;
use App\Models\Conversation;
use App\Models\ConversationNote;
use App\Models\Department;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

/*
| Who reaches which conversation. Every rule combines the permission of the
| action, the data scope, department membership and the assignment.
*/

beforeEach(function () {
    $this->sales = Department::factory()->create(['name' => 'Ventas']);
    $this->support = Department::factory()->create(['name' => 'Soporte']);
    $this->agent = User::factory()->agent()->inDepartments($this->sales)->create();
    $this->colleague = User::factory()->agent()->inDepartments($this->sales)->create();

    $this->mine = Conversation::factory()->inDepartment($this->sales)->assignedTo($this->agent)->create();
    $this->colleagues = Conversation::factory()->inDepartment($this->sales)->assignedTo($this->colleague)->create();
    $this->queued = Conversation::factory()->inDepartment($this->sales)->create();
    $this->otherQueue = Conversation::factory()->inDepartment($this->support)->create();
    $this->unrouted = Conversation::factory()->create();
});

test('the list shows each person only the conversations within their reach', function (Closure $viewer, Closure $expected) {
    $user = $viewer($this);

    $this->actingAs($user)
        ->get(route('conversations.index', ['status' => 'all']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('conversations/Index')
            ->where('conversations.data', fn ($rows) => collect($rows)->pluck('id')->sort()->values()->all() === collect($expected($this))->pluck('id')->sort()->values()->all())
            ->where('counts', fn ($counts) => collect($counts)->sum() === count($expected($this))));
})->with([
    'administrator' => [fn ($test) => User::factory()->administrator()->create(), fn ($test) => [$test->mine, $test->colleagues, $test->queued, $test->otherQueue, $test->unrouted]],
    'supervisor of the department' => [fn ($test) => User::factory()->supervisor()->inDepartments($test->sales)->create(), fn ($test) => [$test->mine, $test->colleagues, $test->queued]],
    // Agents open on "Mis conversaciones"; the queue they may accept from is
    // a separate view (see ConversationInboxTest).
    'agent with accept permission' => [fn ($test) => $test->agent, fn ($test) => [$test->mine]],
    'agent without accept permission' => [fn ($test) => conversationUser([Permission::ViewConversations, Permission::ReplyConversations], departments: [$test->sales]), fn ($test) => []],
]);

test('an agent cannot open a colleague\'s conversation or another queue by URL', function (string $conversation) {
    $this->actingAs($this->agent)
        ->get(route('conversations.show', $this->{$conversation}))
        ->assertNotFound();
})->with(['colleagues', 'otherQueue', 'unrouted']);

test('an agent opens their own conversation and the pending ones they can accept', function () {
    $this->actingAs($this->agent)
        ->get(route('conversations.show', $this->mine))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('conversations/Show')
            ->where('conversation.can.reply', true)
            ->where('conversation.can.assign', false)
            ->where('conversation.can.accept', false));

    $this->actingAs($this->agent)
        ->get(route('conversations.show', $this->queued))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('conversation.can.accept', true)
            ->where('conversation.can.reply', false)
            ->where('conversation.can.transfer', false));
});

test('operations on a conversation outside the agent\'s reach look like a missing record', function (string $route, array $input) {
    $input = array_map(fn (mixed $value): mixed => match ($value) {
        'self' => $this->agent->id,
        'sales' => $this->sales->id,
        default => $value,
    }, $input);

    $this->actingAs($this->agent)
        ->post(route($route, $this->colleagues), [...$input, 'version' => 0])
        ->assertNotFound();

    expect($this->colleagues->refresh())
        ->assigned_user_id->toBe($this->colleague->id)
        ->status->toBe(ConversationStatus::Open)
        ->and($this->colleagues->events()->count())->toBe(0)
        ->and($this->colleagues->notes()->count())->toBe(0);
})->with([
    'assign to self' => ['conversations.assign', ['user_id' => 'self']],
    'transfer' => ['conversations.transfer', ['department_id' => 'sales']],
    'release' => ['conversations.release', []],
    'hold' => ['conversations.hold', []],
    'close' => ['conversations.close', []],
    'note' => ['conversations.notes.store', ['body' => 'Intrusión']],
]);

test('an agent without the transfer permission cannot transfer their own conversation', function () {
    $agent = conversationUser([Permission::ViewConversations, Permission::ReplyConversations], departments: [$this->sales]);
    $conversation = Conversation::factory()->inDepartment($this->sales)->assignedTo($agent)->create();

    $this->actingAs($agent)
        ->post(route('conversations.transfer', $conversation), ['department_id' => $this->support->id, 'version' => 0])
        ->assertForbidden();

    expect($conversation->refresh()->department_id)->toBe($this->sales->id);
});

test('agents cannot assign even their own conversations to someone else', function () {
    $this->actingAs($this->agent)
        ->post(route('conversations.assign', $this->mine), ['user_id' => $this->colleague->id, 'version' => 0])
        ->assertForbidden();

    expect($this->mine->refresh()->assigned_user_id)->toBe($this->agent->id);
});

test('nobody edits a colleague\'s note, not even the administrator', function () {
    $note = ConversationNote::factory()->for($this->mine)->create(['user_id' => $this->agent->id, 'body' => 'Original']);
    $supervisor = User::factory()->supervisor()->inDepartments($this->sales)->create();

    foreach ([$supervisor, User::factory()->administrator()->create()] as $other) {
        $this->actingAs($other)
            ->put(route('conversations.notes.update', [$this->mine, $note]), ['body' => 'Alterado'])
            ->assertForbidden();
    }

    expect($note->refresh()->body)->toBe('Original');
});

test('closed conversations require the history permission', function () {
    $closed = Conversation::factory()->inDepartment($this->sales)->assignedTo($this->agent)->closed()->create();
    $withoutHistory = conversationUser([Permission::ViewConversations, Permission::ReplyConversations], DataScope::Global);

    expect($this->agent->can('view', $closed))->toBeTrue()
        ->and($withoutHistory->can('view', $closed))->toBeFalse()
        ->and(Conversation::query()->visibleTo($withoutHistory)->whereKey($closed->id)->exists())->toBeFalse();
});

test('a revoked permission is honored on the next request', function () {
    $role = $this->agent->role();
    $role->revokePermissionTo(Permission::AcceptConversations->value);

    $this->actingAs($this->agent->fresh())
        ->post(route('conversations.accept', $this->queued))
        ->assertNotFound();

    $role->revokePermissionTo(Permission::ViewConversations->value);

    $this->actingAs($this->agent->fresh())
        ->get(route('conversations.index'))
        ->assertForbidden();
});

test('a deactivated user cannot operate', function () {
    $this->agent->forceFill(['is_active' => false])->save();

    $this->actingAs($this->agent)
        ->post(route('conversations.close', $this->mine), ['version' => 0])
        ->assertRedirect(route('login'));

    expect($this->mine->refresh()->status)->not->toBe(ConversationStatus::Closed);
});

test('tampered inputs are rejected without side effects', function (array $input, string $error) {
    $admin = User::factory()->administrator()->create();

    $this->actingAs($admin)
        ->from(route('conversations.show', $this->queued))
        ->post(route('conversations.assign', $this->queued), $input)
        ->assertSessionHasErrors($error);

    expect($this->queued->refresh()->assigned_user_id)->toBeNull();
})->with([
    'missing version' => [['user_id' => 1], 'version'],
    'negative version' => [['user_id' => 1, 'version' => -1], 'version'],
    'version as text' => [['user_id' => 1, 'version' => 'latest'], 'version'],
    'user id as array' => [['user_id' => [1, 2], 'version' => 0], 'user_id'],
]);

test('filters cannot reveal conversations outside the scope', function () {
    // A personal inbox ignores the agent filter: it only ever holds the agent's own work.
    $this->actingAs($this->agent)
        ->get(route('conversations.index', ['agent' => $this->colleague->id, 'status' => 'all']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('conversations.data', fn ($rows) => collect($rows)->pluck('id')->all() === [$this->mine->id])
            ->where('agents', []));

    $this->actingAs($this->agent)
        ->get(route('conversations.index', ['department' => 'none']))
        ->assertInertia(fn (Assert $page) => $page->where('conversations.data', []));
});

test('the transfer options only name people the actor may already see', function () {
    $this->actingAs($this->agent)
        ->get(route('conversations.show', $this->mine), [
            'X-Inertia' => 'true',
            'X-Inertia-Partial-Component' => 'conversations/Show',
            'X-Inertia-Partial-Data' => 'transferOptions',
            'X-Inertia-Version' => app(HandleInertiaRequests::class)->version(request()),
        ])
        ->assertOk()
        ->assertJsonPath('props.transferOptions.departments.0.agents', fn ($agents) => collect($agents)->pluck('id')->diff([$this->agent->id])->isEmpty());
});

test('assigning or transferring to someone outside the actor\'s scope never reveals who they are', function (string $route, Closure $input) {
    $supervisor = User::factory()->supervisor()->inDepartments($this->sales)->create();
    $outsider = User::factory()->agent()->inDepartments($this->support)->create(['name' => 'Persona Fuera De Alcance']);

    $response = $this->actingAs($supervisor)
        ->from(route('conversations.show', $this->queued))
        ->post(route($route, $this->queued), [...$input($this, $outsider), 'version' => 0])
        ->assertSessionHasErrors(['conversation' => 'El agente elegido no existe.']);

    expect(json_encode(session()->all()))->not->toContain('Persona Fuera De Alcance')
        ->and($this->queued->refresh()->assigned_user_id)->toBeNull();
})->with([
    'assign' => ['conversations.assign', fn ($test, $outsider) => ['user_id' => $outsider->id]],
    'transfer' => ['conversations.transfer', fn ($test, $outsider) => ['department_id' => $test->sales->id, 'user_id' => $outsider->id]],
]);

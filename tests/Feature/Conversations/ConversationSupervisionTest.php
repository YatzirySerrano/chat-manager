<?php

use App\Actions\Conversations\TransferConversation;
use App\Enums\DataScope;
use App\Enums\DefaultRole;
use App\Enums\Permission;
use App\Models\Conversation;
use App\Models\Department;
use App\Models\Role;
use App\Models\Tag;
use App\Models\User;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

/*
| Read-only supervision (conversations.supervise, DESIGN decision 3): within
| the data scope it shows every conversation regardless of assignee and
| status, keeps conversations that passed through the supervisor's
| departments visible after a transfer, and never grants an operation.
*/

beforeEach(function () {
    $this->sales = Department::factory()->create(['name' => 'Ventas']);
    $this->support = Department::factory()->create(['name' => 'Soporte']);
    $this->salesAgent = User::factory()->agent()->inDepartments($this->sales)->create();
    $this->supportAgent = User::factory()->agent()->inDepartments($this->support)->create();
});

/**
 * A departments-scope supervisor role that can only view and supervise.
 */
function readOnlySupervisor(Department $department): User
{
    $role = Role::factory()->scope(DataScope::Departments)->withPermissions([
        Permission::ViewConversations,
        Permission::SuperviseConversations,
    ])->create();

    return User::factory()->withRole($role)->inDepartments($department)->create();
}

test('a global administrator keeps seeing the conversations of every agent after transfers', function () {
    $admin = User::factory()->administrator()->create();
    $conversation = Conversation::factory()->inDepartment($this->sales)->assignedTo($this->salesAgent)->create();

    app(TransferConversation::class)->handle($conversation, $this->support, $this->supportAgent, $admin, 'Caso técnico');

    expect(Conversation::query()->visibleTo($admin)->whereKey($conversation->id)->exists())->toBeTrue()
        ->and($admin->can('view', $conversation->refresh()))->toBeTrue();

    $this->actingAs($admin)
        ->get(route('conversations.index', ['c' => $conversation->id]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('conversation.id', $conversation->id));
});

test('a departments supervisor keeps visibility after the conversation is transferred out of their department', function () {
    $supervisor = User::factory()->supervisor()->inDepartments($this->sales)->create();
    $conversation = Conversation::factory()->inDepartment($this->sales)->assignedTo($this->salesAgent)->create();

    app(TransferConversation::class)->handle($conversation, $this->support, null, $supervisor, 'Corresponde a soporte', 0);
    $conversation->refresh();

    expect($conversation->department_id)->toBe($this->support->id)
        ->and(Conversation::query()->visibleTo($supervisor)->whereKey($conversation->id)->exists())->toBeTrue()
        ->and($supervisor->can('view', $conversation))->toBeTrue()
        ->and($supervisor->can('supervise', $conversation))->toBeTrue();

    $this->actingAs($supervisor)
        ->get(route('conversations.index', ['c' => $conversation->id]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('conversation.id', $conversation->id));
});

test('without supervise a departments user loses the conversation once it leaves their department', function () {
    $role = Role::factory()->scope(DataScope::Departments)->withPermissions([Permission::ViewConversations, Permission::TransferConversations, Permission::AssignConversations])->create();
    $viewer = User::factory()->withRole($role)->inDepartments($this->sales)->create();
    $conversation = Conversation::factory()->inDepartment($this->sales)->create();
    $conversation->events()->create(['type' => 'transferred', 'from_department_id' => $this->sales->id, 'to_department_id' => $this->support->id, 'created_at' => now()]);
    $conversation->forceFill(['department_id' => $this->support->id])->save();

    expect(Conversation::query()->visibleTo($viewer)->whereKey($conversation->id)->exists())->toBeFalse()
        ->and($viewer->can('view', $conversation))->toBeFalse();
});

test('a read-only supervisor sees every conversation of the department, including closed history', function () {
    $supervisor = readOnlySupervisor($this->sales);
    $open = Conversation::factory()->inDepartment($this->sales)->assignedTo($this->salesAgent)->create();
    $closed = Conversation::factory()->inDepartment($this->sales)->assignedTo($this->salesAgent)->closed()->create();
    $foreign = Conversation::factory()->inDepartment($this->support)->assignedTo($this->supportAgent)->create();

    expect(Conversation::query()->visibleTo($supervisor)->pluck('id')->all())->toEqualCanonicalizing([$open->id, $closed->id])
        ->and($supervisor->can('viewHistory', $closed))->toBeTrue()
        ->and($supervisor->can('view', $foreign))->toBeFalse();

    $this->actingAs($supervisor)->get(route('conversations.index', ['c' => $closed->id]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('conversation.id', $closed->id));
    $this->actingAs($supervisor)->getJson(route('conversations.timeline', $closed))->assertOk();
});

test('a supervise-only user may filter the closed conversations of their scope', function () {
    $supervisor = readOnlySupervisor($this->sales);
    Conversation::factory()->inDepartment($this->sales)->assignedTo($this->salesAgent)->create();
    $closed = Conversation::factory()->inDepartment($this->sales)->assignedTo($this->salesAgent)->closed()->create();

    $this->actingAs($supervisor)
        ->get(route('conversations.index', ['status' => 'closed']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('canSeeClosed', true)
            ->where('filters.status', 'closed')
            ->where('conversations.data', fn ($rows) => collect($rows)->pluck('id')->all() === [$closed->id]));
});

test('supervising never grants operations', function () {
    $supervisor = readOnlySupervisor($this->sales);
    $conversation = Conversation::factory()->inDepartment($this->sales)->assignedTo($this->salesAgent)->create();
    $pending = Conversation::factory()->inDepartment($this->sales)->create();

    foreach (['reply', 'accept', 'assign', 'transfer', 'close', 'tag', 'release', 'sendTemplate'] as $ability) {
        expect($supervisor->can($ability, $conversation))->toBeFalse("supervise must not grant {$ability}")
            ->and($supervisor->can($ability, $pending))->toBeFalse("supervise must not grant {$ability}");
    }

    $this->actingAs($supervisor)
        ->postJson(route('conversations.messages.store', $conversation), ['body' => 'Hola', 'client_reference' => (string) Str::uuid()])
        ->assertForbidden();

    $this->actingAs($supervisor)
        ->get(route('conversations.index', ['c' => $conversation->id]))
        ->assertInertia(fn (Assert $page) => $page
            ->where('conversation.can.supervise', true)
            ->where('conversation.can.reply', false)
            ->where('conversation.can.send_templates', false));
});

test('an agent of one department never reaches a conversation of another by URL, API or ids', function () {
    $foreign = Conversation::factory()->inDepartment($this->support)->assignedTo($this->supportAgent)->create();

    $this->actingAs($this->salesAgent)->get(route('conversations.show', $foreign))->assertNotFound();
    $this->actingAs($this->salesAgent)->getJson(route('conversations.timeline', $foreign))->assertNotFound();
    $this->actingAs($this->salesAgent)
        ->postJson(route('conversations.messages.store', $foreign), ['body' => 'Hola', 'client_reference' => (string) Str::uuid()])
        ->assertNotFound();

    expect(Conversation::query()->visibleTo($this->salesAgent)->whereKey($foreign->id)->exists())->toBeFalse();
});

/**
 * State that no refused operation may change.
 *
 * @return array<string, mixed>
 */
function operationalSnapshot(Conversation $conversation): array
{
    $conversation->refresh();

    return [
        'status' => $conversation->status,
        'department_id' => $conversation->department_id,
        'assigned_user_id' => $conversation->assigned_user_id,
        'version' => $conversation->version,
        'events' => $conversation->events()->count(),
        'notes' => $conversation->notes()->count(),
        'tags' => $conversation->tags()->count(),
        'messages' => $conversation->messages()->count(),
    ];
}

test('the default supervisor only reads a conversation transferred out of their department', function () {
    $supervisor = User::factory()->supervisor()->inDepartments($this->sales)->create();
    $tag = Tag::factory()->create();
    $conversation = Conversation::factory()->inDepartment($this->sales)->assignedTo($this->salesAgent)->create();
    app(TransferConversation::class)->handle($conversation, $this->support, $this->supportAgent, $supervisor, 'Corresponde a soporte');
    $before = operationalSnapshot($conversation);

    expect($supervisor->can('view', $conversation))->toBeTrue();
    $this->actingAs($supervisor)->get(route('conversations.index', ['c' => $conversation->id]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('conversation.id', $conversation->id)
            ->where('conversation.can.supervise', true)
            ->where('conversation.can.assign', false)
            ->where('conversation.can.transfer', false)
            ->where('conversation.can.close', false)
            ->where('conversation.can.release', false)
            ->where('conversation.can.tag', false)
            ->where('conversation.can.addNote', false)
            ->where('conversation.can.reply', false));
    $this->actingAs($supervisor)->getJson(route('conversations.timeline', $conversation))->assertOk();

    $version = $before['version'];
    $requests = [
        fn () => $this->post(route('conversations.assign', $conversation), ['user_id' => $this->supportAgent->id, 'version' => $version]),
        fn () => $this->post(route('conversations.transfer', $conversation), ['department_id' => $this->sales->id, 'version' => $version]),
        fn () => $this->post(route('conversations.close', $conversation), ['version' => $version]),
        fn () => $this->post(route('conversations.release', $conversation), ['version' => $version]),
        fn () => $this->put(route('conversations.tags.update', $conversation), ['tag_ids' => [$tag->id]]),
        fn () => $this->post(route('conversations.notes.store', $conversation), ['body' => 'Intervención', 'version' => $version]),
        fn () => $this->postJson(route('conversations.messages.store', $conversation), ['body' => 'Hola', 'client_reference' => (string) Str::uuid()]),
    ];

    $this->actingAs($supervisor);
    foreach ($requests as $request) {
        expect($request()->status())->toBeIn([403, 404]);
    }

    expect(operationalSnapshot($conversation))->toEqual($before);
});

test('supervision does not let a user without history operate on closed conversations', function () {
    $permissions = array_values(array_filter(
        DefaultRole::Supervisor->permissions(),
        fn (Permission $permission): bool => $permission !== Permission::ViewConversationHistory,
    ));
    $role = Role::factory()->scope(DataScope::Departments)->withPermissions($permissions)->create();
    $supervisor = User::factory()->withRole($role)->inDepartments($this->sales)->create();
    $tag = Tag::factory()->create();
    $closed = Conversation::factory()->inDepartment($this->sales)->assignedTo($this->salesAgent)->closed()->create();
    $before = operationalSnapshot($closed);

    $this->actingAs($supervisor)->get(route('conversations.index', ['c' => $closed->id]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('conversation.id', $closed->id)
            ->where('conversation.can.reopen', false)
            ->where('conversation.can.tag', false)
            ->where('conversation.can.addNote', false));

    expect($this->post(route('conversations.reopen', $closed), ['version' => $before['version']])->status())->toBeIn([403, 404])
        ->and($this->put(route('conversations.tags.update', $closed), ['tag_ids' => [$tag->id]])->status())->toBeIn([403, 404])
        ->and($this->post(route('conversations.notes.store', $closed), ['body' => 'Intervención', 'version' => $before['version']])->status())->toBeIn([403, 404])
        ->and(operationalSnapshot($closed))->toEqual($before);
});

test('the default supervisor still operates within their own department', function () {
    $supervisor = User::factory()->supervisor()->inDepartments($this->sales)->create();
    $colleague = User::factory()->agent()->inDepartments($this->sales)->create();
    $conversation = Conversation::factory()->inDepartment($this->sales)->assignedTo($this->salesAgent)->create();
    $closed = Conversation::factory()->inDepartment($this->sales)->assignedTo($this->salesAgent)->closed()->create();

    foreach (['assign', 'transfer', 'close', 'release', 'tag', 'addNote'] as $ability) {
        expect($supervisor->can($ability, $conversation))->toBeTrue("the supervisor keeps {$ability} in their department");
    }

    expect($supervisor->can('reopen', $closed))->toBeTrue();

    $this->actingAs($supervisor)
        ->post(route('conversations.assign', $conversation), ['user_id' => $colleague->id, 'version' => $conversation->version])
        ->assertSessionHasNoErrors();

    expect($conversation->refresh()->assigned_user_id)->toBe($colleague->id);
});

test('the supervision passage query matches the policy for every listed row', function () {
    $supervisor = User::factory()->supervisor()->inDepartments($this->sales)->create();
    $passed = Conversation::factory()->inDepartment($this->sales)->create();
    $passed->events()->create(['type' => 'transferred', 'from_department_id' => $this->sales->id, 'to_department_id' => $this->support->id, 'created_at' => now()]);
    $passed->forceFill(['department_id' => $this->support->id])->save();
    $arrived = Conversation::factory()->inDepartment($this->support)->create();
    $arrived->events()->create(['type' => 'transferred', 'from_department_id' => $this->support->id, 'to_department_id' => $this->sales->id, 'created_at' => now()]);
    $foreign = Conversation::factory()->inDepartment($this->support)->assignedTo($this->supportAgent)->create();

    $visible = Conversation::query()->visibleTo($supervisor)->pluck('id')->all();

    expect($visible)->toContain($passed->id, $arrived->id)->not->toContain($foreign->id);

    $rows = collect(test()->actingAs($supervisor)->get(route('conversations.index', ['view' => 'all']))->viewData('page')['props']['conversations']['data']);

    expect($rows->pluck('id')->all())->toContain($passed->id, $arrived->id)->not->toContain($foreign->id)
        ->and($rows->firstWhere('id', $passed->id)['can']['view'])->toBeTrue()
        ->and($rows->firstWhere('id', $passed->id)['can']['assign'])->toBeFalse();
});

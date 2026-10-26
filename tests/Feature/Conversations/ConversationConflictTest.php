<?php

use App\Actions\Conversations\ReleaseConversation;
use App\Enums\ConversationConflict as ConversationConflictReason;
use App\Enums\ConversationStatus;
use App\Enums\DataScope;
use App\Enums\Permission;
use App\Exceptions\ConversationStateConflict;
use App\Models\Conversation;
use App\Models\Department;
use App\Models\User;

/*
| Conversation operations tell three refusals apart, in this order:
| out of reach (404, exactly like a missing record), visible but without
| permission or reach for the operation (403), and allowed but the state
| changed meanwhile (409 with a code the interface understands).
*/

const FORBIDDEN_TEXT = 'No tienes autorización para realizar esta acción.';
const CONFLICT_TEXTS = [
    'state_changed' => 'Esta conversación cambió de estado. Actualiza la información para continuar.',
    'assigned_to_other' => 'Otro agente ya está atendiendo esta conversación.',
    'closed' => 'Esta conversación está cerrada. Revisa las opciones disponibles.',
];

/**
 * An agent who sees every conversation of their department (and closed
 * ones), with every attention permission but assigning.
 */
function departmentAgent(Department $department, array $without = []): User
{
    $permissions = array_values(array_filter([
        Permission::ViewConversations,
        Permission::ViewConversationHistory,
        Permission::ReplyConversations,
        Permission::AcceptConversations,
        Permission::TransferConversations,
        Permission::CloseConversations,
        Permission::ReopenConversations,
        Permission::AddConversationNotes,
        Permission::AssignTags,
    ], fn (Permission $permission): bool => ! in_array($permission, $without, true)));

    return conversationUser($permissions, DataScope::Departments, [$department]);
}

beforeEach(function () {
    $this->sales = Department::factory()->create(['name' => 'Ventas']);
    $this->support = Department::factory()->create(['name' => 'Soporte']);
    $this->admin = User::factory()->administrator()->create();
    $this->ana = departmentAgent($this->sales);
    $this->beto = departmentAgent($this->sales);
});

test('without the permission of the operation a visible conversation answers 403 with the plain explanation', function () {
    $agent = departmentAgent($this->sales, without: [Permission::CloseConversations]);
    $conversation = Conversation::factory()->inDepartment($this->sales)->assignedTo($agent)->create();

    $this->actingAs($agent)
        ->postJson(route('conversations.close', $conversation), ['version' => 0])
        ->assertForbidden()
        ->assertExactJson(['message' => FORBIDDEN_TEXT]);

    $this->actingAs($agent)
        ->withHeaders(['X-Inertia' => 'true'])
        ->from(route('conversations.index'))
        ->post(route('conversations.close', $conversation), ['version' => 0])
        ->assertRedirect(route('conversations.index'))
        ->assertInertiaFlash('toast', ['type' => 'error', 'message' => FORBIDDEN_TEXT]);

    expect($conversation->refresh()->status)->toBe(ConversationStatus::Open);
});

test('permission is checked before the state: lacking it is 403 even when the conversation also changed', function () {
    $agent = departmentAgent($this->sales, without: [Permission::TransferConversations]);
    $conversation = Conversation::factory()->inDepartment($this->sales)->assignedTo($this->beto)->closed()->create(['version' => 3]);

    $this->actingAs($agent)
        ->postJson(route('conversations.transfer', $conversation), ['department_id' => $this->support->id, 'version' => 0])
        ->assertForbidden()
        ->assertExactJson(['message' => FORBIDDEN_TEXT]);

    $this->actingAs($agent)
        ->getJson(route('conversations.destinations', $conversation))
        ->assertForbidden()
        ->assertExactJson(['message' => FORBIDDEN_TEXT]);
});

test('a supervisor who only watches a closed conversation may not reopen it: real 403', function () {
    $supervisor = conversationUser(
        [Permission::ViewConversations, Permission::SuperviseConversations, Permission::ReopenConversations],
        DataScope::Departments,
        [$this->sales],
    );
    $conversation = Conversation::factory()->inDepartment($this->sales)->assignedTo($this->ana)->closed()->create();

    expect($supervisor->can('view', $conversation))->toBeTrue();

    $this->actingAs($supervisor)
        ->postJson(route('conversations.reopen', $conversation), ['version' => 0])
        ->assertForbidden()
        ->assertExactJson(['message' => FORBIDDEN_TEXT]);
});

test('a conversation that was never the user\'s is a real 403, not a conflict', function () {
    $conversation = Conversation::factory()->inDepartment($this->sales)->assignedTo($this->ana)->create(['last_inbound_at' => now()]);
    $supervisor = User::factory()->supervisor()->inDepartments($this->sales)->create();

    $this->actingAs($this->beto)
        ->postJson(route('conversations.close', $conversation), ['version' => 0])
        ->assertForbidden()
        ->assertExactJson(['message' => FORBIDDEN_TEXT]);

    $this->actingAs($supervisor)
        ->postJson(route('conversations.messages.store', $conversation), ['body' => 'Hola', 'client_reference' => (string) str()->uuid()])
        ->assertForbidden()
        ->assertExactJson(['message' => FORBIDDEN_TEXT]);

    $this->actingAs($this->beto)
        ->getJson(route('conversations.destinations', ['conversation' => $conversation, 'version' => 0]))
        ->assertForbidden();

    // Opened on an older version, the same request explains what changed.
    $this->actingAs($this->beto)
        ->getJson(route('conversations.destinations', ['conversation' => Conversation::factory()->inDepartment($this->sales)->assignedTo($this->ana)->create(['version' => 2]), 'version' => 1]))
        ->assertStatus(409)
        ->assertJsonPath('code', 'assigned_to_other');
});

test('accepting after another agent took the conversation is a conflict with its code', function () {
    $conversation = Conversation::factory()->inDepartment($this->sales)->create();
    $this->actingAs($this->ana)->post(route('conversations.accept', $conversation))->assertSessionHasNoErrors();

    $this->actingAs($this->beto)
        ->postJson(route('conversations.accept', $conversation))
        ->assertStatus(409)
        ->assertExactJson(['code' => 'assigned_to_other', 'message' => CONFLICT_TEXTS['assigned_to_other']]);

    $this->actingAs($this->beto)
        ->from(route('conversations.show', $conversation))
        ->post(route('conversations.accept', $conversation))
        ->assertRedirect(route('conversations.show', $conversation))
        ->assertSessionHasErrors(['conversation' => CONFLICT_TEXTS['assigned_to_other'], 'conversation_conflict' => 'assigned_to_other']);

    expect($conversation->refresh()->assigned_user_id)->toBe($this->ana->id);
});

test('accepting a conversation closed meanwhile is a "closed" conflict, also for an agent of the queue who no longer sees it', function () {
    $conversation = Conversation::factory()->inDepartment($this->sales)->closed()->create();
    $queueAgent = User::factory()->agent()->inDepartments($this->sales)->create();

    $this->actingAs($this->beto)
        ->postJson(route('conversations.accept', $conversation))
        ->assertStatus(409)
        ->assertExactJson(['code' => 'closed', 'message' => CONFLICT_TEXTS['closed']]);

    $this->actingAs($queueAgent)
        ->postJson(route('conversations.accept', $conversation))
        ->assertStatus(409)
        ->assertJson(['code' => 'closed']);
});

test('acting on a conversation that another agent now attends is an "assigned_to_other" conflict', function (string $route, array $input) {
    $conversation = Conversation::factory()->inDepartment($this->sales)->assignedTo($this->ana)->create();
    $version = $conversation->version;

    // A supervisor reassigns it to Beto while Ana still has it on screen.
    $this->actingAs($this->admin)
        ->post(route('conversations.assign', $conversation), ['user_id' => $this->beto->id, 'version' => $version])
        ->assertSessionHasNoErrors();

    $input = array_map(fn (mixed $value): mixed => $value === 'support' ? $this->support->id : $value, $input);

    $this->actingAs($this->ana)
        ->postJson(route($route, $conversation), [...$input, 'version' => $version])
        ->assertStatus(409)
        ->assertExactJson(['code' => 'assigned_to_other', 'message' => CONFLICT_TEXTS['assigned_to_other']]);

    // Even with the current version (the state, not the version, refuses it).
    $this->actingAs($this->ana)
        ->postJson(route($route, $conversation), [...$input, 'version' => $conversation->refresh()->version])
        ->assertStatus(409)
        ->assertJson(['code' => 'assigned_to_other']);

    expect($conversation->refresh())
        ->assigned_user_id->toBe($this->beto->id)
        ->status->toBe(ConversationStatus::Open);
})->with([
    'close' => ['conversations.close', []],
    'release' => ['conversations.release', []],
    'transfer' => ['conversations.transfer', ['department_id' => 'support']],
]);

test('replying to a conversation another agent attends or that was closed is a conflict, not a 403', function () {
    $conversation = Conversation::factory()->inDepartment($this->sales)->assignedTo($this->ana)->create(['last_inbound_at' => now()]);
    $this->actingAs($this->admin)->post(route('conversations.assign', $conversation), ['user_id' => $this->beto->id, 'version' => 0]);

    $this->actingAs($this->ana)
        ->postJson(route('conversations.messages.store', $conversation), ['body' => 'Hola', 'client_reference' => (string) str()->uuid()])
        ->assertStatus(409)
        ->assertExactJson(['code' => 'assigned_to_other', 'message' => CONFLICT_TEXTS['assigned_to_other']]);

    $this->actingAs($this->admin)->post(route('conversations.close', $conversation), ['version' => 1])->assertSessionHasNoErrors();

    $this->actingAs($this->beto)
        ->postJson(route('conversations.messages.store', $conversation), ['body' => 'Hola', 'client_reference' => (string) str()->uuid()])
        ->assertStatus(409)
        ->assertExactJson(['code' => 'closed', 'message' => CONFLICT_TEXTS['closed']]);

    expect($conversation->messages()->count())->toBe(0);
});

test('operating on a conversation closed meanwhile is a "closed" conflict', function (string $route, array $input) {
    $conversation = Conversation::factory()->inDepartment($this->sales)->assignedTo($this->ana)->create();
    $this->actingAs($this->admin)->post(route('conversations.close', $conversation), ['version' => 0])->assertSessionHasNoErrors();

    $input = array_map(fn (mixed $value): mixed => match ($value) {
        'support' => $this->support->id,
        'beto' => $this->beto->id,
        default => $value,
    }, $input);
    $actor = $route === 'conversations.assign' ? User::factory()->supervisor()->inDepartments($this->sales)->create() : $this->ana;

    foreach ([0, 1] as $version) {
        $this->actingAs($actor)
            ->postJson(route($route, $conversation), [...$input, 'version' => $version])
            ->assertStatus(409)
            ->assertExactJson(['code' => 'closed', 'message' => CONFLICT_TEXTS['closed']]);
    }

    $this->actingAs($actor)
        ->from(route('conversations.show', $conversation))
        ->post(route($route, $conversation), [...$input, 'version' => 1])
        ->assertSessionHasErrors(['conversation' => CONFLICT_TEXTS['closed'], 'conversation_conflict' => 'closed']);

    expect($conversation->refresh()->status)->toBe(ConversationStatus::Closed);
})->with([
    'close' => ['conversations.close', []],
    'release' => ['conversations.release', []],
    'transfer' => ['conversations.transfer', ['department_id' => 'support']],
    'assign' => ['conversations.assign', ['user_id' => 'beto']],
]);

test('the destinations of a conversation closed meanwhile answer the "closed" conflict', function () {
    $conversation = Conversation::factory()->inDepartment($this->sales)->assignedTo($this->ana)->closed()->create();

    $this->actingAs($this->ana)
        ->getJson(route('conversations.destinations', $conversation))
        ->assertStatus(409)
        ->assertExactJson(['code' => 'closed', 'message' => CONFLICT_TEXTS['closed']]);
});

test('state changes without another responsible are a "state_changed" conflict', function () {
    $reopened = Conversation::factory()->inDepartment($this->sales)->assignedTo($this->ana)->closed()->create();
    $this->actingAs($this->admin)->post(route('conversations.reopen', $reopened), ['version' => 0])->assertSessionHasNoErrors();

    // Someone else reopened it first (stale version, and also with the current one).
    foreach ([0, 1] as $version) {
        $this->actingAs($this->ana)
            ->postJson(route('conversations.reopen', $reopened), ['version' => $version])
            ->assertStatus(409)
            ->assertExactJson(['code' => 'state_changed', 'message' => CONFLICT_TEXTS['state_changed']]);
    }

    // Returned to the queue meanwhile: there is nothing to release any more.
    $released = Conversation::factory()->inDepartment($this->sales)->assignedTo($this->ana)->create();
    $this->actingAs($this->admin)->post(route('conversations.release', $released), ['version' => 0])->assertSessionHasNoErrors();

    $this->actingAs($this->ana)
        ->postJson(route('conversations.release', $released), ['version' => 1])
        ->assertStatus(409)
        ->assertExactJson(['code' => 'state_changed', 'message' => CONFLICT_TEXTS['state_changed']]);
});

test('a decision on a stale version is a conflict whose code explains what changed', function () {
    $conversation = Conversation::factory()->inDepartment($this->sales)->create();
    $this->actingAs($this->admin)->post(route('conversations.assign', $conversation), ['user_id' => $this->ana->id, 'version' => 0]);

    $this->actingAs($this->admin)
        ->postJson(route('conversations.assign', $conversation), ['user_id' => $this->beto->id, 'version' => 0])
        ->assertStatus(409)
        ->assertExactJson(['code' => 'assigned_to_other', 'message' => CONFLICT_TEXTS['assigned_to_other']]);

    // Back in the queue: nothing more specific than "it changed".
    $this->actingAs($this->admin)->post(route('conversations.release', $conversation), ['version' => 1])->assertSessionHasNoErrors();

    $this->actingAs($this->admin)
        ->postJson(route('conversations.assign', $conversation), ['user_id' => $this->beto->id, 'version' => 1])
        ->assertStatus(409)
        ->assertExactJson(['code' => 'state_changed', 'message' => CONFLICT_TEXTS['state_changed']]);

    expect($conversation->refresh()->assigned_user_id)->toBeNull();
});

test('a conversation outside the user\'s reach is always "not found", whatever its state', function (Closure $state, string $route, array $input) {
    $agent = User::factory()->agent()->inDepartments($this->sales)->create();
    $conversation = $state($this);

    $this->actingAs($agent)
        ->postJson(route($route, $conversation), [...$input, 'version' => 0])
        ->assertNotFound()
        ->assertExactJson(['message' => 'No se encontró la página o el recurso solicitado.']);

    $this->actingAs($agent)
        ->post(route($route, $conversation), [...$input, 'version' => 0])
        ->assertNotFound();
})->with([
    'closed, of a colleague' => fn ($test) => Conversation::factory()->inDepartment($test->sales)->assignedTo($test->ana)->closed()->create(),
    'open, of a colleague' => fn ($test) => Conversation::factory()->inDepartment($test->sales)->assignedTo($test->ana)->create(),
    'pending, in another queue' => fn ($test) => Conversation::factory()->inDepartment($test->support)->create(),
    'closed, in another queue' => fn ($test) => Conversation::factory()->inDepartment($test->support)->closed()->create(),
])->with([
    'close' => ['conversations.close', []],
    'reopen' => ['conversations.reopen', []],
    'release' => ['conversations.release', []],
    'transfer' => ['conversations.transfer', ['department_id' => 1]],
    'reply' => ['conversations.messages.store', ['body' => 'Hola', 'client_reference' => '1b4e28ba-2fa1-4d3b-8c5e-1234567890ab']],
]);

test('accepting is "not found" outside the queues one may accept from, whatever the state', function (string $state) {
    $agent = User::factory()->agent()->inDepartments($this->sales)->create();
    $factory = Conversation::factory()->inDepartment($this->support);
    $conversation = match ($state) {
        'pending' => $factory->create(),
        'open' => $factory->assignedTo($this->ana)->create(),
        'closed' => $factory->assignedTo($this->ana)->closed()->create(),
    };

    $this->actingAs($agent)
        ->postJson(route('conversations.accept', $conversation))
        ->assertNotFound()
        ->assertExactJson(['message' => 'No se encontró la página o el recurso solicitado.']);
})->with(['pending', 'open', 'closed']);

test('a closed conversation is "not found" for someone who cannot read closed conversations', function () {
    $agent = departmentAgent($this->sales, without: [Permission::ViewConversationHistory]);
    $conversation = Conversation::factory()->inDepartment($this->sales)->assignedTo($agent)->closed()->create();

    $this->actingAs($agent)
        ->postJson(route('conversations.reopen', $conversation), ['version' => 0])
        ->assertNotFound();

    $this->actingAs($agent)
        ->getJson(route('conversations.destinations', $conversation))
        ->assertNotFound();
});

test('the operations offered to the interface keep combining permission and state', function () {
    $conversation = Conversation::factory()->inDepartment($this->sales)->assignedTo($this->ana)->create();
    $closed = Conversation::factory()->inDepartment($this->sales)->assignedTo($this->ana)->closed()->create();

    expect($this->ana->can('close', $conversation))->toBeTrue()
        ->and($this->ana->can('reply', $conversation))->toBeTrue()
        ->and($this->beto->can('close', $conversation))->toBeFalse()
        ->and($this->beto->can('reply', $conversation))->toBeFalse()
        ->and($this->ana->can('close', $closed))->toBeFalse()
        ->and($this->ana->can('reply', $closed))->toBeFalse()
        ->and($this->ana->can('reopen', $closed))->toBeTrue()
        ->and($this->ana->can('reopen', $conversation))->toBeFalse();
});

test('other conflicts also answer {code, message}, keeping their own explanation', function () {
    $closed = Conversation::factory()->inDepartment($this->sales)->assignedTo($this->ana)->closed()->create();
    Conversation::factory()->for($closed->channelConnection()->firstOrFail(), 'channelConnection')->create(['contact_id' => $closed->contact_id]);

    $this->actingAs($this->admin)
        ->postJson(route('conversations.reopen', $closed), ['version' => 0])
        ->assertStatus(409)
        ->assertExactJson(['code' => 'conflict', 'message' => 'Este contacto ya tiene otra conversación activa en esta conexión; continúa la atención en ella.']);
});

test('a conversation that left the user\'s reach while the operation waited is a "state_changed" conflict without details', function () {
    $conversation = Conversation::factory()->inDepartment($this->support)->assignedTo($this->beto)->create();

    // Ana passed the visibility check of the request; meanwhile the
    // conversation moved to a department she does not see.
    expect(fn () => app(ReleaseConversation::class)->handle($conversation, $this->ana, null, 0))
        ->toThrow(fn (ConversationStateConflict $conflict) => expect($conflict->reason)->toBe(ConversationConflictReason::StateChanged)
            ->and($conflict->getMessage())->toBe(CONFLICT_TEXTS['state_changed']));

    expect($conversation->refresh()->status)->toBe(ConversationStatus::Open);
});

test('the responsible agent keeps replying, closing and releasing their own conversation whatever their reach rules', function () {
    $attendant = conversationUser([Permission::ReplyConversations, Permission::CloseConversations], DataScope::Departments, []);
    $conversation = Conversation::factory()->inDepartment($this->sales)->assignedTo($attendant)->create();

    expect($attendant->can('reply', $conversation))->toBeTrue()
        ->and($attendant->can('close', $conversation))->toBeTrue()
        ->and($attendant->can('release', $conversation))->toBeTrue()
        ->and($attendant->can('transfer', $conversation))->toBeFalse();
});

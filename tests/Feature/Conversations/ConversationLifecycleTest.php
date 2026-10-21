<?php

use App\Actions\Conversations\AcceptConversation;
use App\Actions\Conversations\AssignAutomatically;
use App\Actions\Conversations\ChangeAgentAvailability;
use App\Actions\Conversations\CloseInactiveConversations;
use App\Enums\AgentAvailability;
use App\Enums\ConversationEventType;
use App\Enums\ConversationStatus;
use App\Enums\DistributionMode;
use App\Enums\Permission;
use App\Models\Connection;
use App\Models\ContactAttribution;
use App\Models\Conversation;
use App\Models\ConversationEvent;
use App\Models\ConversationNote;
use App\Models\Department;
use App\Models\Message;
use App\Models\Tag;
use App\Models\User;
use App\Services\PlatformSettings;
use Illuminate\Database\QueryException;
use Inertia\Testing\AssertableInertia as Assert;

/*
| Operations of the conversation engine through their HTTP endpoints, as the
| operational view uses them.
*/

beforeEach(function () {
    $this->department = Department::factory()->create(['name' => 'Ventas']);
    $this->admin = User::factory()->administrator()->create();
    $this->agent = User::factory()->agent()->inDepartments($this->department)->create(['name' => 'Ana']);
    $this->colleague = User::factory()->agent()->inDepartments($this->department)->create(['name' => 'Beto']);
});

function pendingIn(Department $department): Conversation
{
    return Conversation::factory()->inDepartment($department)->create();
}

test('an agent accepts a pending conversation of their department and becomes responsible', function () {
    $conversation = pendingIn($this->department);

    $this->actingAs($this->agent)
        ->post(route('conversations.accept', $conversation))
        ->assertRedirect(route('conversations.show', $conversation))
        ->assertSessionHasNoErrors();

    $event = $conversation->events()->sole();

    expect($conversation->refresh())
        ->status->toBe(ConversationStatus::Open)
        ->assigned_user_id->toBe($this->agent->id)
        ->version->toBe(1)
        ->and($event)
        ->type->toBe(ConversationEventType::Accepted)
        ->actor_user_id->toBe($this->agent->id)
        ->to_department_id->toBe($this->department->id)
        ->and($this->agent->refresh()->last_assigned_at)->not->toBeNull();
});

test('accepting a conversation someone already took is a controlled conflict', function () {
    $queued = pendingIn($this->department);
    $this->actingAs($this->colleague)->post(route('conversations.accept', $queued));

    $this->actingAs($this->agent)
        ->from(route('conversations.index'))
        ->post(route('conversations.accept', $queued))
        ->assertRedirect(route('conversations.index'))
        ->assertSessionHasErrors(['conversation' => 'Otra persona ya aceptó esta conversación.']);

    $this->actingAs($this->agent)
        ->postJson(route('conversations.accept', $queued))
        ->assertStatus(409)
        ->assertExactJson(['message' => 'Otra persona ya aceptó esta conversación.']);

    expect($queued->refresh()->assigned_user_id)->toBe($this->colleague->id)
        ->and($queued->events()->count())->toBe(1);
});

test('losing the race from the conversation page returns the agent to the queue with the explanation', function () {
    $queued = pendingIn($this->department);
    $this->actingAs($this->colleague)->post(route('conversations.accept', $queued));

    $this->actingAs($this->agent)
        ->from(route('conversations.show', $queued))
        ->post(route('conversations.accept', $queued))
        ->assertRedirect(route('conversations.index'))
        ->assertSessionHasErrors(['conversation' => 'Otra persona ya aceptó esta conversación.']);

    $this->actingAs($this->agent)
        ->get(route('conversations.show', $queued))
        ->assertNotFound();
});

test('the queue keeps the user\'s filters when an operation or the breadcrumb returns to it', function () {
    $queued = pendingIn($this->department);
    $filters = ['status' => 'pending', 'department' => (string) $this->department->id, 'page' => 2];

    $this->actingAs($this->agent)->get(route('conversations.index', $filters))->assertOk();
    $this->actingAs($this->agent)
        ->get(route('conversations.show', $queued))
        ->assertInertia(fn (Assert $page) => $page->where('listUrl', route('conversations.index', $filters)));

    $this->actingAs($this->colleague)->post(route('conversations.accept', $queued));

    $this->actingAs($this->agent)
        ->from(route('conversations.show', $queued))
        ->post(route('conversations.accept', $queued))
        ->assertRedirect(route('conversations.index', $filters));
});

test('a database deadlock during an operation is answered as a controlled conflict', function () {
    $queued = pendingIn($this->department);
    $this->mock(AcceptConversation::class)->shouldReceive('handle')->andThrow(
        new QueryException('mariadb', 'update conversations', [], new PDOException('SQLSTATE[40001]: Serialization failure: 1213 Deadlock found when trying to get lock')),
    );

    $this->actingAs($this->agent)
        ->from(route('conversations.show', $queued))
        ->post(route('conversations.accept', $queued))
        ->assertRedirect(route('conversations.show', $queued))
        ->assertSessionHasErrors(['conversation' => 'Otra operación modificó esta conversación al mismo tiempo. Revisa su estado actual e inténtalo de nuevo.']);

    $this->actingAs($this->agent)
        ->postJson(route('conversations.accept', $queued))
        ->assertStatus(409);
});

test('accepting outside the queues one may accept from looks like a missing record', function () {
    $otherQueue = pendingIn(Department::factory()->create());
    $taken = Conversation::factory()->inDepartment(Department::factory()->create())->assignedTo($this->colleague)->create();

    $this->actingAs($this->agent)->post(route('conversations.accept', $otherQueue))->assertNotFound();
    $this->actingAs($this->agent)->post(route('conversations.accept', $taken))->assertNotFound();

    expect($otherQueue->refresh()->assigned_user_id)->toBeNull();
});

test('a supervisor who can see the queue gets the explanation when the conversation was already accepted', function () {
    $supervisor = User::factory()->supervisor()->inDepartments($this->department)->create();
    $conversation = pendingIn($this->department);
    $this->actingAs($this->colleague)->post(route('conversations.accept', $conversation));

    $this->actingAs($supervisor)
        ->from(route('conversations.show', $conversation))
        ->post(route('conversations.accept', $conversation))
        ->assertRedirect(route('conversations.show', $conversation))
        ->assertSessionHasErrors(['conversation' => 'Otra persona ya aceptó esta conversación.']);

    $this->actingAs($supervisor)
        ->postJson(route('conversations.accept', $conversation))
        ->assertStatus(409)
        ->assertExactJson(['message' => 'Otra persona ya aceptó esta conversación.']);
});

test('the attention limit stops self service but not an assignment by a supervisor', function () {
    app(PlatformSettings::class)->update(['operations' => ['max_open_conversations_per_agent' => 1]]);
    Conversation::factory()->inDepartment($this->department)->assignedTo($this->agent)->create();
    $conversation = pendingIn($this->department);

    $this->actingAs($this->agent)
        ->from(route('conversations.index'))
        ->post(route('conversations.accept', $conversation))
        ->assertSessionHasErrors(['conversation' => 'Ya atiendes el máximo de 1 conversaciones permitido. Cierra o libera alguna antes de aceptar otra.']);

    $this->actingAs($this->admin)
        ->post(route('conversations.assign', $conversation), ['user_id' => $this->agent->id, 'version' => 0])
        ->assertSessionHasNoErrors();

    expect($conversation->refresh()->assigned_user_id)->toBe($this->agent->id);
});

test('departments that distribute manually do not let agents accept', function () {
    $this->department->forceFill(['distribution_mode' => DistributionMode::Manual])->save();
    $conversation = pendingIn($this->department);

    expect($this->agent->can('accept', $conversation))->toBeFalse()
        ->and($this->agent->can('view', $conversation))->toBeFalse();

    $this->actingAs($this->agent)->post(route('conversations.accept', $conversation))->assertNotFound();
});

test('an administrator assigns and reassigns, and every change is recorded', function () {
    $conversation = pendingIn($this->department);

    $this->actingAs($this->admin)
        ->post(route('conversations.assign', $conversation), ['user_id' => $this->agent->id, 'version' => 0])
        ->assertSessionHasNoErrors();
    $this->actingAs($this->admin)
        ->post(route('conversations.assign', $conversation), ['user_id' => $this->colleague->id, 'version' => 1])
        ->assertSessionHasNoErrors();

    $events = $conversation->events()->orderBy('id')->get();

    expect($conversation->refresh())
        ->assigned_user_id->toBe($this->colleague->id)
        ->status->toBe(ConversationStatus::Open)
        ->version->toBe(2)
        ->and($events->pluck('type')->all())->toBe([ConversationEventType::Assigned, ConversationEventType::Assigned])
        ->and($events[1])
        ->from_user_id->toBe($this->agent->id)
        ->to_user_id->toBe($this->colleague->id)
        ->actor_user_id->toBe($this->admin->id);
});

test('a decision taken on stale data is rejected', function () {
    $conversation = pendingIn($this->department);
    $this->actingAs($this->admin)->post(route('conversations.assign', $conversation), ['user_id' => $this->agent->id, 'version' => 0]);

    $this->actingAs($this->admin)
        ->from(route('conversations.show', $conversation))
        ->post(route('conversations.assign', $conversation), ['user_id' => $this->colleague->id, 'version' => 0])
        ->assertSessionHasErrors(['conversation' => 'La conversación cambió mientras la consultabas. Revisa su estado actual e inténtalo de nuevo.']);

    expect($conversation->refresh()->assigned_user_id)->toBe($this->agent->id);
});

test('conversations are never assigned to someone who cannot attend them', function (Closure $makeTarget, string $message) {
    $conversation = pendingIn($this->department);
    $target = $makeTarget($this->department);

    $this->actingAs($this->admin)
        ->from(route('conversations.show', $conversation))
        ->post(route('conversations.assign', $conversation), ['user_id' => $target?->id ?? 999999, 'version' => 0])
        ->assertSessionHasErrors(['conversation' => str_replace(':name', (string) $target?->name, $message)]);

    expect($conversation->refresh())->status->toBe(ConversationStatus::Pending)->assigned_user_id->toBeNull()
        ->and($conversation->events()->count())->toBe(0);
})->with([
    'inexistent user' => [fn () => null, 'El agente elegido no existe.'],
    'deactivated user' => [fn (Department $department) => User::factory()->agent()->inactive()->inDepartments($department)->create(), ':name está desactivado.'],
    'not a member of the department' => [fn () => User::factory()->agent()->inDepartments(Department::factory()->create())->create(), ':name no pertenece al departamento Ventas.'],
    'role that cannot reply' => [fn (Department $department) => conversationUser([Permission::ViewConversations], departments: [$department]), 'El rol de :name no le permite atender conversaciones.'],
]);

test('conversations of an inactive department cannot be assigned', function () {
    $conversation = pendingIn($this->department);
    $this->department->forceFill(['is_active' => false])->save();

    $this->actingAs($this->admin)
        ->from(route('conversations.show', $conversation))
        ->post(route('conversations.assign', $conversation), ['user_id' => $this->agent->id, 'version' => 0])
        ->assertSessionHasErrors(['conversation' => 'El departamento Ventas está inactivo.']);
});

test('a transfer to another department keeps messages, notes, tags and origin, and records the trace', function () {
    $support = Department::factory()->create(['name' => 'Soporte']);
    $receiver = User::factory()->agent()->inDepartments($support)->create();
    $conversation = Conversation::factory()->inDepartment($this->department)->assignedTo($this->agent)->create();
    Message::factory()->count(3)->for($conversation)->create();
    ConversationNote::factory()->for($conversation)->create(['user_id' => $this->agent->id]);
    $tag = Tag::factory()->create();
    $conversation->tags()->attach($tag->id, ['created_at' => now()]);
    ContactAttribution::factory()->create(['contact_id' => $conversation->contact_id, 'conversation_id' => $conversation->id]);

    $this->actingAs($this->agent)
        ->post(route('conversations.transfer', $conversation), [
            'department_id' => $support->id,
            'user_id' => $receiver->id,
            'reason' => 'Requiere revisión técnica',
            'version' => 0,
        ])
        ->assertRedirect(route('conversations.index'))
        ->assertSessionHasNoErrors();

    $event = $conversation->events()->sole();

    expect($conversation->refresh())
        ->department_id->toBe($support->id)
        ->assigned_user_id->toBe($receiver->id)
        ->status->toBe(ConversationStatus::Open)
        ->and($conversation->messages()->count())->toBe(3)
        ->and($conversation->notes()->count())->toBe(1)
        ->and($conversation->tags()->pluck('tags.id')->all())->toBe([$tag->id])
        ->and($conversation->attributions()->count())->toBe(1)
        ->and($event)
        ->type->toBe(ConversationEventType::Transferred)
        ->actor_user_id->toBe($this->agent->id)
        ->from_user_id->toBe($this->agent->id)
        ->to_user_id->toBe($receiver->id)
        ->from_department_id->toBe($this->department->id)
        ->to_department_id->toBe($support->id)
        ->note->toBe('Requiere revisión técnica')
        ->and($this->agent->can('view', $conversation))->toBeFalse();
});

test('without a receiving agent the transfer leaves the conversation pending in the new queue', function () {
    $support = Department::factory()->create();
    $conversation = Conversation::factory()->inDepartment($this->department)->assignedTo($this->agent)->create();

    $this->actingAs($this->agent)
        ->post(route('conversations.transfer', $conversation), ['department_id' => $support->id, 'version' => 0])
        ->assertSessionHasNoErrors();

    expect($conversation->refresh())
        ->status->toBe(ConversationStatus::Pending)
        ->assigned_user_id->toBeNull()
        ->department_id->toBe($support->id);
});

test('transfers to inactive, unauthorized or identical destinations are refused', function () {
    $connection = Connection::factory()->create();
    $authorized = Department::factory()->create();
    $unauthorized = Department::factory()->create(['name' => 'Cobranza']);
    $inactive = Department::factory()->inactive()->create();
    $connection->departments()->attach([$this->department->id, $authorized->id, $inactive->id]);
    $conversation = Conversation::factory()->for($connection, 'channelConnection')->inDepartment($this->department)->assignedTo($this->agent)->create();

    $attempt = fn (array $input) => $this->actingAs($this->admin)
        ->from(route('conversations.show', $conversation))
        ->post(route('conversations.transfer', $conversation), [...$input, 'version' => 0]);

    $attempt(['department_id' => $inactive->id])->assertSessionHasErrors(['conversation' => 'El departamento de destino está inactivo.']);
    $attempt(['department_id' => $unauthorized->id])->assertSessionHasErrors(['conversation' => "El departamento Cobranza no está autorizado para recibir conversaciones de la conexión {$connection->name}."]);
    $attempt(['user_id' => $this->agent->id])->assertSessionHasErrors(['conversation' => 'Elige un destino distinto al actual.']);
    $attempt([])->assertSessionHasErrors(['department_id', 'user_id']);

    expect($conversation->refresh()->version)->toBe(0)
        ->and($conversation->events()->count())->toBe(0);
});

test('the transfer reason is required only when the configuration asks for it', function () {
    app(PlatformSettings::class)->update(['operations' => ['transfer_reason_required' => true]]);
    $conversation = Conversation::factory()->inDepartment($this->department)->assignedTo($this->agent)->create();

    $this->actingAs($this->agent)
        ->post(route('conversations.transfer', $conversation), ['user_id' => $this->colleague->id, 'reason' => '   ', 'version' => 0])
        ->assertSessionHasErrors(['reason' => 'Indica el motivo de la transferencia.']);

    $this->actingAs($this->agent)
        ->post(route('conversations.transfer', $conversation), ['user_id' => $this->colleague->id, 'reason' => 'Cambio de turno', 'version' => 0])
        ->assertSessionHasNoErrors();

    expect($conversation->refresh()->assigned_user_id)->toBe($this->colleague->id);
});

test('hold, resume, release, close and reopen follow the state matrix and are recorded', function () {
    $conversation = Conversation::factory()->inDepartment($this->department)->assignedTo($this->agent)->create();
    $post = fn (string $route, int $version, array $input = [], ?User $actor = null) => $this->actingAs($actor ?? $this->agent)
        ->from(route('conversations.show', $conversation))
        ->post(route($route, $conversation), [...$input, 'version' => $version])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $post('conversations.hold', 0, ['reason' => 'Esperando comprobante de pago']);
    expect($conversation->refresh())
        ->status->toBe(ConversationStatus::OnHold)
        ->on_hold_reason->toBe('Esperando comprobante de pago')
        ->assigned_user_id->toBe($this->agent->id)
        ->on_hold_at->not->toBeNull();

    $post('conversations.resume', 1);
    expect($conversation->refresh())->status->toBe(ConversationStatus::Open)->on_hold_at->toBeNull();

    $post('conversations.close', 2, ['reason' => 'Resuelto']);
    expect($conversation->refresh())
        ->status->toBe(ConversationStatus::Closed)
        ->closed_by_user_id->toBe($this->agent->id)
        ->assigned_user_id->toBe($this->agent->id);

    $post('conversations.reopen', 3, actor: $this->admin);
    expect($conversation->refresh())
        ->status->toBe(ConversationStatus::Open)
        ->assigned_user_id->toBe($this->agent->id)
        ->reopen_count->toBe(1);

    $this->actingAs($this->agent)->post(route('conversations.release', $conversation), ['version' => 4])->assertSessionHasNoErrors();
    expect($conversation->refresh())->status->toBe(ConversationStatus::Pending)->assigned_user_id->toBeNull();

    expect($conversation->events()->orderBy('id')->pluck('type')->all())->toBe([
        ConversationEventType::PutOnHold,
        ConversationEventType::Resumed,
        ConversationEventType::Closed,
        ConversationEventType::Reopened,
        ConversationEventType::Unassigned,
    ])->and($conversation->events()->where('type', ConversationEventType::Reopened)->sole()->metadata)->toBe(['trigger' => 'manual'])
        ->and($conversation->events()->where('type', ConversationEventType::Closed)->sole()->note)->toBe('Resuelto');
});

test('transitions outside the matrix are rejected', function (string $state, string $route) {
    $conversation = Conversation::factory()->inDepartment($this->department)->assignedTo($this->agent)->create(['status' => $state]);

    $this->actingAs($this->admin)
        ->from(route('conversations.show', $conversation))
        ->post(route($route, $conversation), ['version' => 0])
        ->assertForbidden();

    expect($conversation->refresh()->status->value)->toBe($state);
})->with([
    'resume an open conversation' => ['open', 'conversations.resume'],
    'hold a conversation on hold' => ['on_hold', 'conversations.hold'],
    'reopen an open conversation' => ['open', 'conversations.reopen'],
    'close a closed conversation' => ['closed', 'conversations.close'],
    'hold a pending conversation' => ['pending', 'conversations.hold'],
]);

test('the state matrix only allows the documented transitions', function () {
    expect(ConversationStatus::Pending->canTransitionTo(ConversationStatus::OnHold))->toBeFalse()
        ->and(ConversationStatus::Closed->canTransitionTo(ConversationStatus::OnHold))->toBeFalse()
        ->and(ConversationStatus::Closed->canTransitionTo(ConversationStatus::Closed))->toBeFalse()
        ->and(ConversationStatus::OnHold->canTransitionTo(ConversationStatus::OnHold))->toBeFalse()
        ->and(ConversationStatus::OnHold->canTransitionTo(ConversationStatus::Open))->toBeTrue()
        ->and(ConversationStatus::Closed->canTransitionTo(ConversationStatus::Pending))->toBeTrue();
});

test('reopening is refused while the contact has another active conversation on the connection', function () {
    $closed = Conversation::factory()->inDepartment($this->department)->assignedTo($this->agent)->closed()->create();
    Conversation::factory()->for($closed->channelConnection()->firstOrFail(), 'channelConnection')->create(['contact_id' => $closed->contact_id]);

    $this->actingAs($this->admin)
        ->from(route('conversations.show', $closed))
        ->post(route('conversations.reopen', $closed), ['version' => 0])
        ->assertSessionHasErrors(['conversation' => 'Este contacto ya tiene otra conversación activa en esta conexión; continúa la atención en ella.']);

    expect($closed->refresh()->status)->toBe(ConversationStatus::Closed)
        ->and($closed->events()->count())->toBe(0);
});

test('internal notes are stored apart from messages and edited only by their author', function () {
    $conversation = Conversation::factory()->inDepartment($this->department)->assignedTo($this->agent)->create();

    $this->actingAs($this->agent)
        ->post(route('conversations.notes.store', $conversation), ['body' => "  El cliente pidió factura\u{0007}  "])
        ->assertSessionHasNoErrors();

    $note = $conversation->notes()->sole();

    expect($note)->body->toBe('El cliente pidió factura')->user_id->toBe($this->agent->id)
        ->and(Message::query()->count())->toBe(0)
        ->and($conversation->refresh()->last_message_at?->toDateTimeString())->toBe($conversation->getOriginal('last_message_at')?->toDateTimeString());

    $this->actingAs($this->admin)
        ->put(route('conversations.notes.update', [$conversation, $note]), ['body' => 'Texto cambiado por otra persona'])
        ->assertForbidden();

    $this->actingAs($this->agent)
        ->put(route('conversations.notes.update', [$conversation, $note]), ['body' => 'El cliente pidió factura a nombre de su empresa'])
        ->assertSessionHasNoErrors();

    expect($note->refresh())->body->toBe('El cliente pidió factura a nombre de su empresa')->edited_at->not->toBeNull()
        ->and($conversation->events()->pluck('type')->all())->toBe([ConversationEventType::NoteAdded, ConversationEventType::NoteEdited]);
});

test('a note cannot be edited through another conversation', function () {
    $conversation = Conversation::factory()->inDepartment($this->department)->assignedTo($this->agent)->create();
    $other = Conversation::factory()->inDepartment($this->department)->assignedTo($this->agent)->create();
    $note = ConversationNote::factory()->for($other)->create(['user_id' => $this->agent->id]);

    $this->actingAs($this->agent)
        ->put(route('conversations.notes.update', [$conversation, $note]), ['body' => 'Cambio'])
        ->assertNotFound();
});

test('automatic distribution picks the available eligible agent with the lowest load', function () {
    $department = Department::factory()->distribution(DistributionMode::Automatic)->create();
    $busy = User::factory()->agent()->available()->inDepartments($department)->create();
    $free = User::factory()->agent()->available()->inDepartments($department)->create();
    User::factory()->agent()->inDepartments($department)->create(['availability' => AgentAvailability::Unavailable]);
    User::factory()->agent()->available()->inactive()->inDepartments($department)->create();
    Conversation::factory()->inDepartment($department)->assignedTo($busy)->create();

    $conversations = collect(range(1, 3))->map(fn () => tap(pendingIn($department), fn (Conversation $conversation) => DB::transaction(
        fn () => app(AssignAutomatically::class)->handle(Conversation::query()->lockForUpdate()->findOrFail($conversation->id)),
    )));

    expect($conversations->map(fn (Conversation $conversation) => $conversation->refresh()->assigned_user_id)->all())
        ->toBe([$free->id, $busy->id, $free->id]);
});

test('automatic distribution respects the attention limit and leaves the rest in the queue', function () {
    app(PlatformSettings::class)->update(['operations' => ['max_open_conversations_per_agent' => 1]]);
    $department = Department::factory()->distribution(DistributionMode::Automatic)->create();
    $agent = User::factory()->agent()->available()->inDepartments($department)->create();
    Conversation::factory()->inDepartment($department)->assignedTo($agent)->create();
    $conversation = pendingIn($department);

    $assigned = DB::transaction(fn () => app(AssignAutomatically::class)->handle(Conversation::query()->lockForUpdate()->findOrFail($conversation->id)));

    expect($assigned)->toBeNull()
        ->and($conversation->refresh()->status)->toBe(ConversationStatus::Pending);
});

test('becoming available receives the waiting conversations, becoming unavailable keeps the current ones', function () {
    $department = Department::factory()->distribution(DistributionMode::Automatic)->create();
    $agent = User::factory()->agent()->inDepartments($department)->create();
    $waiting = pendingIn($department);
    $owned = Conversation::factory()->inDepartment($department)->assignedTo($agent)->create();

    $this->actingAs($agent)
        ->put(route('conversations.availability.update'), ['availability' => 'available'])
        ->assertSessionHasNoErrors();

    expect($waiting->refresh()->assigned_user_id)->toBe($agent->id)
        ->and($agent->refresh()->availability)->toBe(AgentAvailability::Available);

    app(ChangeAgentAvailability::class)->handle($agent, AgentAvailability::Unavailable);

    expect($owned->refresh()->assigned_user_id)->toBe($agent->id)
        ->and($waiting->refresh()->assigned_user_id)->toBe($agent->id);
});

test('releasing in an automatic department offers the conversation to another agent', function () {
    $department = Department::factory()->distribution(DistributionMode::Automatic)->create();
    $releasing = User::factory()->agent()->available()->inDepartments($department)->create();
    $other = User::factory()->agent()->available()->inDepartments($department)->create();
    $conversation = Conversation::factory()->inDepartment($department)->assignedTo($releasing)->create();

    $this->actingAs($releasing)->post(route('conversations.release', $conversation), ['version' => 0])->assertSessionHasNoErrors();

    expect($conversation->refresh()->assigned_user_id)->toBe($other->id);
});

test('switching a department to automatic distributes its waiting queue', function () {
    $agent = User::factory()->agent()->available()->inDepartments($this->department)->create();
    $waiting = pendingIn($this->department);

    $this->actingAs($this->admin)
        ->put(route('departments.distribution.update', $this->department), ['distribution_mode' => 'automatic'])
        ->assertSessionHasNoErrors();

    expect($this->department->refresh()->distribution_mode)->toBe(DistributionMode::Automatic)
        ->and($waiting->refresh()->assigned_user_id)->toBe($agent->id);
});

test('only users who edit the department change its distribution', function () {
    $this->actingAs($this->agent)
        ->put(route('departments.distribution.update', $this->department), ['distribution_mode' => 'automatic'])
        ->assertForbidden();

    $this->actingAs($this->admin)
        ->put(route('departments.distribution.update', $this->department), ['distribution_mode' => 'round_robin'])
        ->assertSessionHasErrors('distribution_mode');

    expect($this->department->refresh()->distribution_mode)->toBe(DistributionMode::Shared);
});

test('conversations without messages for the configured hours are closed by the system', function () {
    app(PlatformSettings::class)->update(['operations' => ['auto_close_after_hours' => 24]]);
    $stale = Conversation::factory()->inDepartment($this->department)->assignedTo($this->agent)->create(['last_message_at' => now()->subHours(25)]);
    $recent = Conversation::factory()->inDepartment($this->department)->create(['last_message_at' => now()->subHours(2)]);

    $this->artisan('conversations:close-inactive')->assertSuccessful();

    $event = $stale->events()->sole();

    expect($stale->refresh())->status->toBe(ConversationStatus::Closed)->closed_by_user_id->toBeNull()
        ->and($recent->refresh()->status)->toBe(ConversationStatus::Pending)
        ->and($event)->actor_user_id->toBeNull()->note->toBe('Cerrada automáticamente tras 24 horas sin mensajes.');
});

test('the inactivity closing does nothing while it is not configured', function () {
    Conversation::factory()->create(['last_message_at' => now()->subYear()]);

    expect(app(CloseInactiveConversations::class)->handle())->toBe(0)
        ->and(ConversationEvent::query()->count())->toBe(0);
});

<?php

use App\Enums\ConversationStatus;
use App\Enums\DataScope;
use App\Enums\DefaultRole;
use App\Enums\MessageDirection;
use App\Enums\Permission;
use App\Events\Messaging\MessageRecorded;
use App\Jobs\DeliverOutboundMessage;
use App\Models\Conversation;
use App\Models\Department;
use App\Models\Message;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

/*
| conversations.intervene: write to the customer of an open conversation
| another person attends, within the reach of the role, without taking it.
| Granted by default to Superadministrador and Administrador only; the
| responsible agent, the assignment and the department never change, the
| message is authored by whoever wrote it, the 24 hour window and closed
| conversations keep their rules, and templates keep needing templates.send.
| Delivery is faked: nothing reaches Meta.
*/

beforeEach(function () {
    Queue::fake([DeliverOutboundMessage::class]);
    $this->travelTo(CarbonImmutable::parse('2026-11-11 18:00:00', 'UTC'));

    $this->sales = Department::factory()->create(['name' => 'Ventas']);
    $this->support = Department::factory()->create(['name' => 'Soporte']);
    $this->agent = User::factory()->agent()->available()->inDepartments($this->sales)->create(['name' => 'Gestora Ana']);
    $this->conversation = Conversation::factory()->inDepartment($this->sales)->assignedTo($this->agent)->create(['last_inbound_at' => now()->subHour()]);
});

function interveneReply(User $user, Conversation $conversation, string $body = 'Le ayudo yo con gusto.')
{
    return test()->actingAs($user)->postJson(route('conversations.messages.store', $conversation), [
        'body' => $body,
        'client_reference' => (string) Str::uuid(),
    ]);
}

test('only the root and administrator roles hold the intervention permission by default', function () {
    expect(DefaultRole::SuperAdministrator->permissions())->toContain(Permission::InterveneConversations)
        ->and(DefaultRole::Administrator->permissions())->toContain(Permission::InterveneConversations)
        ->and(DefaultRole::Supervisor->permissions())->not->toContain(Permission::InterveneConversations)
        ->and(DefaultRole::Agent->permissions())->not->toContain(Permission::InterveneConversations)
        ->and(Permission::InterveneConversations->requiresGlobalScope())->toBeFalse()
        ->and(Permission::InterveneConversations->isReservedToRoot())->toBeFalse();
});

test('a superadministrator and an administrator reply to a conversation another agent attends, without taking it', function (string $role) {
    Event::fake([MessageRecorded::class]);
    $admin = User::factory()->{$role}()->create();
    $before = $this->conversation->only(['assigned_user_id', 'department_id', 'status', 'assigned_at']);

    $this->actingAs($admin)
        ->get(route('conversations.index', ['c' => $this->conversation->id]))
        ->assertInertia(fn (Assert $page) => $page->where('conversation.can.reply', true));

    interveneReply($admin, $this->conversation)->assertSuccessful();

    $message = Message::query()->sole();
    $conversation = $this->conversation->fresh();

    expect($message->sender_user_id)->toBe($admin->id)
        ->and($message->direction)->toBe(MessageDirection::Outbound)
        ->and($message->body)->toBe('Le ayudo yo con gusto.')
        ->and($conversation->only(['assigned_user_id', 'department_id', 'status', 'assigned_at']))->toEqual($before)
        ->and($conversation->assigned_user_id)->toBe($this->agent->id);

    Queue::assertPushed(DeliverOutboundMessage::class, fn (DeliverOutboundMessage $job): bool => $job->messageId === $message->id);
    Event::assertDispatched(MessageRecorded::class, fn (MessageRecorded $event): bool => $event->messageId === $message->id && $event->conversationId === $conversation->id);

    // The responsible agent keeps replying as before.
    interveneReply($this->agent, $conversation, 'Gracias por esperar.')->assertSuccessful();
    expect(Message::query()->where('sender_user_id', $this->agent->id)->count())->toBe(1);
})->with(['superAdministrator', 'administrator']);

test('an administrator limited to some departments intervenes only within them', function () {
    $admin = User::factory()->withRole(DefaultRole::Administrator)->create();
    $scoped = conversationUser([Permission::ViewConversations, Permission::ReplyConversations, Permission::InterveneConversations], DataScope::Departments, [$this->sales]);
    $foreign = Conversation::factory()->inDepartment($this->support)
        ->assignedTo(User::factory()->agent()->inDepartments($this->support)->create())
        ->create(['last_inbound_at' => now()->subHour()]);

    interveneReply($scoped, $this->conversation)->assertSuccessful();
    interveneReply($scoped, $foreign)->assertNotFound();

    expect(Message::query()->where('conversation_id', $foreign->id)->exists())->toBeFalse()
        ->and($admin->can('reply', $foreign))->toBeTrue();
});

test('a supervisor replies to colleagues\' conversations only when their role is granted the permission', function () {
    $supervisor = User::factory()->supervisor()->inDepartments($this->sales)->create();

    expect($supervisor->can('reply', $this->conversation))->toBeFalse();
    interveneReply($supervisor, $this->conversation)->assertForbidden();

    $role = $supervisor->roles()->sole();
    $role->givePermissionTo(Permission::InterveneConversations->value);
    $supervisor = $supervisor->fresh();

    expect($supervisor->can('reply', $this->conversation))->toBeTrue();
    interveneReply($supervisor, $this->conversation)->assertSuccessful();

    expect(Message::query()->sole()->sender_user_id)->toBe($supervisor->id)
        ->and($this->conversation->fresh()->assigned_user_id)->toBe($this->agent->id);
});

test('an agent never replies to a conversation of a colleague, through the interface or a direct request', function () {
    $colleague = User::factory()->agent()->inDepartments($this->sales)->create();

    $this->actingAs($colleague)
        ->get(route('conversations.index', ['c' => $this->conversation->id]));

    expect($colleague->can('reply', $this->conversation))->toBeFalse();
    expect(interveneReply($colleague, $this->conversation)->status())->toBeIn([403, 404]);
    expect(Message::query()->exists())->toBeFalse();
});

test('a custom role needs both the reply and the intervention permissions, within its reach', function () {
    $onlyIntervene = conversationUser([Permission::ViewConversations, Permission::InterveneConversations], DataScope::Global);
    $onlyReply = conversationUser([Permission::ViewConversations, Permission::ReplyConversations], DataScope::Global);
    $both = conversationUser([Permission::ViewConversations, Permission::ReplyConversations, Permission::InterveneConversations], DataScope::Global);

    interveneReply($onlyIntervene, $this->conversation)->assertForbidden();
    interveneReply($onlyReply, $this->conversation)->assertForbidden();
    interveneReply($both, $this->conversation)->assertSuccessful();

    expect(Message::query()->pluck('sender_user_id')->all())->toBe([$both->id]);
});

test('intervening keeps the 24 hour window: free text is refused once it closed', function () {
    $admin = User::factory()->administrator()->create();
    $this->conversation->forceFill(['last_inbound_at' => now()->subHours(25)])->save();

    interveneReply($admin, $this->conversation)->assertStatus(409);

    expect(Message::query()->exists())->toBeFalse();
});

test('a closed conversation is answered only after reopening it, also by an administrator', function () {
    $admin = User::factory()->administrator()->create();
    $this->conversation->forceFill(['status' => ConversationStatus::Closed, 'closed_at' => now()])->save();

    expect($admin->can('reply', $this->conversation->fresh()))->toBeFalse();
    interveneReply($admin, $this->conversation)->assertStatus(409);
    expect(Message::query()->exists())->toBeFalse();

    $this->actingAs($admin)
        ->post(route('conversations.reopen', $this->conversation), ['version' => $this->conversation->fresh()->version])
        ->assertSessionHasNoErrors();

    $reopened = $this->conversation->fresh();

    if ($reopened->status === ConversationStatus::Open) {
        interveneReply($admin, $reopened)->assertSuccessful();
        expect(Message::query()->sole()->sender_user_id)->toBe($admin->id);
    } else {
        // Reopened as pending: it waits for someone to accept it first.
        expect($reopened->status)->toBe(ConversationStatus::Pending);
        interveneReply($admin, $reopened)->assertStatus(409);
    }
});

test('intervening never grants templates: they keep needing templates.send and the responsible agent', function () {
    $intervener = conversationUser([Permission::ViewConversations, Permission::ReplyConversations, Permission::InterveneConversations], DataScope::Global);
    $administrator = User::factory()->administrator()->create();

    expect($intervener->can('reply', $this->conversation))->toBeTrue()
        ->and($intervener->can('sendTemplate', $this->conversation))->toBeFalse()
        ->and($intervener->holdsPermission(Permission::SendTemplates))->toBeFalse()
        ->and($administrator->holdsPermission(Permission::SendTemplates))->toBeTrue()
        // Even holding templates.send, an intervening administrator sends no
        // template on a conversation someone else attends (Meta may charge it).
        ->and($administrator->can('reply', $this->conversation))->toBeTrue()
        ->and($administrator->can('sendTemplate', $this->conversation))->toBeFalse();
});

test('a pending conversation is still accepted first: intervening is not a way around the queue', function () {
    $admin = User::factory()->administrator()->create();
    $pending = Conversation::factory()->inDepartment($this->sales)->create(['last_inbound_at' => now()->subMinutes(5)]);

    expect($pending->status)->toBe(ConversationStatus::Pending)
        ->and($admin->can('reply', $pending))->toBeFalse();
    interveneReply($admin, $pending)->assertStatus(409);
});

<?php

use App\Actions\Conversations\AssignAutomatically;
use App\Enums\ConversationStatus;
use App\Enums\DistributionMode;
use App\Enums\MessageDirection;
use App\Enums\MessageStatus;
use App\Enums\MessageType;
use App\Enums\Permission;
use App\Jobs\DeliverOutboundMessage;
use App\Models\Connection;
use App\Models\Conversation;
use App\Models\ConversationRead;
use App\Models\Department;
use App\Models\Role;
use App\Models\TeamChat\TeamThread;
use App\Models\User;
use App\Services\Conversations\PendingAttention;
use App\Services\PlatformSettings;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;

/*
| Control of new conversations: an agent whose customers have been waiting
| for their answer longer than the tolerance cannot accept another one nor
| receive one automatically. Waiting for the customer, queued, closed or
| reassigned conversations never block; administrators may still assign;
| the internal chat is never limited.
*/

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-02 16:00', 'UTC'));
    app(PlatformSettings::class)->update(['operations' => ['pending_block_enabled' => true, 'pending_block_after_minutes' => 10]]);

    $this->department = Department::factory()->create(['name' => 'Soporte']);
    $this->agent = User::factory()->agent()->available()->inDepartments($this->department)->create(['name' => 'Ana']);
    $this->queued = Conversation::factory()->inDepartment($this->department)->create();
});

/**
 * A conversation attended by the agent whose customer wrote at the given
 * moment and that the team last answered at the other one (null: never).
 */
function agentConversationWaitingSince(User $agent, Department $department, string $customerWrote, ?string $teamAnswered = null, ConversationStatus $status = ConversationStatus::Open): Conversation
{
    return Conversation::factory()->inDepartment($department)->assignedTo($agent)->create([
        'status' => $status,
        'last_inbound_at' => CarbonImmutable::parse($customerWrote, 'UTC'),
        'last_outbound_at' => $teamAnswered === null ? null : CarbonImmutable::parse($teamAnswered, 'UTC'),
        // Assigned when the customer wrote: the agent's wait starts there.
        'assigned_at' => CarbonImmutable::parse($customerWrote, 'UTC'),
    ]);
}

function acceptAs(object $test, User $agent, Conversation $conversation): TestResponse
{
    return $test->actingAs($agent)->from(route('conversations.index'))->post(route('conversations.accept', $conversation));
}

test('a customer waiting longer than the tolerance blocks accepting a new conversation', function () {
    agentConversationWaitingSince($this->agent, $this->department, '2026-11-02 15:45');

    acceptAs($this, $this->agent, $this->queued)
        ->assertSessionHasErrors(['conversation' => 'Tienes conversaciones pendientes de atención. Revísalas antes de aceptar una nueva.']);

    expect($this->queued->fresh())->status->toBe(ConversationStatus::Pending)
        ->assigned_user_id->toBeNull();
});

test('conversations that do not need the agent now never block accepting', function (string $customerWrote, ?string $teamAnswered, ConversationStatus $status) {
    agentConversationWaitingSince($this->agent, $this->department, $customerWrote, $teamAnswered, $status);

    acceptAs($this, $this->agent, $this->queued)->assertSessionHasNoErrors();

    expect($this->queued->fresh())->status->toBe(ConversationStatus::Open)
        ->assigned_user_id->toBe($this->agent->id);
})->with([
    'customer waiting within the tolerance' => ['2026-11-02 15:55', null, ConversationStatus::Open],
    'waiting for the customer (the agent answered last)' => ['2026-11-02 15:00', '2026-11-02 15:05', ConversationStatus::Open],
    'closed conversation' => ['2026-11-02 15:00', null, ConversationStatus::Closed],
]);

test('a conversation that waited in the queue gives the agent who takes it the whole tolerance', function () {
    $waited = agentConversationWaitingSince($this->agent, $this->department, '2026-11-02 15:00');
    $waited->forceFill(['assigned_at' => CarbonImmutable::parse('2026-11-02 15:55', 'UTC')])->save();

    acceptAs($this, $this->agent, $this->queued)->assertSessionHasNoErrors();

    $this->travelTo(CarbonImmutable::parse('2026-11-02 16:06', 'UTC'));
    $another = Conversation::factory()->inDepartment($this->department)->create();
    acceptAs($this, $this->agent, $another)->assertSessionHasErrors('conversation');
});

test('a customer writing again after the agent\'s answer makes the conversation pending again', function () {
    agentConversationWaitingSince($this->agent, $this->department, '2026-11-02 15:30', '2026-11-02 15:20');

    acceptAs($this, $this->agent, $this->queued)->assertSessionHasErrors('conversation');
});

test('a conversation handed to someone else stops counting for the previous agent', function () {
    $waiting = agentConversationWaitingSince($this->agent, $this->department, '2026-11-02 15:00');
    $colleague = User::factory()->agent()->inDepartments($this->department)->create();
    $waiting->forceFill(['assigned_user_id' => $colleague->id])->save();

    acceptAs($this, $this->agent, $this->queued)->assertSessionHasNoErrors();

    expect($this->queued->fresh()->assigned_user_id)->toBe($this->agent->id);
});

test('reading the chat or writing an internal note is not answering: the customer is still waiting', function () {
    $waiting = agentConversationWaitingSince($this->agent, $this->department, '2026-11-02 15:40');
    $message = $waiting->messages()->create([
        'direction' => MessageDirection::Inbound,
        'type' => MessageType::Text,
        'body' => '¿Me ayudan?',
        'status' => MessageStatus::Received,
        'sent_at' => CarbonImmutable::parse('2026-11-02 15:40', 'UTC'),
    ]);

    // The agent opens the chat (the unread counter clears) and leaves a note.
    $this->actingAs($this->agent)->postJson(route('conversations.read', $waiting), ['message_id' => $message->id])->assertOk();
    $this->actingAs($this->agent)->post(route('conversations.notes.store', $waiting), ['body' => 'Revisar mañana'])->assertSessionHasNoErrors();

    expect(ConversationRead::query()->where('conversation_id', $waiting->id)->where('user_id', $this->agent->id)->value('last_read_message_id'))->toBe($message->id)
        ->and($waiting->fresh()->last_outbound_at)->toBeNull();
    acceptAs($this, $this->agent, $this->queued)->assertSessionHasErrors(['conversation' => PendingAttention::BLOCKED_MESSAGE]);
});

test('a transfer frees the previous agent and gives the new one the whole tolerance', function () {
    $waiting = agentConversationWaitingSince($this->agent, $this->department, '2026-11-02 15:00');
    $colleague = User::factory()->agent()->available()->inDepartments($this->department)->create();

    $this->actingAs($this->agent)
        ->post(route('conversations.transfer', $waiting), ['department_id' => $this->department->id, 'user_id' => $colleague->id, 'version' => $waiting->version])
        ->assertSessionHasNoErrors();

    expect(app(PendingAttention::class)->blocks($this->agent->fresh()))->toBeFalse()
        ->and(app(PendingAttention::class)->blocks($colleague->fresh()))->toBeFalse();

    $this->travel(11)->minutes();

    expect(app(PendingAttention::class)->blocks($colleague->fresh()))->toBeTrue();
});

test('the control does not apply when it is off or to roles exempt from it', function (bool $enabled, bool $exempt) {
    app(PlatformSettings::class)->update(['operations' => ['pending_block_enabled' => $enabled]]);
    $agent = $exempt
        ? conversationUser([Permission::ViewConversations, Permission::ReplyConversations, Permission::AcceptConversations, Permission::AcceptWithPendingConversations], departments: [$this->department])
        : $this->agent;
    agentConversationWaitingSince($agent, $this->department, '2026-11-02 15:00');

    acceptAs($this, $agent, $this->queued)->assertSessionHasNoErrors();

    expect($this->queued->fresh()->assigned_user_id)->toBe($agent->id);
})->with([
    'control off' => [false, false],
    'role exempt' => [true, true],
]);

test('automatic distribution skips an agent with overdue customers and offers the next one', function () {
    $this->department->forceFill(['distribution_mode' => DistributionMode::Automatic])->save();
    $colleague = User::factory()->agent()->available()->inDepartments($this->department)->create(['name' => 'Beto']);
    // Ana attends one conversation and Beto two, so Ana would be chosen first.
    agentConversationWaitingSince($this->agent, $this->department, '2026-11-02 15:00');
    agentConversationWaitingSince($colleague, $this->department, '2026-11-02 15:00', '2026-11-02 15:10');
    agentConversationWaitingSince($colleague, $this->department, '2026-11-02 15:00', '2026-11-02 15:10');

    $receiver = DB::transaction(fn () => app(AssignAutomatically::class)->handle(Conversation::query()->lockForUpdate()->findOrFail($this->queued->id)));

    expect($receiver?->id)->toBe($colleague->id);
});

test('when every agent is blocked the conversation waits in the queue and is offered once the agent answers', function () {
    Queue::fake([DeliverOutboundMessage::class]);
    $this->department->forceFill(['distribution_mode' => DistributionMode::Automatic])->save();
    $connection = Connection::factory()->verified()->create();
    $waiting = agentConversationWaitingSince($this->agent, $this->department, '2026-11-02 15:00');
    $waiting->forceFill(['connection_id' => $connection->id])->save();

    $receiver = DB::transaction(fn () => app(AssignAutomatically::class)->handle(Conversation::query()->lockForUpdate()->findOrFail($this->queued->id)));
    expect($receiver)->toBeNull();

    $this->actingAs($this->agent)
        ->postJson(route('conversations.messages.store', $waiting), ['body' => 'Con gusto le ayudo.', 'client_reference' => (string) str()->uuid()])
        ->assertStatus(202);

    expect($this->queued->fresh())->status->toBe(ConversationStatus::Open)
        ->assigned_user_id->toBe($this->agent->id);
});

test('an administrator may still hand a conversation to a blocked agent', function () {
    agentConversationWaitingSince($this->agent, $this->department, '2026-11-02 15:00');
    $admin = User::factory()->administrator()->create();

    $this->actingAs($admin)
        ->from(route('conversations.index'))
        ->post(route('conversations.assign', $this->queued), ['user_id' => $this->agent->id, 'version' => $this->queued->version])
        ->assertSessionHasNoErrors();

    expect($this->queued->fresh()->assigned_user_id)->toBe($this->agent->id);
});

test('a blocked agent keeps using the internal chat', function () {
    agentConversationWaitingSince($this->agent, $this->department, '2026-11-02 15:00');
    $supervisor = User::factory()->supervisor()->inDepartments($this->department)->create();
    $thread = TeamThread::factory()->direct($this->agent, $supervisor)->create();

    $this->actingAs($this->agent)
        ->postJson(route('team-chat.threads.messages.store', $thread), ['body' => '¿Me ayudas con un cliente?'])
        ->assertCreated();
    $this->actingAs($supervisor)
        ->postJson(route('team-chat.threads.messages.store', $thread), ['body' => 'Claro, dime.'])
        ->assertCreated();

    expect(app(PendingAttention::class)->blocks($this->agent->fresh()))->toBeTrue();
    $this->actingAs($this->agent)->get(route('team-chat.index'))->assertOk();
    $this->actingAs($this->agent)->get(route('team-chat.threads.show', $thread))->assertOk();
    $this->actingAs($this->agent)->getJson(route('team-chat.threads.preview', $thread))
        ->assertOk()
        ->assertJsonFragment(['body' => 'Claro, dime.']);
    $this->actingAs($this->agent)->getJson(route('team-chat.threads.messages.index', $thread))->assertOk();
    $this->actingAs($this->agent)->getJson(route('my-alerts.index'))->assertOk();
});

test('the inbox tells the agent why accepting is disabled and when it will change by itself', function () {
    agentConversationWaitingSince($this->agent, $this->department, '2026-11-02 15:00');
    agentConversationWaitingSince($this->agent, $this->department, '2026-11-02 15:56');

    $this->actingAs($this->agent)->get(route('conversations.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('acceptBlock.blocked', true)
            ->where('acceptBlock.overdue', 1)
            ->where('acceptBlock.awaiting', 2)
            ->where('acceptBlock.message', 'Tienes conversaciones pendientes de atención. Revísalas antes de aceptar una nueva.')
            ->where('acceptBlock.next_change_at', null));
});

test('a waiting customer still within the tolerance announces when the block starts', function () {
    agentConversationWaitingSince($this->agent, $this->department, '2026-11-02 15:56');

    $this->actingAs($this->agent)->get(route('conversations.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('acceptBlock.blocked', false)
            ->where('acceptBlock.next_change_at', '2026-11-02T16:06:00+00:00'));
});

test('people who cannot accept conversations get no control state', function () {
    $viewer = conversationUser([Permission::ViewConversations, Permission::SuperviseConversations]);

    $this->actingAs($viewer)->get(route('conversations.index'))
        ->assertInertia(fn (Assert $page) => $page->where('acceptBlock', null));
});

test('the tolerance and the switch are saved from the platform settings', function () {
    $admin = User::factory()->administrator()->create();
    $settings = app(PlatformSettings::class)->all();

    $this->actingAs($admin)->put(route('admin.settings.update'), [
        ...$settings,
        'operations' => [...$settings['operations'], 'pending_block_enabled' => false, 'pending_block_after_minutes' => 25],
    ])->assertSessionHasNoErrors();

    expect(app(PlatformSettings::class)->get('operations'))
        ->pending_block_enabled->toBeFalse()
        ->pending_block_after_minutes->toBe(25);
});

test('the exemption is a permission of the role, granted to administrators', function () {
    expect(Role::findByName('administrator', 'web')->hasPermissionTo('conversations.accept-with-pending'))->toBeTrue()
        ->and(Role::findByName('agent', 'web')->hasPermissionTo('conversations.accept-with-pending'))->toBeFalse()
        ->and(Role::findByName('supervisor', 'web')->hasPermissionTo('conversations.accept-with-pending'))->toBeFalse();
});

test('turning the control off offers the waiting queue to the agents it blocked', function () {
    $this->department->forceFill(['distribution_mode' => DistributionMode::Automatic])->save();
    agentConversationWaitingSince($this->agent, $this->department, '2026-11-02 15:00');
    $admin = User::factory()->administrator()->create();
    $settings = app(PlatformSettings::class)->all();

    $this->actingAs($admin)->put(route('admin.settings.update'), [
        ...$settings,
        'operations' => [...$settings['operations'], 'pending_block_enabled' => false],
    ])->assertSessionHasNoErrors();

    expect($this->queued->fresh()->assigned_user_id)->toBe($this->agent->id);
});

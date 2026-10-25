<?php

use App\Enums\ConversationStatus;
use App\Enums\DataScope;
use App\Enums\MessageDirection;
use App\Enums\MessageStatus;
use App\Enums\Permission;
use App\Models\Connection;
use App\Models\Conversation;
use App\Models\ConversationRead;
use App\Models\Department;
use App\Models\Message;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

/*
| What each row of the inbox list tells and offers (docs/bandejas-y-asignacion.md):
| the receiving connection and its channel, the status, accepting a pending
| conversation from the list through the same route the chat uses, the
| personal unread counters and the "unread" filter combined with the others.
*/

beforeEach(function () {
    $this->sales = Department::factory()->create(['name' => 'Ventas']);
    $this->support = Department::factory()->create(['name' => 'Soporte']);
    $this->agent = User::factory()->agent()->available()->inDepartments($this->sales)->create();
    $this->colleague = User::factory()->agent()->available()->inDepartments($this->sales)->create();
});

/**
 * Rows of the list as the viewer gets them.
 *
 * @param  array<string, mixed>  $query
 * @return list<array<string, mixed>>
 */
function inboxRows(User $viewer, array $query = []): array
{
    return test()->actingAs($viewer)->get(route('conversations.index', $query))->viewData('page')['props']['conversations']['data'];
}

/**
 * @param  list<array<string, mixed>>  $rows
 * @return list<int>
 */
function rowIds(array $rows): array
{
    return collect($rows)->pluck('id')->sort()->values()->all();
}

function inboundTo(Conversation $conversation): Message
{
    return Message::factory()->create(['conversation_id' => $conversation->id, 'direction' => MessageDirection::Inbound]);
}

test('a row tells the receiving connection with its channel and phone, and its status', function () {
    $connection = Connection::factory()->create(['name' => 'Ventas MX', 'display_phone_number' => '+52 55 1234 5678']);
    $attended = Conversation::factory()->inDepartment($this->sales)->assignedTo($this->agent)->create(['connection_id' => $connection->id]);

    $row = collect(inboxRows($this->agent))->firstWhere('id', $attended->id);

    expect($row['connection'])->toMatchArray([
        'id' => $connection->id,
        'name' => 'Ventas MX',
        'phone' => '+52 55 1234 5678',
        'channel' => 'whatsapp',
        'channel_label' => 'WhatsApp',
    ])
        ->and($row['status'])->toBe('open')
        ->and($row['status_label'])->toBe(ConversationStatus::Open->label())
        ->and($row['department']['name'])->toBe('Ventas')
        ->and($row['assignee']['id'])->toBe($this->agent->id);
});

test('the connection filter offers each connection with its phone number', function () {
    $connection = Connection::factory()->create(['name' => 'Soporte', 'display_phone_number' => '+52 33 0000 1111']);

    $this->actingAs(User::factory()->administrator()->create())
        ->get(route('conversations.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('connections', fn ($options) => collect($options)->contains(fn ($option) => $option['id'] === $connection->id
                && $option['display_phone_number'] === '+52 33 0000 1111')));
});

test('a pending row offers accepting only to whoever the policy lets accept it', function () {
    $queued = Conversation::factory()->inDepartment($this->sales)->create();
    $viewerOnly = conversationUser([Permission::ViewConversations, Permission::ReplyConversations], DataScope::Departments, [$this->sales]);

    expect(collect(inboxRows($this->agent, ['view' => 'queue']))->firstWhere('id', $queued->id)['can']['accept'])->toBeTrue()
        ->and(collect(inboxRows($viewerOnly, ['view' => 'all']))->firstWhere('id', $queued->id)['can']['accept'])->toBeFalse();
});

test('accepting from the general inbox uses the chat\'s route and leaves the row in its place, now in attention', function () {
    $older = Conversation::factory()->inDepartment($this->sales)->assignedTo($this->agent)->create(['last_message_at' => now()->subHours(2)]);
    $queued = Conversation::factory()->inDepartment($this->sales)->create(['last_message_at' => now()->subHour()]);
    $newer = Conversation::factory()->inDepartment($this->sales)->assignedTo($this->agent)->create(['last_message_at' => now()]);

    expect(collect(inboxRows($this->agent))->pluck('id')->all())->toBe([$newer->id, $queued->id, $older->id]);

    $this->actingAs($this->agent)
        ->from(route('conversations.index'))
        ->post(route('conversations.accept', $queued))
        ->assertRedirect(route('conversations.show', $queued))
        ->assertSessionHasNoErrors();

    $rows = collect(inboxRows($this->agent));

    expect($rows->pluck('id')->all())->toBe([$newer->id, $queued->id, $older->id])
        ->and($rows->firstWhere('id', $queued->id)['status'])->toBe('open')
        ->and($rows->firstWhere('id', $queued->id)['can']['accept'])->toBeFalse();
});

test('accepting from the pending ones opens the general inbox with the conversation, keeping the other filters', function () {
    $queued = Conversation::factory()->inDepartment($this->sales)->create();

    inboxRows($this->agent, ['status' => 'pending', 'department' => (string) $this->sales->id]);

    $this->actingAs($this->agent)
        ->post(route('conversations.accept', $queued))
        ->assertRedirect(route('conversations.index', ['department' => (string) $this->sales->id, 'c' => $queued->id]))
        ->assertSessionHasNoErrors();

    $rows = inboxRows($this->agent, ['department' => (string) $this->sales->id]);

    expect(collect($rows)->firstWhere('id', $queued->id)['status'])->toBe('open')
        ->and(rowIds(inboxRows($this->agent, ['status' => 'pending'])))->not->toContain($queued->id);
});

test('a queue remembered in the session is normalized to the pending ones of the general inbox', function () {
    $queued = Conversation::factory()->inDepartment($this->sales)->create();

    $this->actingAs($this->agent)
        ->withSession(['conversations.list_query' => ['view' => 'queue']])
        ->get(route('conversations.show', $queued))
        ->assertRedirect(route('conversations.index', ['status' => 'pending', 'c' => $queued->id]));

    $this->actingAs($this->agent)
        ->withSession(['conversations.list_query' => ['view' => 'queue']])
        ->post(route('conversations.accept', $queued))
        ->assertRedirect(route('conversations.index', ['c' => $queued->id]));
});

test('the second agent accepting from the list gets the conflict, never the conversation', function () {
    $queued = Conversation::factory()->inDepartment($this->sales)->create();
    $this->actingAs($this->colleague)->post(route('conversations.accept', $queued))->assertSessionHasNoErrors();

    $this->actingAs($this->agent)
        ->from(route('conversations.index', ['view' => 'queue']))
        ->post(route('conversations.accept', $queued))
        ->assertRedirect(route('conversations.index'))
        ->assertSessionHasErrors(['conversation' => 'Otra persona ya aceptó esta conversación.']);

    $this->actingAs($this->agent)
        ->postJson(route('conversations.accept', $queued))
        ->assertStatus(409);

    expect($queued->refresh()->assigned_user_id)->toBe($this->colleague->id);
});

test('accepting outside one\'s departments or without the permission is refused', function () {
    $foreign = Conversation::factory()->inDepartment($this->support)->create();
    $own = Conversation::factory()->inDepartment($this->sales)->create();
    $viewerOnly = conversationUser([Permission::ViewConversations, Permission::ReplyConversations], DataScope::Departments, [$this->sales]);

    $this->actingAs($this->agent)->post(route('conversations.accept', $foreign))->assertNotFound();
    $this->actingAs($viewerOnly)->post(route('conversations.accept', $own))->assertForbidden();

    expect($foreign->refresh()->status)->toBe(ConversationStatus::Pending)
        ->and($own->refresh()->assigned_user_id)->toBeNull();
});

test('listing never moves the read markers; reading clears the counter of that person only', function () {
    $conversation = Conversation::factory()->inDepartment($this->sales)->assignedTo($this->agent)->create();
    inboundTo($conversation);
    $latest = inboundTo($conversation);

    expect(collect(inboxRows($this->agent))->firstWhere('id', $conversation->id)['unread_count'])->toBe(2)
        ->and(collect(inboxRows($this->agent))->firstWhere('id', $conversation->id)['unread_count'])->toBe(2)
        ->and(ConversationRead::query()->count())->toBe(0);

    $this->actingAs($this->agent)->postJson(route('conversations.read', $conversation), ['message_id' => $latest->id])->assertOk();

    expect(collect(inboxRows($this->agent))->firstWhere('id', $conversation->id)['unread_count'])->toBe(0);
});

test('without a read marker only unanswered customer messages count, and closed conversations never', function () {
    $admin = User::factory()->administrator()->create();
    $answered = Conversation::factory()->inDepartment($this->sales)->assignedTo($this->agent)->create();
    inboundTo($answered);
    Message::factory()->create([
        'conversation_id' => $answered->id,
        'direction' => MessageDirection::Outbound,
        'status' => MessageStatus::Sent,
        'sender_user_id' => $this->agent->id,
    ]);
    inboundTo($answered);
    $automatic = Conversation::factory()->inDepartment($this->sales)->assignedTo($this->agent)->create();
    inboundTo($automatic);
    Message::factory()->create([
        'conversation_id' => $automatic->id,
        'direction' => MessageDirection::Outbound,
        'status' => MessageStatus::Sent,
        'sender_user_id' => null,
        'metadata' => ['automatic' => true],
    ]);
    $closed = Conversation::factory()->inDepartment($this->sales)->assignedTo($this->agent)->closed()->create();
    inboundTo($closed);

    $rows = collect(inboxRows($admin, ['view' => 'all', 'status' => 'all']));

    expect($rows->firstWhere('id', $answered->id)['unread_count'])->toBe(1)
        ->and($rows->firstWhere('id', $automatic->id)['unread_count'])->toBe(1)
        ->and($rows->firstWhere('id', $closed->id)['unread_count'])->toBe(0);
});

test('the unread filter combines with the view, status and department filters and counts its rows', function () {
    $admin = User::factory()->administrator()->create();
    $unreadSales = Conversation::factory()->inDepartment($this->sales)->assignedTo($this->agent)->create();
    inboundTo($unreadSales);
    $readSales = Conversation::factory()->inDepartment($this->sales)->assignedTo($this->agent)->create();
    $readMessage = inboundTo($readSales);
    ConversationRead::query()->forceCreate(['user_id' => $admin->id, 'conversation_id' => $readSales->id, 'last_read_message_id' => $readMessage->id, 'read_at' => now()]);
    $unreadSupport = Conversation::factory()->inDepartment($this->support)->create();
    inboundTo($unreadSupport);
    Conversation::factory()->inDepartment($this->sales)->assignedTo($this->agent)->create();

    $this->actingAs($admin)
        ->get(route('conversations.index', ['view' => 'all', 'unread' => 1]))
        ->assertInertia(fn (Assert $page) => $page
            ->where('filters.unread', true)
            ->where('unreadCount', 2)
            ->where('conversations.data', fn ($rows) => rowIds(collect($rows)->all()) === collect([$unreadSales->id, $unreadSupport->id])->sort()->values()->all())
            // The administrator attends nothing: of the unread ones, only the pending one is theirs to take.
            ->where('counts.pending', 1)
            ->where('counts.attending', 0));

    expect(rowIds(inboxRows($admin, ['view' => 'all', 'unread' => 1, 'department' => (string) $this->sales->id])))->toBe([$unreadSales->id])
        ->and(rowIds(inboxRows($admin, ['view' => 'all', 'unread' => 1, 'status' => 'pending'])))->toBe([$unreadSupport->id])
        // Markers are personal: what the administrator read is still unread for the agent.
        ->and(rowIds(inboxRows($this->agent, ['unread' => 1])))->toBe(collect([$unreadSales->id, $readSales->id])->sort()->values()->all())
        ->and(rowIds(inboxRows($this->colleague, ['unread' => 1])))->toBe([]);
});

test('the unread counter follows the status tab, as the list it would show', function () {
    $admin = User::factory()->administrator()->create();
    inboundTo(Conversation::factory()->inDepartment($this->sales)->assignedTo($this->agent)->create());
    inboundTo(Conversation::factory()->inDepartment($this->support)->create());
    inboundTo(Conversation::factory()->inDepartment($this->sales)->assignedTo($this->agent)->create(['status' => ConversationStatus::Closed]));

    $unreadCountFor = fn (string $status): int => $this->actingAs($admin)
        ->get(route('conversations.index', ['view' => 'all', 'status' => $status]))
        ->viewData('page')['props']['unreadCount'];

    expect($unreadCountFor('active'))->toBe(2)
        ->and($unreadCountFor('pending'))->toBe(1)
        // The retired "on hold" tab falls back to the active conversations.
        ->and($unreadCountFor(ConversationStatus::OnHold->value))->toBe(2)
        ->and($unreadCountFor(ConversationStatus::Closed->value))->toBe(0);
});

test('the hidden pending tab of my conversations falls back to the active ones', function () {
    $mine = Conversation::factory()->inDepartment($this->sales)->assignedTo($this->agent)->create();

    $this->actingAs($this->agent)
        ->get(route('conversations.index', ['view' => 'mine', 'status' => 'pending']))
        ->assertInertia(fn (Assert $page) => $page
            ->where('filters.view', 'mine')
            ->where('filters.status', 'active')
            ->where('conversations.data.0.id', $mine->id));
});

test('a duplicated webhook of the same message does not count it twice', function () {
    $admin = User::factory()->administrator()->create();
    $connection = Connection::factory()->verified()->create();

    $first = receiveWhatsapp($connection, id: 'wamid.duplicated');
    receiveWhatsapp($connection, id: 'wamid.duplicated');

    expect(collect(inboxRows($admin))->firstWhere('id', $first->conversation->id)['unread_count'])->toBe(1);
});

test('the preview of a failure of unknown outcome tells its error code', function () {
    $conversation = Conversation::factory()->inDepartment($this->sales)->assignedTo($this->agent)->create();
    Message::factory()->create([
        'conversation_id' => $conversation->id,
        'direction' => MessageDirection::Outbound,
        'status' => MessageStatus::Failed,
        'error_code' => 'delivery_unknown',
        'sender_user_id' => $this->agent->id,
    ]);

    expect(collect(inboxRows($this->agent))->firstWhere('id', $conversation->id)['last_message'])
        ->toMatchArray(['status' => 'failed', 'error_code' => 'delivery_unknown']);
});

test('operational badges are offered only to viewers who supervise, assign or have global reach', function () {
    $supervisor = User::factory()->supervisor()->inDepartments($this->sales)->create();
    $admin = User::factory()->administrator()->create();
    $canSupervise = fn (User $viewer): bool => $this->actingAs($viewer)
        ->get(route('conversations.index'))
        ->viewData('page')['props']['viewer']['can_supervise'];

    expect($canSupervise($this->agent))->toBeFalse()
        ->and($canSupervise($supervisor))->toBeTrue()
        ->and($canSupervise($admin))->toBeTrue();
});

test('the preview versions the delivery state of the latest message', function () {
    $conversation = Conversation::factory()->inDepartment($this->sales)->assignedTo($this->agent)->create();
    $message = Message::factory()->for($conversation)->create(['direction' => MessageDirection::Outbound, 'status' => MessageStatus::Sent]);

    $row = collect(inboxRows($this->agent))->firstWhere('id', $conversation->id);

    expect($row['last_message']['id'])->toBe($message->id)
        ->and($row['last_message']['status'])->toBe('sent')
        ->and($row['last_message']['updated_at'])->toBe($message->fresh()->updated_at->toIso8601String());
});

test('the rows carry the operational data and the viewer decides the badges', function () {
    $conversation = Conversation::factory()->inDepartment($this->sales)->assignedTo($this->agent)->create();
    $supervisor = User::factory()->supervisor()->inDepartments($this->sales)->create();

    $payloadFor = function (User $viewer, array $query = []) use ($conversation): array {
        $props = $this->actingAs($viewer)->get(route('conversations.index', $query))->viewData('page')['props'];

        return [$props['viewer']['can_supervise'], collect($props['conversations']['data'])->firstWhere('id', $conversation->id)];
    };

    [$agentSupervises, $agentRow] = $payloadFor($this->agent);
    [$supervisorSupervises, $supervisorRow] = $payloadFor($supervisor, ['view' => 'all']);

    foreach ([$agentRow, $supervisorRow] as $row) {
        expect($row['department'])->toMatchArray(['id' => $this->sales->id, 'name' => 'Ventas'])
            ->and($row['assignee'])->toMatchArray(['id' => $this->agent->id])
            ->and($row['connection'])->toHaveKey('id', $conversation->connection_id);
    }

    expect($agentSupervises)->toBeFalse()
        ->and($agentRow['can']['supervise'])->toBeFalse()
        ->and($supervisorSupervises)->toBeTrue()
        ->and($supervisorRow['can']['supervise'])->toBeTrue();
});

<?php

use App\Actions\Conversations\TransferConversation;
use App\Broadcasting\DepartmentSupervisionChannel;
use App\Broadcasting\RealtimeAudience;
use App\Broadcasting\RealtimeSignals;
use App\Enums\DataScope;
use App\Enums\Permission;
use App\Events\Broadcasts\RealtimeSignal;
use App\Events\Messaging\MessageRecorded;
use App\Models\Connection;
use App\Models\Conversation;
use App\Models\Department;
use App\Models\Message;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\Event;

/*
| Real-time for supervisors (Fase 5.1): the open inbox of whoever supervises
| receives the signals of every conversation of their read reach, the real
| WhatsApp webhook path included, through channels authorized against fresh
| data. A departments supervisor also hears the conversations that passed
| through their departments (inbox.supervision.{id}); nobody else does.
*/

beforeEach(function () {
    // Any broadcaster other than "null" enables the real-time listeners;
    // RealtimeSignal itself is faked, so nothing is published.
    config(['broadcasting.default' => 'log']);

    $this->sales = Department::factory()->create();
    $this->support = Department::factory()->create();
    $this->salesAgent = User::factory()->agent()->inDepartments($this->sales)->create();
    $this->supportAgent = User::factory()->agent()->inDepartments($this->support)->create();
    $this->admin = User::factory()->administrator()->create();
    $this->supervisor = User::factory()->supervisor()->inDepartments($this->sales)->create();
});

/**
 * Channels that received the event during the test, sorted.
 *
 * @return list<string>
 */
function supervisionSignalChannels(string $event): array
{
    return array_values(Event::dispatched(RealtimeSignal::class)
        ->map(fn (array $arguments): RealtimeSignal => $arguments[0])
        ->where('event', $event)
        ->flatMap(fn (RealtimeSignal $signal): array => $signal->channels)
        ->unique()->sort()->values()->all());
}

test('the administrator listens to the global inbox and a departments supervisor to their departments and their passages', function () {
    $audience = app(RealtimeAudience::class);
    $teamRole = Role::factory()->scope(DataScope::Departments)->withPermissions([
        Permission::ViewConversations,
        Permission::ReplyConversations,
    ])->create();
    $teamAgent = User::factory()->withRole($teamRole)->inDepartments($this->sales)->create();

    expect($audience->inboxChannels($audience->freshUser($this->admin)))->toBe(['inbox.global'])
        ->and($audience->inboxChannels($audience->freshUser($this->supervisor)))->toBe([
            "inbox.departments.{$this->sales->id}",
            "inbox.supervision.{$this->sales->id}",
        ])
        // Without supervision, a team member hears only their departments.
        ->and($audience->inboxChannels($audience->freshUser($teamAgent)))->toBe(["inbox.departments.{$this->sales->id}"])
        ->and($audience->inboxChannels($audience->freshUser($this->salesAgent)))->not->toContain("inbox.supervision.{$this->sales->id}");
});

test('only members who supervise the department may join its supervision channel', function () {
    $channel = app(DepartmentSupervisionChannel::class);
    $teamRole = Role::factory()->scope(DataScope::Departments)->withPermissions([
        Permission::ViewConversations,
        Permission::ReplyConversations,
    ])->create();
    $supportSupervisor = User::factory()->supervisor()->inDepartments($this->support)->create();

    expect($channel->join($this->supervisor, (string) $this->sales->id))->toBeTrue()
        ->and($channel->join($supportSupervisor, (string) $this->sales->id))->toBeFalse()
        ->and($channel->join(User::factory()->withRole($teamRole)->inDepartments($this->sales)->create(), (string) $this->sales->id))->toBeFalse()
        ->and($channel->join($this->salesAgent, (string) $this->sales->id))->toBeFalse()
        // Global users already hear every conversation on inbox.global.
        ->and($channel->join($this->admin, (string) $this->sales->id))->toBeFalse()
        ->and($channel->join($this->supervisor, '01'))->toBeFalse();
});

test('a real WhatsApp message to a new conversation reaches the administrator\'s open inbox and lists by default', function () {
    $connection = Connection::factory()->create(['default_department_id' => $this->sales->id]);
    Event::fake([RealtimeSignal::class]);

    $receipt = receiveWhatsapp($connection, '5215512345678', 'Hola, necesito ayuda');

    expect(supervisionSignalChannels('inbox.changed'))->toContain('inbox.global', "inbox.departments.{$this->sales->id}");

    $listed = collect($this->actingAs($this->admin)->getJson(route('conversations.page'))->assertOk()->json('data'))->pluck('id');
    expect($listed)->toContain($receipt->conversation->id);
});

test('a customer message in a colleague\'s conversation reaches the supervisors\' inboxes', function () {
    $conversation = Conversation::factory()->inDepartment($this->sales)->assignedTo($this->salesAgent)->create();
    $message = Message::factory()->for($conversation)->create();
    Event::fake([RealtimeSignal::class]);

    MessageRecorded::dispatch($message->id, $conversation->id, 'inbound');

    expect(supervisionSignalChannels('inbox.changed'))->toEqualCanonicalizing([
        'inbox.global',
        "inbox.departments.{$this->sales->id}",
        "users.{$this->salesAgent->id}",
    ]);
});

test('after a transfer out of the department its supervisors keep hearing the conversation, its other members do not', function () {
    $conversation = Conversation::factory()->inDepartment($this->sales)->assignedTo($this->salesAgent)->create();
    app(TransferConversation::class)->handle($conversation, $this->support, $this->supportAgent, $this->salesAgent, 'Corresponde a soporte', 0);
    $conversation->refresh();
    $message = Message::factory()->for($conversation)->create();
    Event::fake([RealtimeSignal::class]);

    MessageRecorded::dispatch($message->id, $conversation->id, 'inbound');

    expect(supervisionSignalChannels('inbox.changed'))->toEqualCanonicalizing([
        'inbox.global',
        "inbox.departments.{$this->support->id}",
        "inbox.supervision.{$this->sales->id}",
        "users.{$this->supportAgent->id}",
    ])
        // The delivery state of the row follows the same audience.
        ->and(app(RealtimeSignals::class)->inboxChannels($conversation, 'message_status', null))
        ->toContain("inbox.supervision.{$this->sales->id}")
        ->not->toContain("inbox.departments.{$this->sales->id}");

    // The supervisor still lists it: the signal matches their read reach.
    $listed = collect($this->actingAs($this->supervisor)->getJson(route('conversations.page'))->assertOk()->json('data'))->pluck('id');
    expect($listed)->toContain($conversation->id);
});

test('a conversation that never left its department adds no supervision channel', function () {
    $conversation = Conversation::factory()->inDepartment($this->sales)->assignedTo($this->salesAgent)->create();

    expect(app(RealtimeSignals::class)->inboxChannels($conversation, 'message', null))->toBe([
        'inbox.global',
        "inbox.departments.{$this->sales->id}",
    ]);
});

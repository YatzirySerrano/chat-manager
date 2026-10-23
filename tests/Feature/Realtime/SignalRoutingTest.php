<?php

use App\Actions\Conversations\AcceptConversation;
use App\Actions\Conversations\ChangeAgentAvailability;
use App\Actions\Conversations\TransferConversation;
use App\Enums\AgentAvailability;
use App\Events\Broadcasts\RealtimeSignal;
use App\Events\Conversations\ConversationChanged;
use App\Events\Conversations\ConversationNoteSaved;
use App\Events\Messaging\MessageRecorded;
use App\Events\Messaging\MessageStatusChanged;
use App\Models\Connection;
use App\Models\Conversation;
use App\Models\Department;
use App\Models\Message;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Event;

beforeEach(function () {
    // Any broadcaster other than "null" enables the real-time listeners;
    // RealtimeSignal itself is faked in every test, so nothing is published.
    config(['broadcasting.default' => 'log']);
});

/**
 * Signals published during the test, as event name => list of channels.
 *
 * @return Collection<int, RealtimeSignal>
 */
function publishedSignals(): Collection
{
    return Event::dispatched(RealtimeSignal::class)->map(fn (array $arguments): RealtimeSignal => $arguments[0])->values();
}

/**
 * @return list<string>
 */
function channelsOf(string $event): array
{
    $channels = publishedSignals()->where('event', $event)->flatMap(fn (RealtimeSignal $signal): array => $signal->channels)->unique()->sort()->values()->all();

    return array_values($channels);
}

test('accepting a queued conversation refreshes the queue, the team inboxes and the agent', function () {
    $department = Department::factory()->create();
    $agent = User::factory()->agent()->available()->inDepartments($department)->create();
    $conversation = Conversation::factory()->inDepartment($department)->create();
    Event::fake([RealtimeSignal::class]);

    app(AcceptConversation::class)->handle($conversation, $agent);

    expect(channelsOf('inbox.changed'))->toEqualCanonicalizing([
        'inbox.global',
        "inbox.departments.{$department->id}",
        "inbox.queues.{$department->id}",
        "users.{$agent->id}",
    ])->and(channelsOf('conversation.changed'))->toBe(["conversations.{$conversation->id}"]);

    $signal = publishedSignals()->firstWhere('event', 'conversation.changed');
    expect($signal->payload)->toBe(['reason' => 'accepted', 'version' => $conversation->fresh()->version]);
});

test('a transfer reaches both departments and the previous agent, never the old queue of an attended conversation', function () {
    $sales = Department::factory()->create();
    $support = Department::factory()->create();
    $agent = User::factory()->agent()->inDepartments($sales)->create();
    $supervisor = User::factory()->supervisor()->inDepartments([$sales, $support])->create();
    $conversation = Conversation::factory()->inDepartment($sales)->assignedTo($agent)->create();
    Event::fake([RealtimeSignal::class]);

    app(TransferConversation::class)->handle($conversation, $support, null, $supervisor, 'Corresponde a soporte', $conversation->version);

    expect(channelsOf('inbox.changed'))
        ->toContain('inbox.global', "inbox.departments.{$sales->id}", "inbox.departments.{$support->id}", "users.{$agent->id}")
        ->not->toContain("inbox.queues.{$sales->id}");
});

test('messages of an attended conversation never reach department queues', function () {
    $department = Department::factory()->create();
    $agent = User::factory()->agent()->inDepartments($department)->create();
    $conversation = Conversation::factory()->inDepartment($department)->assignedTo($agent)->create();
    $message = Message::factory()->for($conversation)->create();
    Event::fake([RealtimeSignal::class]);

    MessageRecorded::dispatch($message->id, $conversation->id, 'inbound');

    expect(channelsOf('message.recorded'))->toBe(["conversations.{$conversation->id}"])
        ->and(channelsOf('inbox.changed'))->toEqualCanonicalizing(['inbox.global', "inbox.departments.{$department->id}", "users.{$agent->id}"]);
});

test('a new inbound conversation is announced to its department queue without customer data', function () {
    $department = Department::factory()->create();
    $connection = Connection::factory()->create(['default_department_id' => $department->id]);
    Event::fake([RealtimeSignal::class]);

    $receipt = receiveWhatsapp($connection, '5215512345678', 'Mi tarjeta 4111 1111 1111 1111');

    expect(channelsOf('inbox.changed'))->toContain("inbox.queues.{$department->id}", "inbox.departments.{$department->id}", 'inbox.global');

    publishedSignals()->each(function (RealtimeSignal $signal) use ($receipt): void {
        $encoded = json_encode($signal->payload);

        expect(array_keys($signal->payload))->each->toBeIn(['conversation_id', 'reason', 'version', 'message_id', 'direction'])
            ->and($encoded)->not->toContain('5215512345678')
            ->not->toContain('4111')
            ->not->toContain((string) $receipt->registration->contact->name);
    });
});

test('message statuses go to the conversation, and failures also to the responsible agent', function () {
    $agent = User::factory()->agent()->create();
    $conversation = Conversation::factory()->assignedTo($agent)->create();
    $message = Message::factory()->for($conversation)->create();
    Event::fake([RealtimeSignal::class]);

    MessageStatusChanged::dispatch($message->id, $conversation->id, 'delivered', 'sent');

    expect(channelsOf('message.status'))->toBe(["conversations.{$conversation->id}"])
        ->and(channelsOf('inbox.changed'))->toBe([]);

    MessageStatusChanged::dispatch($message->id, $conversation->id, 'failed', 'sending');

    expect(channelsOf('inbox.changed'))->toBe(["users.{$agent->id}"]);
});

test('notes only refresh the conversation itself', function () {
    $department = Department::factory()->create();
    $conversation = Conversation::factory()->inDepartment($department)->create();
    Event::fake([RealtimeSignal::class]);

    ConversationNoteSaved::dispatch($conversation->id, 41, null);
    ConversationChanged::dispatch($conversation->id, 'note_added', null, null);

    expect(channelsOf('note.saved'))->toBe(["conversations.{$conversation->id}"])
        ->and(channelsOf('conversation.changed'))->toBe(["conversations.{$conversation->id}"])
        ->and(channelsOf('inbox.changed'))->toBe([]);
});

test('read markers only reach the reader', function () {
    $agent = User::factory()->agent()->create();
    $conversation = Conversation::factory()->assignedTo($agent)->create();
    Event::fake([RealtimeSignal::class]);

    ConversationChanged::dispatch($conversation->id, 'read', $agent->id, null);

    expect(publishedSignals())->toHaveCount(1)
        ->and(channelsOf('inbox.changed'))->toBe(["users.{$agent->id}"]);
});

test('availability changes reach the agent and the team inboxes, not colleagues in personal queues', function () {
    $department = Department::factory()->create();
    $agent = User::factory()->agent()->inDepartments($department)->create();
    Event::fake([RealtimeSignal::class]);

    app(ChangeAgentAvailability::class)->handle($agent, AgentAvailability::Available);

    expect(channelsOf('availability.changed'))->toEqualCanonicalizing([
        "users.{$agent->id}",
        'inbox.global',
        "inbox.departments.{$department->id}",
    ]);
});

test('nothing is queued or published when broadcasting is disabled', function () {
    config(['broadcasting.default' => 'null']);
    $department = Department::factory()->create();
    $agent = User::factory()->agent()->available()->inDepartments($department)->create();
    $conversation = Conversation::factory()->inDepartment($department)->create();
    Event::fake([RealtimeSignal::class]);

    app(AcceptConversation::class)->handle($conversation, $agent);

    Event::assertNotDispatched(RealtimeSignal::class);
});

test('a customer message signals each inbox once and the open chat once', function () {
    $department = Department::factory()->create();
    $connection = Connection::factory()->create(['default_department_id' => $department->id]);
    receiveWhatsapp($connection, '5215512345678', 'Hola');
    Event::fake([RealtimeSignal::class]);

    $receipt = receiveWhatsapp($connection, '5215512345678', '¿Siguen ahí?');

    $inboxChannels = publishedSignals()->where('event', 'inbox.changed')->flatMap(fn (RealtimeSignal $signal): array => $signal->channels);

    expect($inboxChannels->all())->toBe($inboxChannels->unique()->values()->all())
        ->toContain('inbox.global', "inbox.departments.{$department->id}")
        ->and(channelsOf('message.recorded'))->toBe(["conversations.{$receipt->conversation->id}"])
        ->and(publishedSignals()->where('event', 'message.recorded'))->toHaveCount(1)
        ->and(channelsOf('conversation.changed'))->toBe([]);
});

<?php

use App\Events\Messaging\MessageRecorded;
use App\Events\TeamChat\Broadcasts\TeamChatUserSignal;
use App\Events\TeamChat\Broadcasts\TeamThreadSignal;
use App\Listeners\Automation\SendAutomaticMessages;
use App\Listeners\Notifications\NotifyConversationChanges;
use App\Listeners\Notifications\NotifyInboundMessage;
use App\Listeners\Realtime\BroadcastConversationChange;
use App\Listeners\Realtime\BroadcastMessageRecorded;
use App\Models\Connection;
use App\Models\Department;
use App\Models\Message;
use App\Models\User;
use Illuminate\Broadcasting\BroadcastEvent;
use Illuminate\Events\CallQueuedListener;
use Illuminate\Support\Facades\Queue;

/*
| Which queue each piece of work lands on, and that a message never queues
| jobs that would do nothing: the real-time signal goes to its dedicated
| queue, never behind notifications or exports.
*/

beforeEach(function () {
    // Any broadcaster other than "null" enables the real-time listeners.
    config(['broadcasting.default' => 'log']);
});

/**
 * Queued listeners of the given class, as their queue names.
 *
 * @return list<string|null>
 */
function queuedListenerQueues(string $listener): array
{
    $pushed = array_filter(
        Queue::pushedJobs()[CallQueuedListener::class] ?? [],
        fn (array $entry): bool => $entry['job']->class === $listener,
    );

    return array_values(array_map(fn (array $entry): ?string => $entry['queue'], $pushed));
}

test('a customer message queues one real-time signal and one notification, nothing else', function () {
    $department = Department::factory()->create();
    $connection = Connection::factory()->create(['default_department_id' => $department->id]);
    User::factory()->agent()->available()->inDepartments($department)->create();
    receiveWhatsapp($connection, '5215512345678', 'Hola');
    Queue::fake();

    receiveWhatsapp($connection, '5215512345678', '¿Siguen ahí?');

    expect(queuedListenerQueues(BroadcastMessageRecorded::class))->toBe(['realtime'])
        ->and(queuedListenerQueues(NotifyInboundMessage::class))->toBe(['notifications'])
        ->and(queuedListenerQueues(BroadcastConversationChange::class))->toBe([])
        ->and(queuedListenerQueues(NotifyConversationChanges::class))->toBe([])
        ->and(queuedListenerQueues(SendAutomaticMessages::class))->toBe([]);
});

test('an outbound message never queues the inbound notification', function () {
    $message = Message::factory()->create();
    Queue::fake();

    MessageRecorded::dispatch($message->id, $message->conversation_id, 'outbound');

    expect(queuedListenerQueues(BroadcastMessageRecorded::class))->toBe(['realtime'])
        ->and(queuedListenerQueues(NotifyInboundMessage::class))->toBe([]);
});

test('REALTIME_QUEUE moves every real-time signal, team chat included', function () {
    config(['broadcasting.realtime.queue' => 'fast']);
    $message = Message::factory()->create();
    Queue::fake();

    MessageRecorded::dispatch($message->id, $message->conversation_id, 'outbound');
    TeamThreadSignal::dispatch(7, 'message.created', ['message_id' => 1]);
    TeamChatUserSignal::dispatch([1], 7, 'message');

    expect(queuedListenerQueues(BroadcastMessageRecorded::class))->toBe(['fast']);
    Queue::assertPushedOn('fast', BroadcastEvent::class, fn (BroadcastEvent $job): bool => $job->event instanceof TeamThreadSignal);
    Queue::assertPushedOn('fast', BroadcastEvent::class, fn (BroadcastEvent $job): bool => $job->event instanceof TeamChatUserSignal);
});

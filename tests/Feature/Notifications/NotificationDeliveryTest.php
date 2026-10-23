<?php

use App\Actions\Conversations\AcceptConversation;
use App\Actions\Conversations\AssignConversation;
use App\Actions\Conversations\ProcessInboundMessages;
use App\Actions\Conversations\TransferConversation;
use App\Enums\ConnectionHealth;
use App\Enums\DataTransferStatus;
use App\Enums\DataTransferType;
use App\Enums\Permission;
use App\Events\Broadcasts\RealtimeSignal;
use App\Events\Connections\ConnectionHealthChanged;
use App\Events\Conversations\ConversationChanged;
use App\Events\Users\UserAccessChanged;
use App\Models\Connection;
use App\Models\DataTransfer;
use App\Models\Department;
use App\Models\User;
use App\Notifications\NotificationKind;
use Illuminate\Broadcasting\Broadcasters\NullBroadcaster;
use Illuminate\Broadcasting\BroadcastException;
use Illuminate\Broadcasting\BroadcastManager;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Tests\Feature\Realtime\RealtimeTestSupport;

/*
| Who receives each notification, how bursts and retries are folded or
| ignored, and that payloads only carry identifiers.
*/

/**
 * @return Collection<int, DatabaseNotification>
 */
function notificationsOf(User $user, ?NotificationKind $kind = null)
{
    return DatabaseNotification::query()
        ->where('notifiable_type', $user->getMorphClass())
        ->where('notifiable_id', $user->id)
        ->when($kind !== null, fn ($query) => $query->where('kind', $kind->value))
        ->get();
}

beforeEach(function () {
    $this->department = Department::factory()->create();
    $this->connection = Connection::factory()->create(['default_department_id' => $this->department->id]);
    $this->agent = User::factory()->agent()->available()->inDepartments($this->department)->create();
});

test('a new pending conversation notifies only available users who can accept it', function () {
    $unavailable = User::factory()->agent()->inDepartments($this->department)->create();
    $otherDepartment = User::factory()->agent()->available()->inDepartments(Department::factory()->create())->create();
    $withoutAccept = conversationUser([Permission::ViewConversations, Permission::ReplyConversations], departments: [$this->department]);
    $withoutAccept->forceFill(['availability' => 'available'])->save();

    $receipt = receiveWhatsapp($this->connection, '5215512345678', 'Mi número es 5544332211');

    $notification = notificationsOf($this->agent, NotificationKind::ConversationPending)->sole();

    expect($notification->level)->toBe('urgent')
        ->and($notification->data)->toBe([
            'conversation_id' => $receipt->conversation->id,
            'department_id' => $this->department->id,
            'conversation_ids' => [$receipt->conversation->id],
        ])
        ->and(json_encode($notification->data))->not->toContain('5544332211')
        ->and(notificationsOf($unavailable))->toBeEmpty()
        ->and(notificationsOf($otherDepartment))->toBeEmpty()
        ->and(notificationsOf($withoutAccept))->toBeEmpty();
});

test('a burst of pending conversations folds into one unread notification and webhook retries add nothing', function () {
    $payload = whatsappWebhook($this->connection, [whatsappTextMessage('5215500000001', id: 'wamid.burst-1')]);

    app(ProcessInboundMessages::class)->handle($payload);
    app(ProcessInboundMessages::class)->handle($payload);
    receiveWhatsapp($this->connection, '5215500000002');
    receiveWhatsapp($this->connection, '5215500000003');

    $notification = notificationsOf($this->agent)->sole();

    expect($notification->occurrences)->toBe(3)
        ->and($notification->data['conversation_ids'])->toHaveCount(3);

    // Once read, the next arrival opens a new notification.
    $notification->forceFill(['read_at' => now()])->save();
    receiveWhatsapp($this->connection, '5215500000004');

    expect(notificationsOf($this->agent))->toHaveCount(2);
});

test('re-dispatching the same conversation event does not notify twice', function () {
    $receipt = receiveWhatsapp($this->connection);
    $event = $receipt->conversation->events()->sole();

    ConversationChanged::dispatch($receipt->conversation->id, 'created', null, $event->id);

    expect(notificationsOf($this->agent)->sole()->occurrences)->toBe(1);
});

test('an assignment notifies the new responsible agent but never the person who did it', function () {
    $supervisor = User::factory()->supervisor()->inDepartments($this->department)->create();
    $receipt = receiveWhatsapp($this->connection);

    app(AssignConversation::class)->handle($receipt->conversation, $this->agent, $supervisor);

    expect(notificationsOf($this->agent, NotificationKind::ConversationAssigned)->sole()->data)
        ->toMatchArray(['conversation_id' => $receipt->conversation->id, 'actor_id' => $supervisor->id, 'reason' => 'assigned'])
        ->and(notificationsOf($supervisor, NotificationKind::ConversationAssigned))->toBeEmpty();
});

test('accepting a conversation yourself is not notified', function () {
    $receipt = receiveWhatsapp($this->connection);

    app(AcceptConversation::class)->handle($receipt->conversation, $this->agent);

    expect(notificationsOf($this->agent, NotificationKind::ConversationAssigned))->toBeEmpty();
});

test('a direct transfer notifies the receiving agent', function () {
    $colleague = User::factory()->agent()->available()->inDepartments($this->department)->create();
    $receipt = receiveWhatsapp($this->connection);
    app(AcceptConversation::class)->handle($receipt->conversation, $this->agent);

    app(TransferConversation::class)->handle($receipt->conversation->refresh(), $this->department, $colleague, $this->agent, 'Cliente VIP');

    $notification = notificationsOf($colleague, NotificationKind::ConversationTransferred)->sole();

    expect($notification->data)->toMatchArray(['conversation_id' => $receipt->conversation->id, 'actor_id' => $this->agent->id])
        ->and(json_encode($notification->data))->not->toContain('Cliente VIP');
});

test('inbound messages of my conversation fold into one notification until I read the conversation', function () {
    $receipt = receiveWhatsapp($this->connection, body: 'Hola');
    app(AcceptConversation::class)->handle($receipt->conversation, $this->agent);

    receiveWhatsapp($this->connection, body: 'Información confidencial 1');
    receiveWhatsapp($this->connection, body: 'Información confidencial 2');

    $notification = notificationsOf($this->agent, NotificationKind::ConversationMessage)->sole();

    expect($notification->occurrences)->toBe(2)
        ->and(json_encode($notification->data))->not->toContain('confidencial');

    ConversationChanged::dispatch($receipt->conversation->id, 'read', $this->agent->id);

    expect($notification->refresh()->read_at)->not->toBeNull();

    receiveWhatsapp($this->connection, body: 'Otra cosa');

    expect(notificationsOf($this->agent, NotificationKind::ConversationMessage))->toHaveCount(2);
});

test('messages of a conversation assigned to someone else do not notify me', function () {
    $colleague = User::factory()->agent()->available()->inDepartments($this->department)->create();
    $receipt = receiveWhatsapp($this->connection);
    app(AcceptConversation::class)->handle($receipt->conversation, $colleague);

    receiveWhatsapp($this->connection, body: 'Hola de nuevo');

    expect(notificationsOf($this->agent, NotificationKind::ConversationMessage))->toBeEmpty()
        ->and(notificationsOf($colleague, NotificationKind::ConversationMessage))->toHaveCount(1);
});

test('users who turned a kind off receive nothing of it', function () {
    $this->actingAs($this->agent)->put(route('notifications.preferences.update'), ['preferences' => [
        ['kind' => NotificationKind::ConversationPending->value, 'in_app' => false, 'browser' => false, 'sound' => false],
    ]]);

    receiveWhatsapp($this->connection);

    expect(notificationsOf($this->agent))->toBeEmpty();
});

test('a finished export notifies its owner once', function () {
    $owner = User::factory()->administrator()->create();
    $transfer = DataTransfer::query()->create([
        'type' => DataTransferType::Export, 'report' => 'contacts', 'format' => 'xlsx',
        'status' => DataTransferStatus::Processing, 'user_id' => $owner->id,
    ]);

    $transfer->update(['status' => DataTransferStatus::Completed]);
    $transfer->update(['status' => DataTransferStatus::Expired]);

    expect(notificationsOf($owner, NotificationKind::ExportFinished)->sole()->data)
        ->toBe(['transfer_id' => $transfer->id, 'status' => 'completed']);
});

test('connection problems notify only people who watch connections, once per transition and hour', function () {
    $admin = User::factory()->administrator()->create();

    ConnectionHealthChanged::dispatch($this->connection->id, ConnectionHealth::Error->value, ConnectionHealth::Operational->value, 'timeout');
    ConnectionHealthChanged::dispatch($this->connection->id, ConnectionHealth::Error->value, ConnectionHealth::Operational->value, 'timeout');
    ConnectionHealthChanged::dispatch($this->connection->id, ConnectionHealth::Operational->value, ConnectionHealth::Error->value);

    expect(notificationsOf($admin, NotificationKind::ConnectionProblem)->sole())
        ->occurrences->toBe(1)
        ->data->toBe(['connection_id' => $this->connection->id, 'health' => 'error'])
        ->and(notificationsOf($this->agent, NotificationKind::ConnectionProblem))->toBeEmpty();
});

test('access changes notify the affected active users without details', function () {
    $inactive = User::factory()->agent()->inactive()->create();

    UserAccessChanged::dispatch([$this->agent->id, $inactive->id], 'role');

    expect(notificationsOf($this->agent, NotificationKind::AccessChanged)->sole()->data)->toBe(['reason' => 'role'])
        ->and(notificationsOf($inactive))->toBeEmpty();
});

test('every stored notification sends a content-free signal on the recipient channel', function () {
    config(['broadcasting.default' => 'log']);
    Event::fake([RealtimeSignal::class]);

    receiveWhatsapp($this->connection);

    $notification = notificationsOf($this->agent)->sole();

    $signals = Event::dispatched(RealtimeSignal::class, fn (RealtimeSignal $signal): bool => $signal->event === 'notification');

    expect($signals)->toHaveCount(1);

    $signal = $signals->first()[0];
    expect($signal->broadcastOn()[0]->name)->toBe("private-users.{$this->agent->id}")
        ->and($signal->broadcastOn())->toHaveCount(1)
        ->and($signal->broadcastAs())->toBe('notification')
        ->and($signal->broadcastWith())->toBe(['id' => $notification->id, 'kind' => 'conversation.pending']);
});

test('a stopped real-time server never loses or retries the notification', function () {
    RealtimeTestSupport::useReverb();
    Log::spy();

    receiveWhatsapp($this->connection);

    expect(notificationsOf($this->agent, NotificationKind::ConversationPending))->toHaveCount(1);
    Log::shouldHaveReceived('warning')->withArgs(fn (string $message, array $context): bool => ($context['event'] ?? null) === 'notification');
});

test('a failing real-time server is tried once per job, never once per recipient', function () {
    $broadcaster = new class extends NullBroadcaster
    {
        public int $notificationAttempts = 0;

        public function broadcast(array $channels, $event, array $payload = []): void
        {
            if ($event === 'notification') {
                $this->notificationAttempts++;
            }

            throw new BroadcastException('Reverb no responde.');
        }
    };
    Broadcast::extend('failing', fn () => $broadcaster);
    config(['broadcasting.default' => 'failing', 'broadcasting.connections.failing' => ['driver' => 'failing']]);
    app(BroadcastManager::class)->forgetDrivers();
    $colleagues = User::factory()->count(2)->agent()->available()->inDepartments($this->department)->create();

    receiveWhatsapp($this->connection);

    expect(notificationsOf($this->agent, NotificationKind::ConversationPending))->toHaveCount(1)
        ->and($colleagues->every(fn (User $colleague): bool => notificationsOf($colleague, NotificationKind::ConversationPending)->count() === 1))->toBeTrue()
        ->and($broadcaster->notificationAttempts)->toBe(1);

    app()->forgetScopedInstances();
    UserAccessChanged::dispatch([$this->agent->id], 'role');

    expect($broadcaster->notificationAttempts)->toBe(2);
});

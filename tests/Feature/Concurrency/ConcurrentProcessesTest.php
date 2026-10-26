<?php

/*
| Critical concurrency cases (SPEC §20, §22) reproduced with REAL concurrent
| processes: every racer is a separate PHP process with its own database
| connection (tests/Support/Concurrency/worker.php), released at the same
| instant, like several queue workers or web requests in production.
|
| Data is prepared through an independent, autocommitted connection
| ("committed") so the processes can see it, and read back through it.
| Because that data is committed, the testing database is rebuilt before
| the next test. WhatsApp is never called: deliveries go to a recording
| transport (simulated).
*/

use App\Actions\Conversations\ConversationInboxQuery;
use App\Enums\ConversationEventType;
use App\Enums\ConversationStatus;
use App\Enums\DataScope;
use App\Enums\ExportFormat;
use App\Enums\InboundMessageOutcome;
use App\Enums\MessageStatus;
use App\Enums\Permission;
use App\Jobs\DeliverOutboundMessage;
use App\Models\Connection;
use App\Models\Contact;
use App\Models\ContactIdentity;
use App\Models\Conversation;
use App\Models\ConversationEvent;
use App\Models\Department;
use App\Models\Message;
use App\Models\Role;
use App\Models\User;
use App\Models\WebhookEvent;
use App\Notifications\NotificationKind;
use App\Reports\ReportRegistry;
use App\Services\Conversations\ConversationTimeline;
use App\Services\Exports\ReportExporter;
use App\Support\Queues;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Http\Request;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\Support\Concurrency\ConcurrentProcess;
use Tests\Support\Concurrency\ProbePermissionJob;
use Tests\Support\Concurrency\RecordingTransport;
use Tests\Support\WhatsAppFixtures;

/**
 * Run the callback through the autocommitted connection shared with the processes.
 *
 * @template TReturn
 *
 * @param  Closure(): TReturn  $callback
 * @return TReturn
 */
function committed(Closure $callback): mixed
{
    if (! array_key_exists('committed', (array) config('database.connections'))) {
        config(['database.connections.committed' => config('database.connections.mariadb')]);
    }

    $default = DB::getDefaultConnection();
    DB::setDefaultConnection('committed');

    try {
        return $callback();
    } finally {
        DB::setDefaultConnection($default);
    }
}

/**
 * A file where the recording transport of every process logs its sends.
 */
function sendsLog(): string
{
    $path = storage_path('framework/testing/concurrency-sends-'.Str::uuid().'.log');
    @mkdir(dirname($path), 0775, true);
    $GLOBALS['concurrency_sends_logs'][] = $path;

    return $path;
}

/**
 * @param  list<array<string, mixed>>  $results
 * @return list<array<string, mixed>>
 */
function failures(array $results): array
{
    return array_values(array_filter($results, fn (array $result): bool => ! $result['ok']));
}

/**
 * A customer conversation with a WhatsApp identity, attended in a
 * department, with the customer service window open.
 *
 * @return array{connection: Connection, department: Department, agent: User, conversation: Conversation, phone: string}
 */
function attendedWhatsappConversation(string $phone = '5215512345678'): array
{
    return committed(function () use ($phone): array {
        $connection = Connection::factory()->operational()->create();
        $department = Department::factory()->create(['name' => 'Ventas']);
        $agent = User::factory()->agent()->available()->inDepartments($department)->create();
        $contact = Contact::factory()->create(['phone_e164' => '+'.$phone]);
        ContactIdentity::factory()->create(['contact_id' => $contact->id, 'external_id' => $phone]);
        $conversation = Conversation::factory()->inDepartment($department)->assignedTo($agent)->create([
            'connection_id' => $connection->id,
            'contact_id' => $contact->id,
            'last_inbound_at' => now()->subHour(),
        ]);

        return ['connection' => $connection, 'department' => $department, 'agent' => $agent, 'conversation' => $conversation, 'phone' => $phone];
    });
}

afterEach(function () {
    foreach ($GLOBALS['concurrency_sends_logs'] ?? [] as $log) {
        @unlink($log);
    }

    $GLOBALS['concurrency_sends_logs'] = [];

    DB::purge('committed');
    RefreshDatabaseState::$migrated = false;
});

test('the same customer message delivered to several workers at once is stored once', function () {
    $connection = committed(fn () => Connection::factory()->operational()->create());

    $results = ConcurrentProcess::race(array_map(fn (int $copy) => ConcurrentProcess::start('inbound', [
        'connection_id' => $connection->id,
        'from' => '5215512345678',
        'wamid' => 'wamid.concurrent.same',
        'body' => "Copia {$copy}",
    ]), range(1, 4)));

    expect(failures($results))->toBe([]);

    $outcomes = collect($results)->map(fn (array $result): string => $result['value'][0]['outcome'])->countBy()->sortKeys()->all();

    expect($outcomes)->toBe([InboundMessageOutcome::Created->value => 1, InboundMessageOutcome::Duplicate->value => 3])
        ->and(collect($results)->pluck('value.0.message_id')->unique()->count())->toBe(1)
        ->and(committed(fn () => [Contact::query()->count(), ContactIdentity::query()->count(), Conversation::query()->count(), Message::query()->count()]))->toBe([1, 1, 1, 1])
        ->and(committed(fn () => ConversationEvent::query()->where('type', ConversationEventType::Created)->count()))->toBe(1);
});

test('a webhook redelivered by Meta at once is stored and processed once, and re-running its job changes nothing', function () {
    $connection = committed(fn () => Connection::factory()->operational()->create());
    $payload = whatsappWebhook($connection, [whatsappTextMessage('5215512345678', 'Hola', CarbonImmutable::now(), 'wamid.redelivered')], ['5215512345678' => 'Laura']);
    $payload['entry'][0]['id'] = (string) $connection->business_account_id;
    $body = (string) json_encode($payload);

    $stored = ConcurrentProcess::race(array_map(fn () => ConcurrentProcess::start('store-webhook', ['connection_id' => $connection->id, 'body' => $body]), range(1, 3)));

    expect(failures($stored))->toBe([])
        ->and(collect($stored)->pluck('value')->unique()->count())->toBe(1)
        ->and(committed(fn () => [WebhookEvent::query()->count(), Message::query()->count()]))->toBe([1, 1]);

    // A restarted worker and a duplicated job run the same event again, at once.
    $eventId = $stored[0]['value'];
    $rerun = ConcurrentProcess::race(array_map(fn () => ConcurrentProcess::start('process-webhook-event', ['event_id' => $eventId]), range(1, 3)));

    expect(failures($rerun))->toBe([])
        ->and(committed(fn () => [Contact::query()->count(), Conversation::query()->count(), Message::query()->count()]))->toBe([1, 1, 1])
        ->and(committed(fn () => WebhookEvent::query()->findOrFail($eventId)->status->value))->toBe('processed');
});

test('status webhooks processed out of order by parallel workers only move the message forward', function () {
    ['connection' => $connection, 'conversation' => $conversation] = attendedWhatsappConversation();
    $message = committed(fn () => WhatsAppFixtures::outbound($conversation, ['status' => MessageStatus::Accepted, 'whatsapp_message_id' => 'wamid.out.order', 'accepted_at' => now()]));
    $at = CarbonImmutable::now()->startOfSecond();
    $status = fn (string $name, int $seconds) => ConcurrentProcess::start('statuses', [
        'connection_id' => $connection->id,
        'payload' => WhatsAppFixtures::statusWebhook($connection, [WhatsAppFixtures::status('wamid.out.order', $name, $at->addSeconds($seconds))]),
    ]);

    // Read arrives first, then delivered, then sent, all at the same time.
    $results = ConcurrentProcess::race([$status('read', 30), $status('delivered', 20), $status('sent', 10), $status('delivered', 20)]);

    expect(failures($results))->toBe([]);

    $stored = committed(fn () => Message::query()->findOrFail($message->id));

    expect($stored->status)->toBe(MessageStatus::Read)
        ->and($stored->sent_at?->getTimestamp())->toBe($at->addSeconds(10)->getTimestamp())
        ->and($stored->delivered_at?->getTimestamp())->toBe($at->addSeconds(20)->getTimestamp())
        ->and($stored->read_at?->getTimestamp())->toBe($at->addSeconds(30)->getTimestamp());

    // A late "sent" repeated afterwards never moves it back.
    expect(failures(ConcurrentProcess::race([$status('sent', 10)])))->toBe([])
        ->and(committed(fn () => Message::query()->findOrFail($message->id)->status))->toBe(MessageStatus::Read);
});

test('four agents accepting the same conversation at once: exactly one wins, the others get a clean refusal', function () {
    [$agents, $conversation] = committed(function (): array {
        $department = Department::factory()->create();

        return [
            User::factory()->agent()->available()->inDepartments($department)->count(4)->create(),
            Conversation::factory()->inDepartment($department)->create(),
        ];
    });

    $results = ConcurrentProcess::race($agents->map(fn (User $agent) => ConcurrentProcess::start('accept', ['conversation_id' => $conversation->id, 'user_id' => $agent->id]))->all());

    $winners = array_values(array_filter($results, fn (array $result): bool => $result['ok']));
    $stored = committed(fn () => Conversation::query()->findOrFail($conversation->id));

    expect($winners)->toHaveCount(1)
        ->and(collect(failures($results))->pluck('message')->unique()->all())->toBe(['Otro agente ya está atendiendo esta conversación.'])
        ->and($stored->assigned_user_id)->toBe($winners[0]['value'])
        ->and($stored->status)->toBe(ConversationStatus::Open)
        ->and($stored->version)->toBe(1)
        ->and(committed(fn () => ConversationEvent::query()->where('type', ConversationEventType::Accepted)->count()))->toBe(1);
});

test('a transfer and a customer message at the same time: the message is kept and the destination is consistent', function () {
    ['connection' => $connection, 'conversation' => $conversation, 'phone' => $phone] = attendedWhatsappConversation();
    [$admin, $support] = committed(fn () => [User::factory()->administrator()->create(), Department::factory()->create(['name' => 'Soporte'])]);

    $results = ConcurrentProcess::race([
        ConcurrentProcess::start('transfer', ['conversation_id' => $conversation->id, 'department_id' => $support->id, 'actor_id' => $admin->id]),
        ConcurrentProcess::start('inbound', ['connection_id' => $connection->id, 'from' => $phone, 'wamid' => 'wamid.during.transfer', 'body' => '¿Siguen ahí?']),
    ]);

    expect(failures($results))->toBe([]);

    $stored = committed(fn () => Conversation::query()->findOrFail($conversation->id));
    $message = committed(fn () => Message::query()->where('whatsapp_message_id', 'wamid.during.transfer')->sole());

    expect($results[1]['value'][0]['outcome'])->toBe(InboundMessageOutcome::Appended->value)
        ->and($message->conversation_id)->toBe($conversation->id)
        ->and(committed(fn () => Conversation::query()->count()))->toBe(1)
        ->and($stored->department_id)->toBe($support->id)
        ->and($stored->assigned_user_id)->toBeNull()
        ->and($stored->status)->toBe(ConversationStatus::Pending)
        ->and($stored->last_inbound_at?->getTimestamp())->toBe($message->sent_at?->getTimestamp())
        ->and(committed(fn () => ConversationEvent::query()->where('conversation_id', $conversation->id)->where('type', ConversationEventType::Transferred)->count()))->toBe(1);
});

/**
 * Wait until the racer either finished or is blocked: one of this database
 * user's statements has been running for a second, which on these tiny data
 * sets only happens while it waits for a row lock. (The process list only
 * shows this user's own connections; no PROCESS privilege is needed.)
 */
function waitUntilBlockedOrFinished(ConcurrentProcess $racer, float $timeout = 15): string
{
    $deadline = microtime(true) + $timeout;
    $database = (string) config('database.testing_database');
    $threads = [];

    while (microtime(true) < $deadline) {
        if ($racer->hasFinished()) {
            return 'finished';
        }

        $threads = DB::connection('committed')->select('SHOW FULL PROCESSLIST');
        $waiting = array_filter($threads, fn (object $thread): bool => $thread->db === $database
            && in_array($thread->Command, ['Query', 'Execute'], true)
            && (int) $thread->Time >= 1
            && ! str_starts_with((string) $thread->Info, 'SHOW')
            && ! str_contains((string) $thread->Info, 'GET_LOCK'));

        if ($waiting !== []) {
            return 'blocked';
        }

        usleep(100_000);
    }

    throw new RuntimeException('The racer neither finished nor waited for a lock: '.json_encode(array_values(array_filter($threads, fn (object $thread): bool => $thread->db !== null))));
}

test('an agent losing access while their accept is in flight never keeps the conversation', function (string $scenario) {
    [$admin, $agent, $conversation, $readOnlyRole] = committed(function (): array {
        $department = Department::factory()->create();

        return [
            User::factory()->administrator()->create(),
            User::factory()->agent()->available()->inDepartments($department)->create(),
            Conversation::factory()->inDepartment($department)->create(),
            Role::factory()->scope(DataScope::Departments)->withPermissions([Permission::ViewDashboard, Permission::ViewConversations])->create(),
        ];
    });

    // The accept has been applied but its request has not committed yet...
    $accept = ConcurrentProcess::start('accept-and-hold', ['conversation_id' => $conversation->id, 'user_id' => $agent->id]);
    $accept->waitFor('READY');
    $accept->tell('GO');
    $accept->waitFor('HOLDING');

    // ...when an administrator removes the agent's access.
    $change = ConcurrentProcess::start($scenario, ['user_id' => $agent->id, 'actor_id' => $admin->id, 'role_id' => $readOnlyRole->id]);
    $change->waitFor('READY');
    $change->tell('GO');
    $race = waitUntilBlockedOrFinished($change);
    $accept->tell('COMMIT');

    $accepted = $accept->result();
    $changed = $change->result();

    $stored = committed(fn () => Conversation::query()->findOrFail($conversation->id));
    $user = committed(fn () => User::query()->with('roles')->findOrFail($agent->id));
    $canStillAttend = $user->is_active && ! $user->isDeleted() && $user->holdsPermission(Permission::ReplyConversations);

    expect($accepted['ok'])->toBeTrue()
        // Either the change waited and then released the conversation, or it
        // was refused because its impact changed: never an orphan.
        ->and($stored->assigned_user_id === null || $canStillAttend)->toBeTrue(json_encode(['race' => $race, 'change' => $changed, 'assigned' => $stored->assigned_user_id, 'status' => $stored->status->value]));

    if ($changed['ok']) {
        expect($stored->assigned_user_id)->toBeNull()
            ->and($stored->status)->toBe(ConversationStatus::Pending)
            ->and(committed(fn () => ConversationEvent::query()->where('conversation_id', $conversation->id)->where('type', ConversationEventType::Unassigned)->count()))->toBe(1);
    }
})->with([
    'role changed to one that cannot reply' => ['change-role'],
    'account deleted' => ['delete-user'],
]);

test('a connection deleted while workers deliver its queued replies: nothing is sent twice or after the deletion is seen, nothing is erased', function () {
    ['connection' => $connection, 'conversation' => $conversation] = attendedWhatsappConversation();
    $admin = committed(fn () => User::factory()->administrator()->create());
    $messageIds = committed(fn () => collect(range(1, 6))->map(fn () => WhatsAppFixtures::outbound($conversation)->id)->all());
    $log = sendsLog();

    $results = ConcurrentProcess::race([
        ConcurrentProcess::start('deliver', ['message_ids' => $messageIds, 'sends_log' => $log]),
        ConcurrentProcess::start('delete-connection', ['connection_id' => $connection->id, 'actor_id' => $admin->id]),
        ConcurrentProcess::start('deliver', ['message_ids' => array_reverse($messageIds), 'sends_log' => $log]),
        ConcurrentProcess::start('deliver', ['message_ids' => $messageIds, 'sends_log' => $log]),
    ]);

    expect(failures($results))->toBe([]);

    $messages = committed(fn () => Message::query()->whereKey($messageIds)->get());
    $sent = RecordingTransport::sentIds($log);
    $accepted = $messages->where('status', MessageStatus::Accepted)->pluck('id')->sort()->values()->all();
    $refused = $messages->where('status', MessageStatus::Failed);

    expect($messages)->toHaveCount(6)
        ->and($sent)->toBe(array_values(array_unique($sent)))
        ->and(collect($sent)->sort()->values()->all())->toBe($accepted)
        ->and($messages->map(fn (Message $message): string => $message->status->value)->unique()->diff(['accepted', 'failed'])->all())->toBe([])
        ->and($refused->pluck('error_code')->unique()->diff(['connection_unavailable'])->all())->toBe([])
        ->and(committed(fn () => Connection::query()->findOrFail($connection->id)->isDeleted()))->toBeTrue()
        ->and(committed(fn () => Conversation::query()->whereKey($conversation->id)->exists()))->toBeTrue();

    // Once the deletion committed, a delivery job that runs later sends nothing.
    $late = committed(fn () => WhatsAppFixtures::outbound($conversation)->id);
    expect(failures([ConcurrentProcess::race([ConcurrentProcess::start('deliver', ['message_ids' => [$late], 'sends_log' => $log])])[0]]))->toBe([])
        ->and(RecordingTransport::sentIds($log))->not->toContain($late)
        ->and(committed(fn () => Message::query()->findOrFail($late)->error_code))->toBe('connection_unavailable');
});

test('replies whose 24 hour window expired before the workers ran are blocked once, never sent', function () {
    ['conversation' => $conversation] = attendedWhatsappConversation();
    $messageIds = committed(fn () => collect(range(1, 3))->map(fn () => WhatsAppFixtures::outbound($conversation)->id)->all());
    // The customer's last message is now older than 24 hours.
    committed(fn () => Conversation::query()->whereKey($conversation->id)->update(['last_inbound_at' => now()->subHours(24)->subMinute()]));
    $log = sendsLog();

    $results = ConcurrentProcess::race(array_map(fn () => ConcurrentProcess::start('deliver', ['message_ids' => $messageIds, 'sends_log' => $log]), range(1, 3)));

    expect(failures($results))->toBe([])
        ->and(RecordingTransport::sentIds($log))->toBe([])
        ->and(committed(fn () => Message::query()->whereKey($messageIds)->pluck('error_code')->unique()->all()))->toBe(['customer_window_closed'])
        ->and(committed(fn () => ConversationEvent::query()->where('type', ConversationEventType::OutboundBlocked)->count()))->toBe(3);
});

test('the same delivery job run by several workers at once (restart, duplicate job) sends the message once', function () {
    ['conversation' => $conversation] = attendedWhatsappConversation();
    $message = committed(fn () => WhatsAppFixtures::outbound($conversation));
    $log = sendsLog();

    $results = ConcurrentProcess::race(array_map(fn () => ConcurrentProcess::start('deliver', ['message_ids' => [$message->id], 'sends_log' => $log]), range(1, 4)));

    expect(failures($results))->toBe([])
        ->and(RecordingTransport::sentIds($log))->toBe([$message->id])
        ->and(committed(fn () => Message::query()->findOrFail($message->id)))
        ->status->toBe(MessageStatus::Accepted)
        ->delivery_attempts->toBe(1)
        ->whatsapp_message_id->toBe('wamid.concurrency.'.$message->id);
});

test('an accidental double submission of the same reply stores and sends one message', function () {
    ['conversation' => $conversation, 'agent' => $agent] = attendedWhatsappConversation();
    committed(fn () => Conversation::query()->whereKey($conversation->id)->update(['status' => ConversationStatus::Open]));
    $reference = (string) Str::uuid();
    $log = sendsLog();

    $results = ConcurrentProcess::race(array_map(fn () => ConcurrentProcess::start('queue-outbound', [
        'conversation_id' => $conversation->id,
        'user_id' => $agent->id,
        'body' => 'Con gusto le ayudo',
        'client_reference' => $reference,
        'sends_log' => $log,
    ]), range(1, 4)));

    $message = committed(fn () => Message::query()->where('client_reference', $reference)->sole());

    expect(failures($results))->toBe([])
        ->and(collect($results)->pluck('value')->unique()->all())->toBe([$message->id])
        ->and(committed(fn () => Message::query()->where('direction', 'outbound')->count()))->toBe(1)
        ->and(RecordingTransport::sentIds($log))->toBe([$message->id]);
});

test('a permission revoked by another process is refused on the next web request', function () {
    [$agent, $role, $conversation] = committed(function (): array {
        $department = Department::factory()->create();
        $role = Role::factory()->scope(DataScope::Departments)->withPermissions([Permission::ViewDashboard, Permission::ViewConversations, Permission::ReplyConversations, Permission::AcceptConversations])->create();
        $agent = User::factory()->withRole($role)->inDepartments($department)->available()->create();

        return [$agent, $role, Conversation::factory()->inDepartment($department)->assignedTo($agent)->create(['status' => ConversationStatus::Open])];
    });
    $shared = ['CACHE_STORE' => 'database'];
    $show = fn () => ConcurrentProcess::race([ConcurrentProcess::start('http', ['user_id' => $agent->id, 'uri' => route('conversations.timeline', $conversation->id, false)], $shared)])[0];

    $before = $show();
    $revoked = ConcurrentProcess::race([ConcurrentProcess::start('revoke-permission', ['role_id' => $role->id, 'permission' => Permission::ViewConversations->value], $shared)])[0];
    $after = $show();

    expect($before)->toBe(['ok' => true, 'value' => 200])
        ->and($revoked['ok'])->toBeTrue()
        ->and($after['ok'])->toBeTrue()
        ->and($after['value'])->toBeIn([403, 404]);
});

test('a long-running queue worker honors a permission revoked by another process', function () {
    [$agent, $role] = committed(function (): array {
        $role = Role::factory()->scope(DataScope::Departments)->withPermissions([Permission::ViewConversations, Permission::ReplyConversations])->create();

        return [User::factory()->withRole($role)->create(), $role];
    });
    $shared = ['CACHE_STORE' => 'database', 'QUEUE_CONNECTION' => 'database', 'CONCURRENCY_WATCHDOG' => '90'];
    $log = sendsLog();
    config(['queue.connections.database.connection' => 'committed']);
    $probe = fn () => committed(fn () => app('queue')->connection('database')->pushOn('concurrency-probe', new ProbePermissionJob($agent->id, Permission::ReplyConversations->value, $log)));
    $probes = function (int $expected) use ($log): array {
        $deadline = microtime(true) + 30;

        while (microtime(true) < $deadline) {
            $lines = is_file($log) ? array_values(array_filter(explode("\n", (string) file_get_contents($log)))) : [];

            if (count($lines) >= $expected) {
                return $lines;
            }

            usleep(100_000);
        }

        throw new RuntimeException("The worker did not run {$expected} probe jobs in time.");
    };

    $worker = ConcurrentProcess::start('queue-work', ['queue' => 'concurrency-probe', 'jobs' => 2], $shared);
    $worker->waitFor('READY');
    $worker->tell('GO');

    $probe();
    expect($probes(1))->toBe(['holds']);

    $revoked = ConcurrentProcess::race([ConcurrentProcess::start('revoke-permission', ['role_id' => $role->id, 'permission' => Permission::ReplyConversations->value], $shared)])[0];
    $probe();

    expect($revoked['ok'])->toBeTrue()
        ->and($probes(2))->toBe(['holds', 'lacks'])
        ->and($worker->result()['ok'])->toBeTrue();
});

test('no global lock: while one conversation is locked, work on other conversations proceeds', function () {
    ['connection' => $connection, 'conversation' => $busy] = attendedWhatsappConversation('5215511110000');
    [$other, $agent, $outbound] = committed(function () use ($connection): array {
        $department = Department::factory()->create();
        $agent = User::factory()->agent()->available()->inDepartments($department)->create();
        $attended = Conversation::factory()->inDepartment($department)->assignedTo($agent)->create(['connection_id' => $connection->id, 'status' => ConversationStatus::Open, 'last_inbound_at' => now()->subHour()]);
        ContactIdentity::factory()->create(['contact_id' => $attended->contact_id, 'external_id' => '5215522220000']);

        return [
            Conversation::factory()->inDepartment($department)->create(['connection_id' => $connection->id]),
            $agent,
            WhatsAppFixtures::outbound($attended),
        ];
    });
    $log = sendsLog();
    $impatient = ['CONCURRENCY_LOCK_WAIT' => '2'];

    $holder = ConcurrentProcess::start('hold-conversation', ['conversation_id' => $busy->id]);
    $holder->waitFor('READY');
    $holder->tell('GO');
    $holder->waitFor('HOLDING');

    $results = ConcurrentProcess::race([
        ConcurrentProcess::start('accept', ['conversation_id' => $other->id, 'user_id' => $agent->id], $impatient),
        ConcurrentProcess::start('inbound', ['connection_id' => $connection->id, 'from' => '5215522220000', 'wamid' => 'wamid.other.customer'], $impatient),
        ConcurrentProcess::start('inbound', ['connection_id' => $connection->id, 'from' => '5215533330000', 'wamid' => 'wamid.new.customer'], $impatient),
        ConcurrentProcess::start('deliver', ['message_ids' => [$outbound->id], 'sends_log' => $log], $impatient),
    ]);

    // Control: the locked conversation itself does wait for its holder.
    $blocked = ConcurrentProcess::race([ConcurrentProcess::start('inbound', ['connection_id' => $connection->id, 'from' => '5215511110000', 'wamid' => 'wamid.busy.customer'], ['CONCURRENCY_LOCK_WAIT' => '1'])])[0];

    $holder->tell('COMMIT');

    expect(failures($results))->toBe([])
        ->and(RecordingTransport::sentIds($log))->toBe([$outbound->id])
        ->and($blocked['ok'])->toBeFalse()
        ->and($blocked['message'])->toContain('Lock wait timeout')
        ->and($holder->result()['ok'])->toBeTrue();
});

/**
 * Bulk-inserts customers with WhatsApp identities and one conversation each
 * (much faster than factories). The i-th conversation's last activity is i
 * minutes ago.
 *
 * @param  Closure(int): array<string, mixed>  $conversation  Extra columns of the i-th conversation.
 * @return list<int> Conversation ids, most recent activity first.
 */
function bulkConversations(Connection $connection, int $count, Closure $conversation, int $offset = 0): array
{
    return committed(function () use ($connection, $count, $conversation, $offset): array {
        $now = now()->startOfSecond();
        $ids = [];

        foreach (array_chunk(range($offset + 1, $offset + $count), 500) as $chunk) {
            $phones = array_map(fn (int $i): string => '52155'.str_pad((string) $i, 8, '0', STR_PAD_LEFT), $chunk);
            Contact::query()->insert(array_map(fn (string $phone): array => [
                ...Contact::factory()->make(['phone_e164' => '+'.$phone])->getAttributes(),
                'created_at' => $now,
                'updated_at' => $now,
            ], $phones));
            $contacts = Contact::query()->whereIn('phone_e164', array_map(fn (string $phone): string => '+'.$phone, $phones))->pluck('id', 'phone_e164');

            ContactIdentity::query()->insert(array_map(fn (string $phone): array => [
                'contact_id' => $contacts['+'.$phone],
                'channel' => 'whatsapp',
                'provider' => 'meta_cloud_api',
                'external_id' => $phone,
                'created_at' => $now,
                'updated_at' => $now,
            ], $phones));

            Conversation::query()->insert(array_map(fn (int $i, string $phone): array => [
                'connection_id' => $connection->id,
                'contact_id' => $contacts['+'.$phone],
                'department_id' => null,
                'assigned_user_id' => null,
                'status' => ConversationStatus::Pending->value,
                'last_message_at' => $now->copy()->subMinutes($i),
                'last_inbound_at' => $now->copy()->subMinutes($i),
                'closed_at' => null,
                'created_at' => $now->copy()->subMinutes($i),
                'updated_at' => $now,
                ...$conversation($i),
            ], $chunk, $phones));
        }

        return Conversation::query()->where('connection_id', $connection->id)->orderByDesc('last_message_at')->orderByDesc('id')->pluck('id')->all();
    });
}

/**
 * The query plan of a builder, run through the autocommitted connection.
 *
 * @return list<object>
 */
function queryPlan(Builder $query): array
{
    return DB::connection('committed')->select('EXPLAIN '.$query->toSql(), $query->getBindings());
}

test('with thousands of historical conversations the inbox queries stay on indexes', function () {
    [$connection, $sales, $support] = committed(fn () => [Connection::factory()->operational()->create(), Department::factory()->create(), Department::factory()->create()]);
    [$agent, $admin] = committed(fn () => [User::factory()->agent()->available()->inDepartments($sales)->create(), User::factory()->administrator()->create()]);
    $others = committed(fn () => User::factory()->agent()->inDepartments([$sales, $support])->count(3)->create()->modelKeys());

    // 4,000 closed conversations of the last months, and 40 active ones.
    bulkConversations($connection, 4000, fn (int $i): array => [
        'department_id' => $i % 2 === 0 ? $sales->id : $support->id,
        'assigned_user_id' => $others[$i % 3],
        'status' => ConversationStatus::Closed->value,
        'last_message_at' => now()->subMinutes(600 + $i * 30),
        'closed_at' => now()->subMinutes(590 + $i * 30),
    ]);
    bulkConversations($connection, 40, fn (int $i): array => [
        'department_id' => $i % 2 === 0 ? $sales->id : $support->id,
        'assigned_user_id' => $i % 4 === 0 ? $agent->id : null,
        'status' => $i % 4 === 0 ? ConversationStatus::Open->value : ConversationStatus::Pending->value,
    ], offset: 4000);
    DB::connection('committed')->statement('ANALYZE TABLE conversations, messages, department_user');

    $inbox = app(ConversationInboxQuery::class);
    $plans = committed(function () use ($inbox, $agent, $admin, $sales): array {
        $agent = User::query()->findOrFail($agent->id);
        $admin = User::query()->findOrFail($admin->id);
        $list = fn (User $viewer, array $input) => queryPlan($inbox->withListColumns($inbox->handle($viewer, $inbox->filters($viewer, $input)), $viewer)->limit(25));

        return [
            'agent: own conversations' => $list($agent, []),
            'agent: department queue' => $list($agent, ['view' => 'queue']),
            'admin: every active conversation' => $list($admin, []),
            'admin: closed history of a department' => $list($admin, ['status' => 'closed', 'department' => (string) $sales->id, 'sort' => 'recent']),
        ];
    });

    foreach ($plans as $view => $plan) {
        foreach ($plan as $step) {
            if (in_array($step->table, ['conversations', 'messages'], true)) {
                expect($step->type)->not->toBe('ALL', "{$view}: full scan of {$step->table} (".json_encode($step).')')
                    ->and($step->key)->not->toBeNull("{$view}: no index on {$step->table}");
            }
        }
    }

    // Active views only read the active rows, not the 4,000 historical ones.
    foreach (['agent: own conversations', 'agent: department queue', 'admin: every active conversation'] as $view) {
        $conversations = collect($plans[$view])->firstWhere('table', 'conversations');
        expect((int) $conversations->rows)->toBeLessThan(400, "{$view} estimates {$conversations->rows} rows");
    }
});

test('a large export while customer messages arrive is consistent and does not block them', function () {
    $connection = committed(fn () => Connection::factory()->operational()->create());
    $admin = committed(fn () => User::factory()->administrator()->create());
    $ids = bulkConversations($connection, 1100, fn (int $i): array => []);
    $moving = committed(fn () => Conversation::query()->with('contact.identities')->findOrFail($ids[700]));
    $arrival = null;

    // As soon as the first page of the export was read, a customer of a
    // later page writes: its conversation becomes the most recent one.
    DB::listen(function (QueryExecuted $query) use (&$arrival, $connection, $moving): void {
        if ($arrival !== null || $query->connectionName !== 'committed' || ! str_contains($query->sql, 'from `conversations`') || ! str_contains($query->sql, 'limit 500 offset 0')) {
            return;
        }

        $arrival = ConcurrentProcess::race([ConcurrentProcess::start('inbound', [
            'connection_id' => $connection->id,
            'from' => $moving->contact->identities->first()->external_id,
            'wamid' => 'wamid.during.export',
            'body' => 'Escribo mientras exportan',
        ], ['CONCURRENCY_LOCK_WAIT' => '2'])])[0];
    });

    $path = committed(function () use ($admin): string {
        $exporter = app(ReportExporter::class);
        $definition = app(ReportRegistry::class)->find('conversations');
        $path = $exporter->temporaryPath(ExportFormat::Xlsx);
        $exporter->render($definition, $exporter->prepare($definition, User::query()->findOrFail($admin->id), []), ExportFormat::Xlsx, $path);

        return $path;
    });
    $sheet = IOFactory::createReader('Xlsx')->load($path)->getSheetByName('Conversaciones');
    @unlink($path);
    $exported = [];

    for ($row = 7; ($id = $sheet->getCell("A{$row}")->getValue()) !== null; $row++) {
        $exported[] = (int) $id;
    }

    expect($arrival)->not->toBeNull('The customer message did not arrive during the export.')
        ->and($arrival['ok'])->toBeTrue(json_encode($arrival))
        ->and($arrival['value'][0]['conversation_id'])->toBe($moving->id)
        ->and(count($exported))->toBe(1100)
        ->and(count(array_unique($exported)))->toBe(1100, 'Some conversations were exported twice and others were missing.')
        ->and(collect($exported)->sort()->values()->all())->toBe(collect($ids)->sort()->values()->all());
});

test('a ReadCommittedTransaction run as the outermost transaction really runs at READ COMMITTED', function () {
    $probe = ConcurrentProcess::start('read-committed-probe');
    $probe->waitFor('READY');
    $probe->tell('GO');
    $probe->waitFor('HOLDING');

    // Committed by another session between the two reads of the probe.
    committed(fn () => Contact::factory()->create());
    $probe->tell('CONTINUE');

    expect($probe->result())->toMatchArray(['ok' => true, 'value' => [0, 1]]);
});

test('a deadlock in a READ COMMITTED transaction is retried after a pause and logged, up to the attempt limit', function (int $deadlocks, bool $ok, int $retried) {
    $result = ConcurrentProcess::race([ConcurrentProcess::start('read-committed-retry', ['deadlocks' => $deadlocks])])[0];

    expect($result['ok'])->toBe($ok)
        ->and($result['retried'])->toBe(['test.retry' => $retried]);

    if ($ok) {
        expect($result['value'])->toBe($deadlocks + 1);
    } else {
        expect($result['message'])->toContain('Deadlock found');
    }
})->with([
    'recovered on the second attempt' => [1, true, 1],
    'given up after three attempts' => [3, false, 2],
]);

test('first messages of many new customers processed at once open one conversation each without exhausting retries', function () {
    $connection = committed(fn () => Connection::factory()->operational()->create());
    // Consecutive numbers: the new contacts share the end of the phone and identity indexes.
    $phones = array_map(fn (int $i): string => '52156000000'.str_pad((string) $i, 2, '0', STR_PAD_LEFT), range(1, 10));

    $results = ConcurrentProcess::race(array_map(fn (string $phone) => ConcurrentProcess::start('inbound', [
        'connection_id' => $connection->id,
        'from' => $phone,
        'wamid' => "wamid.first.{$phone}",
    ]), $phones));

    expect(failures($results))->toBe([])
        ->and(collect($results)->pluck('value.0.outcome')->unique()->values()->all())->toBe([InboundMessageOutcome::Created->value])
        ->and(committed(fn () => [Contact::query()->count(), ContactIdentity::query()->count(), Conversation::query()->count(), Message::query()->count()]))->toBe([10, 10, 10, 10])
        ->and(committed(fn () => Conversation::query()->distinct()->count('contact_id')))->toBe(10);
});

test('several messages of the same new customer and of other new customers at once: one contact and one conversation per customer, no message lost', function () {
    $connection = committed(fn () => Connection::factory()->operational()->create());
    $repeated = '5215600000100';
    $messages = [
        ...array_map(fn (int $i): array => ['from' => $repeated, 'wamid' => "wamid.same.{$i}"], range(1, 4)),
        ...array_map(fn (int $i): array => ['from' => '52156000001'.$i.'0', 'wamid' => "wamid.other.{$i}"], range(1, 4)),
    ];

    $results = ConcurrentProcess::race(array_map(fn (array $message) => ConcurrentProcess::start('inbound', ['connection_id' => $connection->id, ...$message]), $messages));

    expect(failures($results))->toBe([])
        ->and(committed(fn () => [Contact::query()->count(), ContactIdentity::query()->count(), Conversation::query()->count(), Message::query()->count()]))->toBe([5, 5, 5, 8])
        ->and(committed(fn () => Message::query()->whereLike('whatsapp_message_id', 'wamid.same.%')->distinct()->count('conversation_id')))->toBe(1)
        ->and(collect($results)->take(4)->pluck('value.0.conversation_id')->unique()->count())->toBe(1);
});

/**
 * An available agent of a new department, to be notified by other processes.
 *
 * @return array{User, Department}
 */
function notifiableAgent(): array
{
    return committed(function (): array {
        $department = Department::factory()->create();

        return [User::factory()->agent()->available()->inDepartments($department)->create(), $department];
    });
}

test('workers notifying the same agent at once with the same new aggregation key leave one unread notification counting every occurrence', function () {
    [$agent, $department] = notifiableAgent();

    $results = ConcurrentProcess::race(array_map(fn (int $i) => ConcurrentProcess::start('notify-pending', [
        'user_id' => $agent->id,
        'department_id' => $department->id,
        'conversation_id' => 1000 + $i,
        'event_id' => 5000 + $i,
    ]), range(1, 8)));

    $notification = committed(fn () => DatabaseNotification::query()->where('notifiable_id', $agent->id)->sole());

    expect(failures($results))->toBe([])
        ->and($notification->kind)->toBe(NotificationKind::ConversationPending->value)
        ->and($notification->occurrences)->toBe(8)
        ->and($notification->read_at)->toBeNull()
        ->and($notification->data['conversation_ids'])->toEqualCanonicalizing(range(1001, 1008))
        ->and(committed(fn () => DB::table('notification_receipts')->where('user_id', $agent->id)->pluck('notification_id')->all()))->toBe(array_fill(0, 8, $notification->id));
});

test('notifications stored while the agent marks everything read are neither lost nor duplicated', function () {
    [$agent, $department] = notifiableAgent();

    $results = ConcurrentProcess::race([
        ...array_map(fn (int $i) => ConcurrentProcess::start('notify-pending', [
            'user_id' => $agent->id,
            'department_id' => $department->id,
            'conversation_id' => 2000 + $i,
            'event_id' => 6000 + $i,
        ]), range(1, 6)),
        ConcurrentProcess::start('mark-all-read', ['user_id' => $agent->id]),
        ConcurrentProcess::start('mark-all-read', ['user_id' => $agent->id]),
    ]);

    $notifications = committed(fn () => DatabaseNotification::query()->where('notifiable_id', $agent->id)->get());

    expect(failures($results))->toBe([])
        ->and($notifications->sum('occurrences'))->toBe(6)
        ->and($notifications->whereNull('read_at')->count())->toBeLessThanOrEqual(1)
        ->and(committed(fn () => DB::table('notification_receipts')->where('user_id', $agent->id)->whereIn('notification_id', $notifications->modelKeys())->count()))->toBe(6);
});

/*
| Multi-agent attention through ONE WhatsApp number (several customers,
| several agents, one phone_number_id).
*/

/**
 * Conversations of the same connection, each with its own customer (a
 * WhatsApp identity) and its own available agent of the department.
 *
 * @param  list<string>  $phones
 * @return array{connection: Connection, department: Department, conversations: list<Conversation>}
 */
function conversationsOnOneNumber(array $phones): array
{
    return committed(function () use ($phones): array {
        $connection = Connection::factory()->operational()->create(['phone_number_id' => '140000000000099']);
        $department = Department::factory()->create(['name' => 'Ventas']);
        $conversations = array_map(function (string $phone) use ($connection, $department): Conversation {
            $agent = User::factory()->agent()->available()->inDepartments($department)->create();
            $contact = Contact::factory()->create(['phone_e164' => '+'.$phone]);
            ContactIdentity::factory()->create(['contact_id' => $contact->id, 'external_id' => $phone]);

            return Conversation::factory()->inDepartment($department)->assignedTo($agent)->create([
                'connection_id' => $connection->id,
                'contact_id' => $contact->id,
                'status' => ConversationStatus::Open,
                'last_inbound_at' => now()->subHour(),
            ]);
        }, $phones);

        return ['connection' => $connection, 'department' => $department, 'conversations' => $conversations];
    });
}

/**
 * The Graph sends logged by the faked Cloud API of the processes.
 *
 * @return list<array{url: string, to: string|null, reference: string|null, body: string|null}>
 */
function graphSends(string $log): array
{
    return is_file($log)
        ? array_values(array_map(fn (string $line): array => json_decode($line, true), array_filter(explode("\n", (string) file_get_contents($log)))))
        : [];
}

test('many customers writing at once to the same number: one contact and one conversation each, no message mixed up', function () {
    $connection = committed(fn () => Connection::factory()->operational()->create());
    $phones = array_map(fn (int $i): string => '52157000000'.str_pad((string) $i, 2, '0', STR_PAD_LEFT), range(1, 8));

    $results = ConcurrentProcess::race(array_map(fn (string $phone) => ConcurrentProcess::start('inbound', [
        'connection_id' => $connection->id,
        'from' => $phone,
        'wamid' => "wamid.mix.{$phone}",
        'body' => "Soy {$phone}",
    ]), $phones));

    expect(failures($results))->toBe([]);

    $messages = committed(fn () => Message::query()->with('conversation.contact.identities')->get());

    expect($messages)->toHaveCount(8)
        ->and($messages->pluck('conversation_id')->unique())->toHaveCount(8)
        ->and(committed(fn () => [Contact::query()->count(), Conversation::query()->where('connection_id', $connection->id)->count()]))->toBe([8, 8])
        ->and($messages->every(fn (Message $message): bool => $message->body === 'Soy '.$message->conversation->contact->identities->sole()->external_id
            && $message->whatsapp_message_id === 'wamid.mix.'.$message->conversation->contact->identities->sole()->external_id))->toBeTrue();
});

test('three agents replying at once to three customers of the same number: each send goes to its own customer through the same phone number id', function () {
    $phones = ['14155550301', '14155550302', '14155550303'];
    ['conversations' => $conversations] = conversationsOnOneNumber($phones);
    $log = sendsLog();
    $references = array_map(fn () => (string) Str::uuid(), $phones);

    $results = ConcurrentProcess::race(array_map(fn (int $i) => ConcurrentProcess::start('queue-outbound-graph', [
        'conversation_id' => $conversations[$i]->id,
        'user_id' => $conversations[$i]->assigned_user_id,
        'body' => "Respuesta para {$phones[$i]}",
        'client_reference' => $references[$i],
        'sends_log' => $log,
    ]), array_keys($phones)));

    expect(failures($results))->toBe([]);

    $sends = collect(graphSends($log))->keyBy('reference');

    expect($sends)->toHaveCount(3)
        ->and($sends->pluck('url')->unique()->all())->toBe(['https://graph.facebook.com/v26.0/140000000000099/messages']);

    foreach (array_keys($phones) as $i) {
        $message = committed(fn () => Message::query()->where('client_reference', $references[$i])->sole());

        expect($message->conversation_id)->toBe($conversations[$i]->id)
            ->and($message->status)->toBe(MessageStatus::Accepted)
            ->and($sends[$references[$i]]['to'])->toBe($phones[$i])
            ->and($sends[$references[$i]]['body'])->toBe("Respuesta para {$phones[$i]}");
    }
});

test('replies of one conversation picked by several queue workers leave in the order they were written, without holding back other conversations', function () {
    ['conversations' => [$chatty, $other]] = conversationsOnOneNumber(['14155550401', '14155550402']);
    [$ordered, $unrelated] = committed(fn () => [
        collect(range(1, 3))->map(fn (int $i) => WhatsAppFixtures::outbound($chatty, ['body' => "Parte {$i}"])->id)->all(),
        WhatsAppFixtures::outbound($other)->id,
    ]);
    $log = sendsLog();
    $shared = ['QUEUE_CONNECTION' => 'database', 'CONCURRENCY_WATCHDOG' => '90'];
    config(['queue.connections.database.connection' => 'committed']);

    // The newest reply is the first job in the queue.
    committed(function () use ($ordered, $unrelated): void {
        foreach ([...array_reverse($ordered), $unrelated] as $messageId) {
            app('queue')->connection('database')->pushOn(Queues::name(Queues::MESSAGES), new DeliverOutboundMessage($messageId));
        }
    });

    $results = ConcurrentProcess::race(array_map(fn () => ConcurrentProcess::start('work-deliveries', [
        'message_ids' => [...$ordered, $unrelated],
        'queue' => Queues::name(Queues::MESSAGES),
        'sends_log' => $log,
    ], $shared), range(1, 3)), 90);

    $sent = RecordingTransport::sentIds($log);
    $messages = committed(fn () => Message::query()->whereKey([...$ordered, $unrelated])->get()->keyBy('id'));

    expect(failures($results))->toBe([])
        ->and(array_values(array_filter($sent, fn (int $id): bool => in_array($id, $ordered, true))))->toBe($ordered)
        ->and($sent)->toContain($unrelated)
        ->and($sent)->toHaveCount(4)
        ->and($messages->every(fn (Message $message): bool => $message->status === MessageStatus::Accepted && $message->delivery_attempts === 1))->toBeTrue()
        ->and($messages[$ordered[2]]->metadata['delivery']['timing']['order_waits'])->toBeGreaterThanOrEqual(1)
        ->and($messages[$unrelated]->metadata['delivery']['timing']['order_waits'])->toBe(0)
        // No delivery job left behind or failed (listeners of other queues may still wait).
        ->and(committed(fn () => DB::table('jobs')->where('queue', Queues::name(Queues::MESSAGES))->count()))->toBe(0)
        ->and(committed(fn () => DB::table('failed_jobs')->count()))->toBe(0);
});

test('two agents accepting through the web at once: one is redirected to the conversation, the other gets a 409', function () {
    [$agents, $conversation] = committed(function (): array {
        $department = Department::factory()->create();

        return [
            User::factory()->agent()->available()->inDepartments($department)->count(2)->create(),
            Conversation::factory()->inDepartment($department)->create(),
        ];
    });
    $uri = route('conversations.accept', $conversation->id, false);

    $results = ConcurrentProcess::race($agents->map(fn (User $agent) => ConcurrentProcess::start('http-json', ['user_id' => $agent->id, 'uri' => $uri, 'method' => 'POST']))->all());

    expect(failures($results))->toBe([])
        ->and(collect($results)->pluck('value.status')->sort()->values()->all())->toBe([302, 409]);

    $loser = collect($results)->firstWhere('value.status', 409)['value'];
    $winner = $agents[collect($results)->search(fn (array $result): bool => $result['value']['status'] === 302)];

    expect($loser['json']['message'])->toBe('Otro agente ya está atendiendo esta conversación.')
        ->and(committed(fn () => Conversation::query()->findOrFail($conversation->id)->assigned_user_id))->toBe($winner->id)
        ->and(committed(fn () => ConversationEvent::query()->where('type', ConversationEventType::Accepted)->count()))->toBe(1);
});

test('a customer sending several quick messages, some delivered twice, at once: each is stored once, shown in the order sent, and notifies once', function () {
    ['connection' => $connection, 'conversation' => $conversation, 'agent' => $agent, 'phone' => $phone] = attendedWhatsappConversation();
    $sentAt = CarbonImmutable::now()->subMinute()->startOfSecond();
    $message = fn (int $i) => ConcurrentProcess::start('inbound', [
        'connection_id' => $connection->id,
        'from' => $phone,
        'wamid' => "wamid.burst.{$i}",
        'body' => "Parte {$i}",
        'timestamp' => $sentAt->addSeconds($i)->getTimestamp(),
    ]);

    // Newest first, and Meta repeats two of them.
    $results = ConcurrentProcess::race([$message(4), $message(3), $message(2), $message(1), $message(3), $message(2)]);

    expect(failures($results))->toBe([])
        ->and(collect($results)->pluck('value.0.outcome')->countBy()->sortKeys()->all())->toBe([InboundMessageOutcome::Appended->value => 4, InboundMessageOutcome::Duplicate->value => 2]);

    $stored = committed(fn () => Message::query()->where('conversation_id', $conversation->id)->get()->keyBy('whatsapp_message_id'));
    $page = committed(function () use ($conversation, $agent): array {
        $request = Request::create('/');
        $request->setUserResolver(fn () => User::query()->findOrFail($agent->id));

        return app(ConversationTimeline::class)->page(Conversation::query()->findOrFail($conversation->id), $request);
    });
    $notification = committed(fn () => DatabaseNotification::query()->where('notifiable_id', $agent->id)->sole());

    expect($stored)->toHaveCount(4)
        ->and(collect(range(1, 4))->every(fn (int $i): bool => $stored["wamid.burst.{$i}"]->sent_at?->getTimestamp() === $sentAt->addSeconds($i)->getTimestamp()))->toBeTrue()
        ->and(array_column($page['messages'], 'body'))->toBe(['Parte 1', 'Parte 2', 'Parte 3', 'Parte 4'])
        ->and($notification->kind)->toBe(NotificationKind::ConversationMessage->value)
        ->and($notification->occurrences)->toBe(4)
        ->and(committed(fn () => DB::table('notification_receipts')->where('user_id', $agent->id)->count()))->toBe(4);
});

test('a transfer between agents: the new agent sees the whole history, the previous one loses access, and both are signalled', function () {
    ['conversation' => $conversation, 'agent' => $previous] = attendedWhatsappConversation();
    [$admin, $support, $next, $history] = committed(function () use ($conversation): array {
        $support = Department::factory()->create(['name' => 'Soporte']);

        return [
            User::factory()->administrator()->create(),
            $support,
            User::factory()->agent()->available()->inDepartments($support)->create(),
            [
                Message::factory()->create(['conversation_id' => $conversation->id, 'body' => 'Necesito ayuda'])->id,
                WhatsAppFixtures::outbound($conversation, ['status' => 'accepted', 'whatsapp_message_id' => 'wamid.before.transfer'])->id,
            ],
        ];
    });
    $shared = ['BROADCAST_CONNECTION' => 'log'];

    $transfer = ConcurrentProcess::race([ConcurrentProcess::start('transfer-with-signals', [
        'conversation_id' => $conversation->id,
        'department_id' => $support->id,
        'user_id' => $next->id,
        'actor_id' => $admin->id,
    ], $shared)])[0];

    $uri = route('conversations.timeline', $conversation->id, false);
    [$asNext, $asPrevious] = ConcurrentProcess::race([
        ConcurrentProcess::start('http-json', ['user_id' => $next->id, 'uri' => $uri]),
        ConcurrentProcess::start('http-json', ['user_id' => $previous->id, 'uri' => $uri]),
    ]);

    expect($transfer['ok'])->toBeTrue(json_encode($transfer))
        ->and($transfer['value']['department_id'])->toBe($support->id)
        ->and($transfer['value']['assigned_user_id'])->toBe($next->id)
        ->and($asNext['value']['status'])->toBe(200)
        ->and(array_column($asNext['value']['json']['messages'], 'id'))->toBe($history)
        ->and($asPrevious['value']['status'])->toBeIn([403, 404])
        ->and(committed(fn () => ConversationEvent::query()->where('conversation_id', $conversation->id)->where('type', ConversationEventType::Transferred)->count()))->toBe(1);

    $signalled = collect($transfer['value']['signals'])->flatMap(fn (array $signal): array => array_map(fn (string $channel): string => "{$signal['event']} {$channel}", $signal['channels']))->all();

    expect($signalled)->toContain("conversation.changed conversations.{$conversation->id}")
        ->toContain("inbox.changed users.{$previous->id}")
        ->toContain("inbox.changed users.{$next->id}");
});

test('the same customer writing to two numbers at once gets one contact and one open conversation per number', function () {
    [$sales, $support] = committed(fn () => [Connection::factory()->operational()->create(), Connection::factory()->operational()->create()]);
    $phone = '5215700000999';

    $results = ConcurrentProcess::race([
        ConcurrentProcess::start('inbound', ['connection_id' => $sales->id, 'from' => $phone, 'wamid' => 'wamid.two.sales.1']),
        ConcurrentProcess::start('inbound', ['connection_id' => $support->id, 'from' => $phone, 'wamid' => 'wamid.two.support.1']),
        ConcurrentProcess::start('inbound', ['connection_id' => $sales->id, 'from' => $phone, 'wamid' => 'wamid.two.sales.2']),
        ConcurrentProcess::start('inbound', ['connection_id' => $support->id, 'from' => $phone, 'wamid' => 'wamid.two.support.2']),
    ]);

    expect(failures($results))->toBe([])
        ->and(committed(fn () => [Contact::query()->count(), ContactIdentity::query()->count(), Message::query()->count()]))->toBe([1, 1, 4]);

    $conversations = committed(fn () => Conversation::query()->whereNot('status', ConversationStatus::Closed)->get()->keyBy('connection_id'));

    expect($conversations)->toHaveCount(2)
        ->and($conversations->pluck('contact_id')->unique())->toHaveCount(1)
        ->and(committed(fn () => Message::query()->whereLike('whatsapp_message_id', 'wamid.two.sales.%')->pluck('conversation_id')->unique()->all()))->toBe([$conversations[$sales->id]->id])
        ->and(committed(fn () => Message::query()->whereLike('whatsapp_message_id', 'wamid.two.support.%')->pluck('conversation_id')->unique()->all()))->toBe([$conversations[$support->id]->id]);
});

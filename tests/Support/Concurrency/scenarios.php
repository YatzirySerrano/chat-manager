<?php

/*
| Scenarios a concurrent test process can run (see worker.php). Each one
| does what a queue worker or a web request does in production, through
| the real actions and jobs. Arguments come from the test as JSON; results
| must be JSON serializable. Scenarios that need to stay inside an open
| transaction print HOLDING and wait for COMMIT.
*/

use App\Actions\Analytics\RebuildConversationMetrics;
use App\Actions\Connections\DeleteConnection;
use App\Actions\Conversations\AcceptConversation;
use App\Actions\Conversations\AssignAutomatically;
use App\Actions\Conversations\CloseConversation;
use App\Actions\Conversations\CloseInactiveConversations;
use App\Actions\Conversations\ProcessInboundMessages;
use App\Actions\Conversations\QueueOutboundMessage;
use App\Actions\Conversations\SetConversationReadiness;
use App\Actions\Conversations\TransferConversation;
use App\Actions\Messaging\ProcessWebhookPayload;
use App\Actions\Messaging\RetryOutboundMessage;
use App\Actions\Messaging\StoreWebhookDelivery;
use App\Actions\Users\AssessRoleChange;
use App\Actions\Users\DeleteUser;
use App\Actions\Users\UpdateUser;
use App\Enums\ConversationEventType;
use App\Enums\ConversationReadiness;
use App\Events\Broadcasts\RealtimeSignal;
use App\Events\WebChat\VisitorSessionSignal;
use App\Jobs\Analytics\RefreshConversationMetrics;
use App\Jobs\DeliverOutboundMessage;
use App\Jobs\ProcessWhatsAppWebhook;
use App\Models\Connection;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Department;
use App\Models\Message;
use App\Models\Role;
use App\Models\User;
use App\Notifications\ConversationPendingNotification;
use App\Notifications\NotificationCenter;
use App\Services\Conversations\ConversationLifecycle;
use App\Services\Meta\WhatsAppTransport;
use App\Services\Sla\SlaEvaluator;
use App\Support\ReadCommittedTransaction;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Database\QueryException;
use Illuminate\Http\Client\Request as GraphRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Tests\Support\Concurrency\RecordingTransport;

/**
 * A "messages" webhook body with one customer text message, as Meta sends it.
 *
 * @param  array<string, mixed>  $arguments
 * @return array<string, mixed>
 */
$inboundPayload = fn (Connection $connection, array $arguments): array => [
    'object' => 'whatsapp_business_account',
    'entry' => [[
        'id' => (string) $connection->business_account_id,
        'changes' => [[
            'field' => 'messages',
            'value' => [
                'messaging_product' => 'whatsapp',
                'metadata' => ['display_phone_number' => '15550783881', 'phone_number_id' => $connection->phone_number_id],
                'contacts' => [['profile' => ['name' => 'Laura Pérez'], 'wa_id' => $arguments['from']]],
                'messages' => [[
                    'from' => $arguments['from'],
                    'id' => $arguments['wamid'],
                    'timestamp' => (string) ($arguments['timestamp'] ?? time()),
                    'type' => 'text',
                    'text' => ['body' => $arguments['body'] ?? 'Hola'],
                ]],
            ],
        ]],
    ]],
];

$useRecordingTransport = function (array $arguments): void {
    app()->instance(WhatsAppTransport::class, new RecordingTransport((string) $arguments['sends_log']));
};

/*
 * The real Cloud API transport, with every Graph call faked (never Meta):
 * each send is appended to the shared log as JSON (endpoint, recipient,
 * callback data, text) and answered as accepted after a short delay.
 */
$useFakedGraph = function (array $arguments): void {
    config(['whatsapp.transport' => 'meta', 'whatsapp.pilot.recipients' => [], 'services.meta.graph_version' => 'v26.0']);
    Http::preventStrayRequests();
    Http::fake(['graph.facebook.com/*' => function (GraphRequest $request) use ($arguments) {
        file_put_contents((string) $arguments['sends_log'], json_encode([
            'url' => $request->url(),
            'to' => $request['to'] ?? null,
            'reference' => $request['biz_opaque_callback_data'] ?? null,
            'body' => $request['text']['body'] ?? null,
        ])."\n", FILE_APPEND | LOCK_EX);
        usleep(100_000);

        return Http::response(['messaging_product' => 'whatsapp', 'messages' => [['id' => 'wamid.graph.'.($request['biz_opaque_callback_data'] ?? uniqid())]]]);
    }]);
};

/*
 * Realtime signals sent by this process (event name and channels).
 */
$recordSignals = function (): Closure {
    $signals = [];
    Event::listen(RealtimeSignal::class, function (RealtimeSignal $signal) use (&$signals): void {
        $signals[] = ['event' => $signal->event, 'channels' => $signal->channels];
    });

    return function () use (&$signals): array {
        return $signals;
    };
};

/*
 * Signals sent to web chat visitors by this process (type and channels).
 */
$recordVisitorSignals = function (): Closure {
    $signals = [];
    Event::listen(VisitorSessionSignal::class, function (VisitorSessionSignal $signal) use (&$signals): void {
        $signals[] = ['type' => $signal->type, 'channels' => $signal->channels];
    });

    return function () use (&$signals): array {
        return $signals;
    };
};

/*
 * One anonymous web request (no signed-in user, no session), served by
 * this process like PHP-FPM would: method, uri, optional JSON body, client
 * IP and headers (Origin, Referer, Authorization…). Returns the status and
 * the decoded JSON answer.
 *
 * @param  array<string, mixed>  $request
 * @return array{status: int, json: mixed}
 */
$guestRequest = function (array $request): array {
    $server = [
        'HTTP_ACCEPT' => 'application/json',
        'REMOTE_ADDR' => (string) ($request['ip'] ?? '127.0.0.1'),
    ];

    foreach ((array) ($request['headers'] ?? []) as $name => $value) {
        $server['HTTP_'.strtoupper(str_replace('-', '_', (string) $name))] = (string) $value;
    }

    $content = null;

    if (array_key_exists('json', $request)) {
        $server['CONTENT_TYPE'] = 'application/json';
        $content = (string) json_encode($request['json']);
    }

    $kernel = app(HttpKernel::class);
    $httpRequest = Request::create((string) $request['uri'], (string) ($request['method'] ?? 'GET'), server: $server, content: $content);
    $response = $kernel->handle($httpRequest);
    $kernel->terminate($httpRequest, $response);

    return ['status' => $response->getStatusCode(), 'json' => json_decode((string) $response->getContent(), true)];
};

return [
    /*
     * A webhook worker receiving one customer message.
     */
    'inbound' => function (array $arguments) use ($inboundPayload): array {
        $connection = Connection::query()->findOrFail((int) $arguments['connection_id']);
        $receipts = app(ProcessInboundMessages::class)->handle($inboundPayload($connection, $arguments), $connection);

        return array_map(fn ($receipt): array => [
            'outcome' => $receipt->outcome->value,
            'conversation_id' => $receipt->conversation->id,
            'message_id' => $receipt->message->id,
        ], $receipts);
    },

    /*
     * The webhook endpoint storing a (possibly repeated) body; with the sync
     * queue its processing job runs right after, like a fast worker.
     */
    'store-webhook' => function (array $arguments): int {
        $connection = Connection::query()->findOrFail((int) $arguments['connection_id']);
        $body = (string) $arguments['body'];

        return app(StoreWebhookDelivery::class)->handle($connection, $body, (array) json_decode($body, true))->id;
    },

    /*
     * A queue worker running the processing job of a stored webhook event
     * (a duplicate job, or a re-run after a worker restart).
     */
    'process-webhook-event' => function (array $arguments): string {
        app()->call([new ProcessWhatsAppWebhook((int) $arguments['event_id']), 'handle']);

        return 'processed';
    },

    /*
     * A webhook worker applying the statuses of one webhook body.
     */
    'statuses' => function (array $arguments): array {
        $connection = Connection::query()->findOrFail((int) $arguments['connection_id']);

        return app(ProcessWebhookPayload::class)->handle($connection, (array) $arguments['payload']);
    },

    'accept' => function (array $arguments): ?int {
        return app(AcceptConversation::class)->handle(
            Conversation::query()->findOrFail((int) $arguments['conversation_id']),
            User::query()->findOrFail((int) $arguments['user_id']),
        )->assigned_user_id;
    },

    /*
     * An accept whose request has not committed yet (e.g. a slow response).
     */
    'accept-and-hold' => function (array $arguments, Closure $say, Closure $await): ?int {
        return DB::transaction(function () use ($arguments, $say, $await): ?int {
            $conversation = app(AcceptConversation::class)->handle(
                Conversation::query()->findOrFail((int) $arguments['conversation_id']),
                User::query()->findOrFail((int) $arguments['user_id']),
            );

            $say('HOLDING');
            $await('COMMIT');

            return $conversation->assigned_user_id;
        });
    },

    /*
     * An accept that was applied and then fails before committing (its
     * request is rolled back when told to go on).
     */
    'accept-and-abort' => function (array $arguments, Closure $say, Closure $await): never {
        DB::transaction(function () use ($arguments, $say, $await): never {
            app(AcceptConversation::class)->handle(
                Conversation::query()->findOrFail((int) $arguments['conversation_id']),
                User::query()->findOrFail((int) $arguments['user_id']),
            );

            $say('HOLDING');
            $await('COMMIT');

            throw new RuntimeException('Aborted on purpose.');
        });
    },

    /*
     * Someone confirms a request as complete or returns it to «Necesita información».
     */
    'set-readiness' => function (array $arguments): string {
        return app(SetConversationReadiness::class)->handle(
            Conversation::query()->findOrFail((int) $arguments['conversation_id']),
            ConversationReadiness::from((string) $arguments['readiness']),
            User::query()->findOrFail((int) $arguments['user_id']),
            $arguments['reason'] ?? null,
        )->readiness->value;
    },

    /*
     * The queue of an automatic department offered again (capacity freed, a wait ended…).
     */
    'distribute-queue' => function (array $arguments): int {
        return app(AssignAutomatically::class)->distributeQueue(Department::query()->findOrFail((int) $arguments['department_id']));
    },

    /*
     * A request holding one conversation's row lock until told to finish.
     */
    'hold-conversation' => function (array $arguments, Closure $say, Closure $await): string {
        return DB::transaction(function () use ($arguments, $say, $await): string {
            Conversation::query()->lockForUpdate()->findOrFail((int) $arguments['conversation_id']);

            $say('HOLDING');
            $await('COMMIT');

            return 'released';
        });
    },

    /*
     * A transfer (to a department, optionally straight to an agent) that
     * reports the realtime signals it sent.
     */
    'transfer-with-signals' => function (array $arguments) use ($recordSignals): array {
        $signals = $recordSignals();
        $conversation = app(TransferConversation::class)->handle(
            Conversation::query()->findOrFail((int) $arguments['conversation_id']),
            Department::query()->findOrFail((int) $arguments['department_id']),
            isset($arguments['user_id']) ? User::query()->findOrFail((int) $arguments['user_id']) : null,
            User::query()->findOrFail((int) $arguments['actor_id']),
            'Transferencia de prueba de concurrencia',
        );

        return ['department_id' => $conversation->department_id, 'assigned_user_id' => $conversation->assigned_user_id, 'signals' => $signals()];
    },

    'transfer' => function (array $arguments): ?int {
        return app(TransferConversation::class)->handle(
            Conversation::query()->findOrFail((int) $arguments['conversation_id']),
            Department::query()->findOrFail((int) $arguments['department_id']),
            null,
            User::query()->findOrFail((int) $arguments['actor_id']),
            'Transferencia de prueba de concurrencia',
        )->department_id;
    },

    /*
     * An administrator changing the user's role, after reviewing its impact.
     */
    'change-role' => function (array $arguments): bool {
        $user = User::query()->findOrFail((int) $arguments['user_id']);
        $actor = User::query()->findOrFail((int) $arguments['actor_id']);
        $role = Role::query()->findOrFail((int) $arguments['role_id']);
        $impact = app(AssessRoleChange::class)->handle(User::query()->findOrFail($user->id), $role, $actor);

        return app(UpdateUser::class)->handle($user, [
            'name' => $user->name,
            'email' => $user->email,
            'role' => $role,
            'department_ids' => null,
            'role_change_fingerprint' => $impact->fingerprint(),
        ], $actor);
    },

    'delete-user' => function (array $arguments): bool {
        return app(DeleteUser::class)->handle(
            User::query()->findOrFail((int) $arguments['user_id']),
            User::query()->findOrFail((int) $arguments['actor_id']),
        )->is_active;
    },

    'delete-connection' => function (array $arguments): bool {
        return app(DeleteConnection::class)->handle(
            Connection::query()->findOrFail((int) $arguments['connection_id']),
            User::query()->findOrFail((int) $arguments['actor_id']),
        )->isDeleted();
    },

    /*
     * A queue worker running the delivery jobs of the given messages.
     */
    'deliver' => function (array $arguments) use ($useRecordingTransport): string {
        $useRecordingTransport($arguments);

        foreach ((array) $arguments['message_ids'] as $messageId) {
            app()->call([new DeliverOutboundMessage((int) $messageId), 'handle']);
        }

        return 'done';
    },

    /*
     * The agent's reply request; the sync queue delivers it right away.
     */
    'queue-outbound' => function (array $arguments) use ($useRecordingTransport): int {
        $useRecordingTransport($arguments);

        return app(QueueOutboundMessage::class)->handle(
            Conversation::query()->findOrFail((int) $arguments['conversation_id']),
            User::query()->findOrFail((int) $arguments['user_id']),
            (string) $arguments['body'],
            (string) $arguments['client_reference'],
        )->id;
    },

    /*
     * The agent's reply request through the real Cloud API transport (Graph
     * faked); the sync queue delivers it right away.
     */
    'queue-outbound-graph' => function (array $arguments) use ($useFakedGraph): int {
        $useFakedGraph($arguments);

        return app(QueueOutboundMessage::class)->handle(
            Conversation::query()->findOrFail((int) $arguments['conversation_id']),
            User::query()->findOrFail((int) $arguments['user_id']),
            (string) $arguments['body'],
            (string) $arguments['client_reference'],
        )->id;
    },

    /*
     * A messages queue worker (database queue, one job at a time) delivering
     * through the recording transport until none of the given messages is
     * queued or being sent; returns how many times it asked for a job.
     */
    'work-deliveries' => function (array $arguments) use ($useRecordingTransport): int {
        $useRecordingTransport($arguments);
        $deadline = microtime(true) + (float) ($arguments['seconds'] ?? 40);
        $runs = 0;

        while (microtime(true) < $deadline && Message::query()->whereKey((array) $arguments['message_ids'])->whereIn('status', ['queued', 'sending'])->exists()) {
            Artisan::call('queue:work', ['connection' => 'database', '--queue' => (string) $arguments['queue'], '--once' => true, '--sleep' => 0]);
            $runs++;
            usleep(50_000);
        }

        return $runs;
    },

    /*
     * A long-lived queue worker (php artisan queue:work) that stops after
     * the given number of jobs.
     */
    'queue-work' => function (array $arguments): int {
        return Artisan::call('queue:work', [
            'connection' => 'database',
            '--queue' => (string) $arguments['queue'],
            '--max-jobs' => (int) $arguments['jobs'],
            '--max-time' => 60,
            '--sleep' => 1,
            '--tries' => 1,
        ]);
    },

    /*
     * The notifications worker telling an agent that a conversation entered
     * the queue of a department (aggregated per department).
     */
    'notify-pending' => function (array $arguments): string {
        User::query()->findOrFail((int) $arguments['user_id'])->notifyNow(new ConversationPendingNotification(
            (int) $arguments['conversation_id'],
            (int) $arguments['department_id'],
            (int) $arguments['event_id'],
        ));

        return 'notified';
    },

    /*
     * The agent marking every notification read from the bell.
     */
    'mark-all-read' => function (array $arguments): int {
        return app(NotificationCenter::class)->markAllRead(User::query()->findOrFail((int) $arguments['user_id']));
    },

    /*
     * Counts contacts twice inside one ReadCommittedTransaction, letting the
     * test commit a new contact in between: READ COMMITTED sees it on the
     * second read, REPEATABLE READ would not.
     */
    'read-committed-probe' => function (array $arguments, Closure $say, Closure $await): array {
        return app(ReadCommittedTransaction::class)->run('test.probe', function () use ($say, $await): array {
            $before = Contact::query()->count();

            $say('HOLDING');
            $await('CONTINUE');

            return [$before, Contact::query()->count()];
        });
    },

    /*
     * A READ COMMITTED transaction chosen as the deadlock victim the given
     * number of times (simulated); returns the attempt that succeeded.
     */
    'read-committed-retry' => function (array $arguments): int {
        $attempt = 0;

        return app(ReadCommittedTransaction::class)->run('test.retry', function () use ($arguments, &$attempt): int {
            $attempt++;

            if ($attempt <= (int) $arguments['deadlocks']) {
                throw new QueryException('mariadb', 'insert into contacts', [], new PDOException('SQLSTATE[40001]: Serialization failure: 1213 Deadlock found when trying to get lock; try restarting transaction'));
            }

            return $attempt;
        });
    },

    'revoke-permission' => function (array $arguments): string {
        Role::query()->findOrFail((int) $arguments['role_id'])->revokePermissionTo((string) $arguments['permission']);

        return 'revoked';
    },

    /*
     * One web request of a signed-in user, served by its own process (as
     * PHP-FPM does).
     */
    'http' => function (array $arguments): int {
        Auth::guard('web')->setUser(User::query()->findOrFail((int) $arguments['user_id']));
        $request = Request::create((string) $arguments['uri'], (string) ($arguments['method'] ?? 'GET'), server: ['HTTP_ACCEPT' => 'application/json']);

        return app(HttpKernel::class)->handle($request)->getStatusCode();
    },

    /*
     * Like "http", also returning the decoded JSON answer.
     */
    'http-json' => function (array $arguments) use ($recordVisitorSignals): array {
        $visitorSignals = $recordVisitorSignals();
        Auth::guard('web')->setUser(User::query()->findOrFail((int) $arguments['user_id']));
        $request = Request::create((string) $arguments['uri'], (string) ($arguments['method'] ?? 'GET'), server: ['HTTP_ACCEPT' => 'application/json']);
        $response = app(HttpKernel::class)->handle($request);

        return [
            'status' => $response->getStatusCode(),
            'json' => json_decode((string) $response->getContent(), true),
            'visitor_signals' => $visitorSignals(),
        ];
    },

    /*
     * One anonymous request (the web chat widget, a webhook…): see
     * $guestRequest. Also reports the signals sent to web chat visitors.
     */
    'http-guest' => function (array $arguments) use ($guestRequest, $recordVisitorSignals): array {
        $visitorSignals = $recordVisitorSignals();

        return [...$guestRequest($arguments), 'visitor_signals' => $visitorSignals()];
    },

    /*
     * A web chat visitor through the widget API (the iframe's requests):
     * starts a session with the frame ticket the test issued, then sends
     * the given messages, one after the other. Returns the statuses, the
     * session's public id and token, and the cursors of the messages.
     */
    'visitor-session' => function (array $arguments) use ($guestRequest): array {
        $ip = (string) ($arguments['ip'] ?? '127.0.0.1');
        $started = $guestRequest([
            'method' => 'POST',
            'uri' => route('api.v1.widget.sessions.store', (string) $arguments['public_key'], false),
            'ip' => $ip,
            'json' => ['ticket' => (string) $arguments['ticket'], ...(isset($arguments['profile']) ? ['profile' => (array) $arguments['profile']] : [])],
        ]);
        $token = is_array($started['json']) ? ($started['json']['token'] ?? null) : null;
        $sent = [];

        foreach ($token === null ? [] : (array) ($arguments['messages'] ?? []) as $message) {
            $sent[] = $guestRequest([
                'method' => 'POST',
                'uri' => route('api.v1.widget.session.messages.store', absolute: false),
                'ip' => $ip,
                'headers' => ['Authorization' => 'Bearer '.$token],
                'json' => ['client_message_id' => (string) $message['client_message_id'], 'body' => (string) $message['body']],
            ]);
        }

        return [
            'status' => $started['status'],
            'token' => $token,
            'session' => $started['json']['data']['id'] ?? null,
            'messages' => array_map(fn (array $answer): array => ['status' => $answer['status'], 'cursor' => $answer['json']['data']['cursor'] ?? null], $sent),
        ];
    },

    /*
     * A visitor's tab sending messages with an existing session token, one
     * after the other (the same client_message_id may repeat, as a retry
     * does). Returns the status of each send.
     */
    'visitor-message' => function (array $arguments) use ($guestRequest): array {
        $statuses = [];

        foreach ((array) $arguments['messages'] as $message) {
            $statuses[] = $guestRequest([
                'method' => 'POST',
                'uri' => route('api.v1.widget.session.messages.store', absolute: false),
                'ip' => (string) ($arguments['ip'] ?? '127.0.0.1'),
                'headers' => ['Authorization' => 'Bearer '.(string) $arguments['token']],
                'json' => ['client_message_id' => (string) $message['client_message_id'], 'body' => (string) $message['body']],
            ])['status'];
        }

        return $statuses;
    },
    /*
     * A new inbound conversation of an automatic department: the department
     * row is locked first (ReceiveInboundMessage), and, when told to go on,
     * the chosen agent's row (AssignAutomatically::handle()).
     */
    'hold-department-then-lock-user' => function (array $arguments, Closure $say, Closure $await): string {
        return DB::transaction(function () use ($arguments, $say, $await): string {
            Department::query()->whereKey((int) $arguments['department_id'])->lockForUpdate()->value('id');

            $say('HOLDING');
            $await('COMMIT');

            app(ConversationLifecycle::class)->freshUser((int) $arguments['user_id']);

            return 'assigned';
        });
    },

    /*
     * An agent closes a conversation.
     */
    'close' => function (array $arguments): string {
        return app(CloseConversation::class)->handle(
            Conversation::query()->findOrFail((int) $arguments['conversation_id']),
            User::query()->findOrFail((int) $arguments['user_id']),
        )->status->value;
    },

    /*
     * conversations:close-inactive.
     */
    'close-inactive' => function (array $arguments): int {
        return app(CloseInactiveConversations::class)->handle();
    },

    /*
     * sla:evaluate claiming the breach of one cycle.
     */
    'claim-breach' => function (array $arguments): bool {
        return app(SlaEvaluator::class)->claimBreach((int) $arguments['cycle_id'], CarbonImmutable::now());
    },
    /*
     * A refresh of one conversation's metrics in progress (its lock held
     * until told to finish).
     */
    'hold-metrics-lock' => function (array $arguments, Closure $say, Closure $await): string {
        $lock = Cache::lock('analytics:conversation:'.(int) $arguments['conversation_id'], 60);

        if (! $lock->get()) {
            return 'not acquired';
        }

        $say('HOLDING');
        $await('COMMIT');
        $lock->release();

        return 'released';
    },

    /*
     * analytics:rebuild of the given conversations.
     */
    'rebuild-metrics' => function (array $arguments): int {
        return app(RebuildConversationMetrics::class)->handle(conversationIds: array_map('intval', (array) $arguments['conversation_ids']));
    },

    /*
     * The queued refresh of one conversation's metrics, run several times in a row.
     */
    'refresh-metrics' => function (array $arguments): int {
        $times = (int) ($arguments['times'] ?? 1);

        for ($run = 0; $run < $times; $run++) {
            // Each pass inserts the per-person rows again (removed by primary key).
            DB::table('conversation_agent_metrics')->whereIn('id', DB::table('conversation_agent_metrics')->where('conversation_id', (int) $arguments['conversation_id'])->pluck('id'))->delete();
            app()->call([new RefreshConversationMetrics((int) $arguments['conversation_id']), 'handle']);
        }

        return $times;
    },
    /*
     * A delivery job blocking its claimed message (DeliverOutboundMessage:
     * the message row first, then the event on its conversation), paused
     * between both steps.
     */
    'hold-message-then-record-event' => function (array $arguments, Closure $say, Closure $await): string {
        return DB::transaction(function () use ($arguments, $say, $await): string {
            $message = Message::query()->lockForUpdate()->findOrFail((int) $arguments['message_id']);

            $say('HOLDING');
            $await('COMMIT');

            app(ConversationLifecycle::class)->record(Conversation::query()->findOrFail($message->conversation_id), ConversationEventType::OutboundBlocked, null, [
                'metadata' => ['message_id' => $message->id, 'reason' => 'connection_unavailable'],
            ]);

            return 'blocked';
        });
    },

    /*
     * The agent asks to send a failed message again.
     */
    'retry-outbound' => function (array $arguments): string {
        return app(RetryOutboundMessage::class)->handle(
            Conversation::query()->findOrFail((int) $arguments['conversation_id']),
            Message::query()->findOrFail((int) $arguments['message_id']),
            User::query()->findOrFail((int) $arguments['user_id']),
        )->status->value;
    },
];

<?php

/*
| Scenarios a concurrent test process can run (see worker.php). Each one
| does what a queue worker or a web request does in production, through
| the real actions and jobs. Arguments come from the test as JSON; results
| must be JSON serializable. Scenarios that need to stay inside an open
| transaction print HOLDING and wait for COMMIT.
*/

use App\Actions\Connections\DeleteConnection;
use App\Actions\Conversations\AcceptConversation;
use App\Actions\Conversations\ProcessInboundMessages;
use App\Actions\Conversations\QueueOutboundMessage;
use App\Actions\Conversations\TransferConversation;
use App\Actions\Messaging\ProcessWebhookPayload;
use App\Actions\Messaging\StoreWebhookDelivery;
use App\Actions\Users\AssessRoleChange;
use App\Actions\Users\DeleteUser;
use App\Actions\Users\UpdateUser;
use App\Jobs\DeliverOutboundMessage;
use App\Jobs\ProcessWhatsAppWebhook;
use App\Models\Connection;
use App\Models\Conversation;
use App\Models\Department;
use App\Models\Role;
use App\Models\User;
use App\Services\Meta\WhatsAppTransport;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
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
];

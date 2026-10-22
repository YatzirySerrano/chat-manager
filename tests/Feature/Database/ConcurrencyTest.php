<?php

/*
 * These tests open two real connections to the testing database to reproduce
 * concurrent requests. Data written through them is committed, so the database
 * is rebuilt before the next test.
 */

use App\Actions\Contacts\RegisterInboundContact;
use App\Actions\Conversations\AcceptConversation;
use App\Actions\Conversations\AssignAutomatically;
use App\Actions\Conversations\AssignConversation;
use App\Actions\Conversations\ChangeAgentAvailability;
use App\Actions\Conversations\CloseConversation;
use App\Actions\Conversations\ReleaseAssignedConversations;
use App\Actions\Conversations\ReopenConversation;
use App\Actions\Conversations\TransferConversation;
use App\Actions\Departments\RemoveDepartmentMember;
use App\Actions\Users\DeleteUser;
use App\Enums\AgentAvailability;
use App\Enums\ConnectionChannel;
use App\Enums\ConnectionProvider;
use App\Enums\ConversationEventType;
use App\Enums\ConversationStatus;
use App\Enums\DistributionMode;
use App\Enums\InboundContactOutcome;
use App\Enums\InboundMessageOutcome;
use App\Enums\Permission;
use App\Exceptions\ConversationConflict;
use App\Models\Connection;
use App\Models\Contact;
use App\Models\ContactIdentity;
use App\Models\Conversation;
use App\Models\ConversationEvent;
use App\Models\Department;
use App\Models\Message;
use App\Models\User;
use App\Services\Conversations\ConversationAccess;
use App\Services\Conversations\ConversationLifecycle;
use App\Services\PlatformSettings;
use App\Support\InboundSender;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Connection as DatabaseConnection;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Open an independent connection that gives up after waiting one second for a row lock.
 */
function concurrentConnection(string $name): DatabaseConnection
{
    config(["database.connections.{$name}" => config('database.connections.mariadb')]);

    $connection = DB::connection($name);
    $connection->statement('SET SESSION innodb_lock_wait_timeout = 1');

    return $connection;
}

/**
 * Run the callback as if it were a request served through the given connection.
 *
 * @template TReturn
 *
 * @param  Closure(): TReturn  $callback
 * @return TReturn
 */
function throughConnection(string $name, Closure $callback): mixed
{
    $default = DB::getDefaultConnection();
    DB::setDefaultConnection($name);

    try {
        return $callback();
    } finally {
        DB::setDefaultConnection($default);
    }
}

afterEach(function () {
    foreach (['first', 'second'] as $name) {
        DB::connection($name)->rollBack(0);
        DB::purge($name);
    }

    RefreshDatabaseState::$migrated = false;
});

test('simultaneous attempts to remove the last two superadministrators are serialized', function () {
    $first = concurrentConnection('first');
    concurrentConnection('second');

    [$firstAdmin, $secondAdmin, $actor] = throughConnection('first', fn () => [
        User::factory()->superAdministrator()->create(),
        User::factory()->superAdministrator()->create(),
        User::factory()->supervisor()->create(),
    ]);

    $first->beginTransaction();
    throughConnection('first', fn () => app(DeleteUser::class)->handle(User::query()->findOrFail($firstAdmin->id), $actor));

    $deactivateSecondAdmin = fn () => throughConnection('second', fn () => app(DeleteUser::class)->handle(User::query()->findOrFail($secondAdmin->id), $actor));

    expect($deactivateSecondAdmin)->toThrow(QueryException::class, 'Lock wait timeout');

    $first->commit();

    expect($deactivateSecondAdmin)->toThrow(ValidationException::class, 'Es el último superadministrador activo')
        ->and(throughConnection('second', fn () => User::query()->withRootAuthority()->pluck('id')->all()))
        ->toBe([$secondAdmin->id]);
});

test('simultaneous requests cannot open two conversations for the same contact', function () {
    $first = concurrentConnection('first');
    concurrentConnection('second');

    [$account, $contact] = throughConnection('first', fn () => [Connection::factory()->create(), Contact::factory()->create()]);
    $openConversation = fn () => Conversation::factory()->for($account, 'channelConnection')->for($contact)->create();

    $first->beginTransaction();
    throughConnection('first', $openConversation);

    expect(fn () => throughConnection('second', $openConversation))->toThrow(QueryException::class, 'Lock wait timeout');

    $first->commit();

    expect(fn () => throughConnection('second', $openConversation))->toThrow(UniqueConstraintViolationException::class)
        ->and(throughConnection('second', fn () => Conversation::query()->count()))->toBe(1);
});

test('conversations being released are locked against concurrent changes', function () {
    $first = concurrentConnection('first');
    concurrentConnection('second');

    [$agent, $conversation] = throughConnection('first', function () {
        $department = Department::factory()->create();
        $agent = User::factory()->agent()->inDepartments($department)->create();

        return [$agent, Conversation::factory()->inDepartment($department)->assignedTo($agent)->create()];
    });

    $first->beginTransaction();
    throughConnection('first', fn () => app(ReleaseAssignedConversations::class)->handle($agent));

    expect(fn () => throughConnection('second', fn () => Conversation::query()->whereKey($conversation->id)->increment('version')))
        ->toThrow(QueryException::class, 'Lock wait timeout');

    $first->commit();

    expect(throughConnection('second', fn () => Conversation::query()->findOrFail($conversation->id)))
        ->assigned_user_id->toBeNull()
        ->version->toBe(1);
});

/**
 * The sender of an inbound WhatsApp message, as the webhook consumer builds it.
 */
function concurrentInboundSender(string $messageId): InboundSender
{
    return new InboundSender(
        channel: ConnectionChannel::Whatsapp,
        provider: ConnectionProvider::MetaCloudApi,
        externalId: '5215512345678',
        profileName: 'Laura',
        messageId: $messageId,
        occurredAt: CarbonImmutable::parse('2026-10-05 10:00'),
    );
}

test('two workers registering the same new sender produce a single contact', function () {
    $first = concurrentConnection('first');
    concurrentConnection('second');

    $connection = throughConnection('first', fn () => Connection::factory()->create());
    $register = fn (string $messageId) => fn () => app(RegisterInboundContact::class)->handle($connection, concurrentInboundSender($messageId));

    $first->beginTransaction();
    $winner = throughConnection('first', $register('wamid.a'));

    expect(fn () => throughConnection('second', $register('wamid.b')))->toThrow(QueryException::class, 'Lock wait timeout');

    $first->commit();

    $loser = throughConnection('second', $register('wamid.b'));

    expect($loser->contact->id)->toBe($winner->contact->id)
        ->and($loser->outcome)->toBe(InboundContactOutcome::Matched)
        ->and(throughConnection('second', fn () => [Contact::query()->count(), ContactIdentity::query()->count()]))->toBe([1, 1]);
});

test('a sender registered by another worker in the meantime is reused through the unique identity', function () {
    concurrentConnection('first');
    concurrentConnection('second');

    $connection = throughConnection('first', fn () => Connection::factory()->create());
    $otherWorkersContact = throughConnection('first', fn () => Contact::factory()->create(['name' => 'Registrado por el otro proceso', 'phone_e164' => null]));
    $racing = false;

    // Right before the second worker inserts the identity, another worker
    // commits the same one: the window a lookup cannot close on its own.
    ContactIdentity::creating(function () use (&$racing, $otherWorkersContact) {
        if ($racing) {
            return;
        }

        $racing = true;
        throughConnection('first', fn () => ContactIdentity::factory()
            ->for($otherWorkersContact)
            ->create(['external_id' => '5215512345678']));
    });

    $registration = throughConnection('second', fn () => app(RegisterInboundContact::class)->handle($connection, concurrentInboundSender('wamid.race')));

    expect($racing)->toBeTrue()
        ->and($registration->outcome)->toBe(InboundContactOutcome::Matched)
        ->and($registration->contact->name)->toBe('Registrado por el otro proceso')
        ->and(throughConnection('second', fn () => [Contact::query()->count(), ContactIdentity::query()->count()]))->toBe([1, 1]);
});

/*
| Conversation engine (increment B). Operations lock the conversation row and
| re-validate state, version and permissions once they hold the lock.
*/

test('two agents accepting the same conversation at once: only one gets it, the other a controlled conflict', function () {
    $first = concurrentConnection('first');
    concurrentConnection('second');

    [$ana, $beto, $conversation] = throughConnection('first', function () {
        $department = Department::factory()->create();

        return [
            User::factory()->agent()->available()->inDepartments($department)->create(),
            User::factory()->agent()->available()->inDepartments($department)->create(),
            Conversation::factory()->inDepartment($department)->create(),
        ];
    });
    $accept = fn (User $agent) => fn () => app(AcceptConversation::class)->handle(Conversation::query()->findOrFail($conversation->id), User::query()->findOrFail($agent->id));

    $first->beginTransaction();
    throughConnection('first', $accept($ana));

    expect(fn () => throughConnection('second', $accept($beto)))->toThrow(QueryException::class, 'Lock wait timeout');

    $first->commit();

    expect(fn () => throughConnection('second', $accept($beto)))->toThrow(ConversationConflict::class, 'Otra persona ya aceptó esta conversación.')
        ->and(throughConnection('second', fn () => Conversation::query()->findOrFail($conversation->id)))
        ->assigned_user_id->toBe($ana->id)
        ->version->toBe(1)
        ->and(throughConnection('second', fn () => ConversationEvent::query()->count()))->toBe(1);
});

test('two simultaneous transfers: the second one, decided on stale data, is rejected', function () {
    $first = concurrentConnection('first');
    concurrentConnection('second');

    [$admin, $support, $billing, $conversation] = throughConnection('first', function () {
        $sales = Department::factory()->create();
        $agent = User::factory()->agent()->inDepartments($sales)->create();

        return [
            User::factory()->administrator()->create(),
            Department::factory()->create(),
            Department::factory()->create(),
            Conversation::factory()->inDepartment($sales)->assignedTo($agent)->create(),
        ];
    });
    $transfer = fn (Department $to) => fn () => app(TransferConversation::class)->handle(
        Conversation::query()->findOrFail($conversation->id), $to, null, User::query()->findOrFail($admin->id), null, expectedVersion: 0,
    );

    $first->beginTransaction();
    throughConnection('first', $transfer($support));

    expect(fn () => throughConnection('second', $transfer($billing)))->toThrow(QueryException::class, 'Lock wait timeout');

    $first->commit();

    expect(fn () => throughConnection('second', $transfer($billing)))->toThrow(ConversationConflict::class, 'La conversación cambió mientras la consultabas')
        ->and(throughConnection('second', fn () => Conversation::query()->findOrFail($conversation->id)->department_id))->toBe($support->id);
});

test('closing and reopening at once never leave an inconsistent state', function () {
    $first = concurrentConnection('first');
    concurrentConnection('second');

    [$admin, $conversation] = throughConnection('first', function () {
        $department = Department::factory()->create();
        $agent = User::factory()->agent()->inDepartments($department)->create();

        return [User::factory()->administrator()->create(), Conversation::factory()->inDepartment($department)->assignedTo($agent)->create()];
    });
    $actor = fn () => User::query()->findOrFail($admin->id);
    $fresh = fn () => Conversation::query()->findOrFail($conversation->id);

    $first->beginTransaction();
    throughConnection('first', fn () => app(CloseConversation::class)->handle($fresh(), $actor(), 'Resuelto', 0));

    // The reopening was decided while the conversation still looked open.
    expect(fn () => throughConnection('second', fn () => app(ReopenConversation::class)->handle($fresh(), $actor(), 0)))
        ->toThrow(QueryException::class, 'Lock wait timeout');

    $first->commit();

    expect(fn () => throughConnection('second', fn () => app(ReopenConversation::class)->handle($fresh(), $actor(), 0)))
        ->toThrow(ConversationConflict::class, 'La conversación cambió mientras la consultabas');

    $reopened = throughConnection('second', fn () => app(ReopenConversation::class)->handle($fresh(), $actor(), 1));

    expect($reopened)->status->toBe(ConversationStatus::Open)->version->toBe(2)->reopen_count->toBe(1)
        ->and(throughConnection('second', fn () => ConversationEvent::query()->orderBy('id')->pluck('type')->all()))
        ->toBe([ConversationEventType::Closed, ConversationEventType::Reopened]);
});

test('two messages of the same new contact processed at once end in a single conversation', function () {
    $first = concurrentConnection('first');
    concurrentConnection('second');

    $connection = throughConnection('first', fn () => Connection::factory()->create());
    $receive = fn (string $id) => fn () => receiveWhatsapp($connection, '5215512345678', "Mensaje {$id}", CarbonImmutable::now(), $id);

    $first->beginTransaction();
    $winner = throughConnection('first', $receive('wamid.one'));

    expect(fn () => throughConnection('second', $receive('wamid.two')))->toThrow(QueryException::class, 'Lock wait timeout');

    $first->commit();

    $loser = throughConnection('second', $receive('wamid.two'));

    expect($loser->conversation->id)->toBe($winner->conversation->id)
        ->and($loser->outcome)->toBe(InboundMessageOutcome::Appended)
        ->and(throughConnection('second', fn () => [Contact::query()->count(), Conversation::query()->count(), Message::query()->count()]))->toBe([1, 1, 2]);
});

test('a conversation opened by another worker while the sender was being registered is joined', function () {
    concurrentConnection('first');
    concurrentConnection('second');

    $connection = throughConnection('first', fn () => Connection::factory()->create());
    throughConnection('first', fn () => receiveWhatsapp($connection, '5215512345678', 'Primero', id: 'wamid.seed'));
    throughConnection('first', fn () => Conversation::query()->update(['status' => 'closed', 'closed_at' => now()->subDay()]));
    $racing = false;

    // Between the sender's registration and the conversation lookup, another
    // worker commits a new conversation for the same contact and number.
    Contact::saved(function (Contact $contact) use (&$racing, $connection) {
        if ($racing) {
            return;
        }

        $racing = true;
        DB::afterCommit(fn () => throughConnection('first', fn () => Conversation::factory()->create(['connection_id' => $connection->id, 'contact_id' => $contact->id])));
    });

    $receipt = throughConnection('second', fn () => receiveWhatsapp($connection, '5215512345678', 'Segundo', id: 'wamid.race'));

    expect($racing)->toBeTrue()
        ->and($receipt->outcome)->toBe(InboundMessageOutcome::Appended)
        ->and(throughConnection('second', fn () => [Conversation::query()->count(), Conversation::query()->notClosed()->count()]))->toBe([2, 1])
        ->and($receipt->message->conversation_id)->toBe($receipt->conversation->id);
});

test('while a worker holds the contact\'s conversations, another one cannot open a parallel conversation', function () {
    $first = concurrentConnection('first');
    concurrentConnection('second');

    $connection = throughConnection('first', fn () => Connection::factory()->create());
    $contact = throughConnection('first', fn () => receiveWhatsapp($connection, '5215512345678', 'Hola', id: 'wamid.seed')->registration->contact);
    throughConnection('first', fn () => Conversation::query()->update(['status' => 'closed', 'closed_at' => now()->subDay()]));

    // The lookup of the first worker locks the range of the contact's
    // conversations, so the insert of another worker waits for it.
    $first->beginTransaction();
    throughConnection('first', fn () => Conversation::query()->where('connection_id', $connection->id)->where('contact_id', $contact->id)->notClosed()->lockForUpdate()->first());

    expect(fn () => throughConnection('second', fn () => Conversation::factory()->create(['connection_id' => $connection->id, 'contact_id' => $contact->id])))
        ->toThrow(QueryException::class, 'Lock wait timeout');

    $first->rollBack();
});

test('the same message delivered twice at once is stored only once', function () {
    concurrentConnection('first');
    concurrentConnection('second');

    $connection = throughConnection('first', fn () => Connection::factory()->create());
    $existing = throughConnection('first', fn () => receiveWhatsapp($connection, '5215512345678', 'Hola', id: 'wamid.seed'));
    $racing = false;

    // The parallel delivery stores the message right after this worker
    // registered the sender.
    Contact::saved(function () use (&$racing, $existing) {
        if ($racing) {
            return;
        }

        $racing = true;
        DB::afterCommit(fn () => throughConnection('first', fn () => Message::factory()->create([
            'conversation_id' => $existing->conversation->id,
            'whatsapp_message_id' => 'wamid.twice',
            'body' => 'Entrega paralela',
        ])));
    });

    $receipt = throughConnection('second', fn () => receiveWhatsapp($connection, '5215512345678', 'Hola otra vez', id: 'wamid.twice'));

    expect($racing)->toBeTrue()
        ->and($receipt->outcome)->toBe(InboundMessageOutcome::Duplicate)
        ->and(throughConnection('second', fn () => Message::query()->where('whatsapp_message_id', 'wamid.twice')->count()))->toBe(1);
});

test('an assignment waiting on an agent being deleted is refused once the deletion commits', function () {
    $first = concurrentConnection('first');
    concurrentConnection('second');

    [$admin, $agent, $conversation] = throughConnection('first', function () {
        $department = Department::factory()->create();

        return [
            User::factory()->administrator()->create(),
            User::factory()->agent()->inDepartments($department)->create(['name' => 'Ana']),
            Conversation::factory()->inDepartment($department)->create(),
        ];
    });
    $assign = fn () => app(AssignConversation::class)->handle(
        Conversation::query()->findOrFail($conversation->id), User::query()->findOrFail($agent->id), User::query()->findOrFail($admin->id), 0,
    );

    $first->beginTransaction();
    throughConnection('first', fn () => app(DeleteUser::class)->handle(User::query()->findOrFail($agent->id), User::query()->findOrFail($admin->id)));

    expect(fn () => throughConnection('second', $assign))->toThrow(QueryException::class, 'Lock wait timeout');

    $first->commit();

    expect(fn () => throughConnection('second', $assign))->toThrow(ConversationConflict::class, 'La cuenta de Ana está eliminada.')
        ->and(throughConnection('second', fn () => Conversation::query()->findOrFail($conversation->id)->assigned_user_id))->toBeNull();
});

test('a permission revoked while an operation waits is honored when it proceeds', function () {
    $first = concurrentConnection('first');
    concurrentConnection('second');

    [$agent, $conversation] = throughConnection('first', function () {
        $department = Department::factory()->create();
        $agent = User::factory()->agent()->available()->inDepartments($department)->create();

        return [$agent, Conversation::factory()->inDepartment($department)->create()];
    });

    // Another request holds the conversation while the role loses the permission.
    $first->beginTransaction();
    throughConnection('first', fn () => Conversation::query()->lockForUpdate()->findOrFail($conversation->id));
    throughConnection('second', fn () => User::query()->findOrFail($agent->id)->role()?->revokePermissionTo(Permission::AcceptConversations->value));

    $accept = fn () => app(AcceptConversation::class)->handle(Conversation::query()->findOrFail($conversation->id), User::query()->findOrFail($agent->id));

    expect(fn () => throughConnection('second', $accept))->toThrow(QueryException::class, 'Lock wait timeout');

    $first->commit();

    expect(fn () => throughConnection('second', $accept))->toThrow(AuthorizationException::class)
        ->and(throughConnection('second', fn () => Conversation::query()->findOrFail($conversation->id)->assigned_user_id))->toBeNull();
});

test('an operation whose snapshot predates a membership removal decides on the current membership', function () {
    concurrentConnection('first');
    $second = concurrentConnection('second');

    [$admin, $agent, $department] = throughConnection('first', function () {
        $department = Department::factory()->create();

        return [
            User::factory()->administrator()->create(),
            User::factory()->agent()->inDepartments($department)->create(),
            $department,
        ];
    });

    // An operation is already running: its consistent snapshot still shows the membership.
    $second->beginTransaction();
    expect(throughConnection('second', fn () => User::query()->findOrFail($agent->id)->departments()->count()))->toBe(1);

    // Meanwhile an administrator removes the agent from the department.
    throughConnection('first', fn () => app(RemoveDepartmentMember::class)->handle(Department::query()->findOrFail($department->id), User::query()->findOrFail($agent->id), User::query()->findOrFail($admin->id)));

    // The locked re-read that decides whether the agent can attend sees the removal.
    $fresh = throughConnection('second', fn () => app(ConversationLifecycle::class)->freshUser($agent->id));

    expect($fresh->departments)->toBeEmpty()
        ->and(app(ConversationAccess::class)->canAttend($fresh, Department::query()->findOrFail($department->id)))->toBeFalse();

    $second->rollBack();
});

test('an agent switching to unavailable while their accept waits is refused once the change commits', function () {
    $first = concurrentConnection('first');
    concurrentConnection('second');

    [$agent, $conversation] = throughConnection('first', function () {
        $department = Department::factory()->create();

        return [
            User::factory()->agent()->available()->inDepartments($department)->create(),
            Conversation::factory()->inDepartment($department)->create(),
        ];
    });
    $accept = fn () => app(AcceptConversation::class)->handle(Conversation::query()->findOrFail($conversation->id), User::query()->findOrFail($agent->id));

    $first->beginTransaction();
    throughConnection('first', fn () => app(ChangeAgentAvailability::class)->handle(User::query()->findOrFail($agent->id), AgentAvailability::Unavailable));

    expect(fn () => throughConnection('second', $accept))->toThrow(QueryException::class, 'Lock wait timeout');

    $first->commit();

    expect(fn () => throughConnection('second', $accept))->toThrow(ConversationConflict::class, 'Estás como No disponible.')
        ->and(throughConnection('second', fn () => Conversation::query()->findOrFail($conversation->id)))
        ->assigned_user_id->toBeNull()
        ->status->toBe(ConversationStatus::Pending)
        ->and(throughConnection('second', fn () => ConversationEvent::query()->count()))->toBe(0);
});

test('automatic and manual assignment at the same time leave a single responsible', function () {
    $first = concurrentConnection('first');
    concurrentConnection('second');

    [$admin, $ana, $beto, $department, $conversation] = throughConnection('first', function () {
        $department = Department::factory()->distribution(DistributionMode::Automatic)->create();

        return [
            User::factory()->administrator()->create(),
            User::factory()->agent()->available()->inDepartments($department)->create(),
            User::factory()->agent()->inDepartments($department)->create(['availability' => AgentAvailability::Unavailable]),
            $department,
            Conversation::factory()->inDepartment($department)->create(),
        ];
    });
    $assignByHand = fn () => app(AssignConversation::class)->handle(
        Conversation::query()->findOrFail($conversation->id), User::query()->findOrFail($beto->id), User::query()->findOrFail($admin->id), 0, confirmUnavailable: true,
    );

    // The distribution holds the conversation while the supervisor assigns it by hand.
    $first->beginTransaction();
    expect(throughConnection('first', fn () => app(AssignAutomatically::class)->distributeQueue(Department::query()->findOrFail($department->id))))->toBe(1);

    expect(fn () => throughConnection('second', $assignByHand))->toThrow(QueryException::class, 'Lock wait timeout');

    $first->commit();

    expect(fn () => throughConnection('second', $assignByHand))->toThrow(ConversationConflict::class, 'La conversación cambió mientras la consultabas')
        ->and(throughConnection('second', fn () => Conversation::query()->findOrFail($conversation->id)))
        ->assigned_user_id->toBe($ana->id)
        ->version->toBe(1)
        ->and(throughConnection('second', fn () => ConversationEvent::query()->pluck('type')->all()))->toBe([ConversationEventType::AutoAssigned]);
});

test('a manual assignment that wins the race is not distributed again', function () {
    $first = concurrentConnection('first');
    concurrentConnection('second');

    [$admin, $ana, $department, $conversation] = throughConnection('first', function () {
        $department = Department::factory()->distribution(DistributionMode::Automatic)->create();
        User::factory()->agent()->available()->inDepartments($department)->create();

        return [
            User::factory()->administrator()->create(),
            User::factory()->agent()->available()->inDepartments($department)->create(),
            $department,
            Conversation::factory()->inDepartment($department)->create(),
        ];
    });
    $distribute = fn () => app(AssignAutomatically::class)->distributeQueue(Department::query()->findOrFail($department->id));

    $first->beginTransaction();
    throughConnection('first', fn () => app(AssignConversation::class)->handle(Conversation::query()->findOrFail($conversation->id), User::query()->findOrFail($ana->id), User::query()->findOrFail($admin->id), 0));

    expect(fn () => throughConnection('second', $distribute))->toThrow(QueryException::class, 'Lock wait timeout');

    $first->commit();

    expect(throughConnection('second', $distribute))->toBe(0)
        ->and(throughConnection('second', fn () => Conversation::query()->findOrFail($conversation->id)->assigned_user_id))->toBe($ana->id)
        ->and(throughConnection('second', fn () => ConversationEvent::query()->pluck('type')->all()))->toBe([ConversationEventType::Assigned]);
});

test('a transfer waiting on the receiver being removed from the department is refused once the removal commits', function () {
    $first = concurrentConnection('first');
    concurrentConnection('second');

    [$admin, $receiver, $department, $conversation] = throughConnection('first', function () {
        $department = Department::factory()->create(['name' => 'Ventas']);
        $owner = User::factory()->agent()->available()->inDepartments($department)->create();

        return [
            User::factory()->administrator()->create(),
            User::factory()->agent()->available()->inDepartments($department)->create(['name' => 'Ana']),
            $department,
            Conversation::factory()->inDepartment($department)->assignedTo($owner)->create(),
        ];
    });
    $transfer = fn () => app(TransferConversation::class)->handle(
        Conversation::query()->findOrFail($conversation->id), null, User::query()->findOrFail($receiver->id), User::query()->findOrFail($admin->id), null, 0,
    );

    $first->beginTransaction();
    throughConnection('first', fn () => app(RemoveDepartmentMember::class)->handle(Department::query()->findOrFail($department->id), User::query()->findOrFail($receiver->id), User::query()->findOrFail($admin->id)));

    expect(fn () => throughConnection('second', $transfer))->toThrow(QueryException::class, 'Lock wait timeout');

    $first->commit();

    expect(fn () => throughConnection('second', $transfer))->toThrow(ConversationConflict::class, 'Ana no pertenece al departamento Ventas.')
        ->and(throughConnection('second', fn () => Conversation::query()->findOrFail($conversation->id)->assigned_user_id))->not->toBe($receiver->id);
});

test('two accepts by the same agent at once never exceed the attention limit', function () {
    $first = concurrentConnection('first');
    concurrentConnection('second');

    [$agent, $one, $two] = throughConnection('first', function () {
        app(PlatformSettings::class)->update(['operations' => ['max_open_conversations_per_agent' => 1]]);
        $department = Department::factory()->create();

        return [
            User::factory()->agent()->available()->inDepartments($department)->create(),
            Conversation::factory()->inDepartment($department)->create(),
            Conversation::factory()->inDepartment($department)->create(),
        ];
    });
    $accept = fn (Conversation $conversation) => fn () => app(AcceptConversation::class)->handle(Conversation::query()->findOrFail($conversation->id), User::query()->findOrFail($agent->id));

    $first->beginTransaction();
    throughConnection('first', $accept($one));

    expect(fn () => throughConnection('second', $accept($two)))->toThrow(QueryException::class, 'Lock wait timeout');

    $first->commit();

    expect(fn () => throughConnection('second', $accept($two)))->toThrow(ConversationConflict::class, 'Ya atiendes el máximo de 1 conversaciones permitido.')
        ->and(throughConnection('second', fn () => Conversation::query()->where('assigned_user_id', $agent->id)->count()))->toBe(1);
});

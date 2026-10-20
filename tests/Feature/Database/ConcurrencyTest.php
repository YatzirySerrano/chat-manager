<?php

/*
 * These tests open two real connections to the testing database to reproduce
 * concurrent requests. Data written through them is committed, so the database
 * is rebuilt before the next test.
 */

use App\Actions\Contacts\RegisterInboundContact;
use App\Actions\Conversations\ReleaseAssignedConversations;
use App\Actions\Users\ChangeUserStatus;
use App\Enums\ConnectionChannel;
use App\Enums\ConnectionProvider;
use App\Enums\InboundContactOutcome;
use App\Models\Connection;
use App\Models\Contact;
use App\Models\ContactIdentity;
use App\Models\Conversation;
use App\Models\Department;
use App\Models\User;
use App\Support\InboundSender;
use Carbon\CarbonImmutable;
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

test('simultaneous attempts to remove the last two administrators are serialized', function () {
    $first = concurrentConnection('first');
    concurrentConnection('second');

    [$firstAdmin, $secondAdmin, $actor] = throughConnection('first', fn () => [
        User::factory()->administrator()->create(),
        User::factory()->administrator()->create(),
        User::factory()->supervisor()->create(),
    ]);

    $first->beginTransaction();
    throughConnection('first', fn () => app(ChangeUserStatus::class)->handle(User::query()->findOrFail($firstAdmin->id), false, $actor));

    $deactivateSecondAdmin = fn () => throughConnection('second', fn () => app(ChangeUserStatus::class)->handle(User::query()->findOrFail($secondAdmin->id), false, $actor));

    expect($deactivateSecondAdmin)->toThrow(QueryException::class, 'Lock wait timeout');

    $first->commit();

    expect($deactivateSecondAdmin)->toThrow(ValidationException::class, 'Es la última autoridad administrativa activa')
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

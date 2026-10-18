<?php

/*
 * These tests open two real connections to the testing database to reproduce
 * concurrent requests. Data written through them is committed, so the database
 * is rebuilt before the next test.
 */

use App\Actions\Conversations\ReleaseAssignedConversations;
use App\Actions\Users\ChangeUserStatus;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Department;
use App\Models\User;
use App\Models\WhatsappAccount;
use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Open an independent connection that gives up after waiting one second for a row lock.
 */
function concurrentConnection(string $name): Connection
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

    [$account, $contact] = throughConnection('first', fn () => [WhatsappAccount::factory()->create(), Contact::factory()->create()]);
    $openConversation = fn () => Conversation::factory()->for($account)->for($contact)->create();

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

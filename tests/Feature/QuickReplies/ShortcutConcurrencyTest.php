<?php

/*
 * These tests open two real connections to the testing database to reproduce
 * concurrent requests. Data written through them is committed, so the database
 * is rebuilt before the next test.
 */

use App\Actions\QuickReplies\CreateQuickReply;
use App\Models\QuickReply;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

afterEach(function () {
    foreach (['shortcut_first', 'shortcut_second'] as $name) {
        DB::connection($name)->rollBack(0);
        DB::purge($name);
    }

    RefreshDatabaseState::$migrated = false;
});

test('simultaneous replies with the same shortcut in the same scope are serialized', function () {
    foreach (['shortcut_first', 'shortcut_second'] as $name) {
        config(["database.connections.{$name}" => config('database.connections.mariadb')]);
        DB::connection($name)->statement('SET SESSION innodb_lock_wait_timeout = 1');
    }

    $through = function (string $name, Closure $callback): mixed {
        $default = DB::getDefaultConnection();
        DB::setDefaultConnection($name);

        try {
            return $callback();
        } finally {
            DB::setDefaultConnection($default);
        }
    };

    $admin = $through('shortcut_first', fn () => User::factory()->administrator()->create());
    $data = ['title' => 'Saludo', 'shortcut' => 'hola', 'body' => 'Hola', 'is_global' => true, 'department_ids' => []];
    $create = fn () => app(CreateQuickReply::class)->handle($data, $admin);

    $first = DB::connection('shortcut_first');
    $first->beginTransaction();
    $through('shortcut_first', $create);

    expect(fn () => $through('shortcut_second', $create))->toThrow(QueryException::class, 'Lock wait timeout');

    $first->commit();

    expect(fn () => $through('shortcut_second', $create))->toThrow(ValidationException::class, 'El atajo /hola ya se usa')
        ->and($through('shortcut_second', fn () => QuickReply::query()->where('shortcut', 'hola')->count()))->toBe(1);
});

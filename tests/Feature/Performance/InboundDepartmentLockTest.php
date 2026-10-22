<?php

use App\Enums\ConversationStatus;
use App\Enums\DistributionMode;
use App\Models\Connection;
use App\Models\Department;
use App\Models\User;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;

/*
| A new conversation only locks its department row when the department
| distributes automatically (the lock serializes the choice of agent).
| Shared and manual departments must not be locked: under an inbound burst
| that row lock made every new conversation of the department wait for the
| previous one and took part in deadlocks (docs/rendimiento-y-carga.md).
*/

/**
 * Locking reads of the departments table, and the insert of the
 * conversation, in the order they ran while the callback runs.
 *
 * @return list<string>
 */
function departmentLocksDuring(Closure $callback): array
{
    $locks = [];
    DB::listen(function (QueryExecuted $query) use (&$locks): void {
        if (str_contains($query->sql, '`departments`') && str_contains(strtolower($query->sql), 'for update')) {
            $locks[] = $query->sql;
        } elseif (str_starts_with($query->sql, 'insert into `conversations`')) {
            $locks[] = 'insert conversation';
        }
    });

    $callback();

    return $locks;
}

test('a new conversation of a shared or manual department does not lock the department', function (DistributionMode $mode) {
    $department = Department::factory()->distribution($mode)->create();
    $connection = Connection::factory()->create(['default_department_id' => $department->id]);
    $connection->departments()->sync([$department->id]);

    $receipt = null;
    $locks = departmentLocksDuring(function () use ($connection, &$receipt): void {
        $receipt = receiveWhatsapp($connection, '5215512340001', 'Hola');
    });

    expect($locks)->toBe(['insert conversation'])
        ->and($receipt->conversation->status)->toBe(ConversationStatus::Pending)
        ->and($receipt->conversation->department_id)->toBe($department->id);
})->with([
    'shared' => DistributionMode::Shared,
    'manual' => DistributionMode::Manual,
]);

test('a new conversation of an automatic department locks it before inserting the conversation, and is assigned', function () {
    $department = Department::factory()->distribution(DistributionMode::Automatic)->create();
    $agent = User::factory()->agent()->available()->inDepartments($department)->create();
    $connection = Connection::factory()->create(['default_department_id' => $department->id]);
    $connection->departments()->sync([$department->id]);

    $receipt = null;
    $locks = departmentLocksDuring(function () use ($connection, &$receipt): void {
        $receipt = receiveWhatsapp($connection, '5215512340002', 'Hola');
    });

    // The lock comes first: taking it after the insert (whose foreign key
    // check holds a shared lock on the department) deadlocked under bursts.
    expect($locks)->not->toBeEmpty()
        ->and($locks[0])->toContain('for update')
        ->and($locks)->toContain('insert conversation')
        ->and($receipt->conversation->status)->toBe(ConversationStatus::Open)
        ->and($receipt->conversation->assigned_user_id)->toBe($agent->id);
});

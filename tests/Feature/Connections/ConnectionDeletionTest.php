<?php

use App\Actions\Connections\ChangeConnectionStatus;
use App\Enums\AuditAction;
use App\Enums\DataScope;
use App\Enums\Permission;
use App\Models\AuditLog;
use App\Models\Connection;
use App\Models\Conversation;
use App\Models\Role;
use App\Models\User;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * @param  list<Permission>  $extra
 */
function connectionManager(array $extra = []): User
{
    return User::factory()->withRole(Role::factory()->scope(DataScope::Global)->withPermissions([Permission::ManageConnections, ...$extra])->create())->create();
}

/**
 * @return array<string, mixed>
 */
function newConnectionInput(string $phoneNumberId): array
{
    return ['name' => 'Nueva', 'phone_number_id' => $phoneNumberId, 'department_ids' => []];
}

test('deleting a connection stops it, keeps its conversations and history and hides it from the operational lists', function () {
    $admin = User::factory()->superAdministrator()->create();
    $connection = Connection::factory()->operational()->create(['name' => 'Ventas']);
    $conversation = Conversation::factory()->create(['connection_id' => $connection->id]);

    $this->actingAs($admin)
        ->delete(route('connections.destroy', $connection))
        ->assertRedirect(route('connections.index'));

    $this->assertModelExists($connection);
    expect($connection->refresh())
        ->isDeleted()->toBeTrue()
        ->isOperational()->toBeFalse()
        ->is_active->toBeFalse()
        ->and($conversation->refresh()->channelConnection?->is($connection))->toBeTrue()
        ->and(AuditLog::query()->where('action', AuditAction::ConnectionDeleted)->sole()->properties)->toBe(['was_active' => true, 'conversations_kept' => 1]);

    $this->actingAs($admin)
        ->get(route('connections.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->has('connections', 0)
            ->where('stats.total', 0));

    $this->actingAs($admin)
        ->get(route('connections.index', ['record' => 'deleted']))
        ->assertInertia(fn (Assert $page) => $page
            ->has('connections', 1)
            ->where('connections.0.is_deleted', true)
            ->where('connections.0.conversations_count', 1)
            ->where('connections.0.can.restore', true)
            ->where('connections.0.can.update', false)
            ->where('connections.0.can.changeStatus', false)
            ->where('stats.total', 0));
});

test('a deleted connection is read-only: it cannot be edited, activated or verified', function () {
    $admin = User::factory()->superAdministrator()->create();
    $connection = Connection::factory()->verified()->create(['deleted_at' => now(), 'is_active' => false]);

    $this->actingAs($admin)->put(route('connections.update', $connection), ['name' => 'Otra', 'department_ids' => []])->assertForbidden();
    $this->actingAs($admin)->patch(route('connections.status.update', $connection), ['is_active' => true])->assertForbidden();
    $this->actingAs($admin)->post(route('connections.verify', $connection))->assertForbidden();

    expect(fn () => app(ChangeConnectionStatus::class)->handle($connection, true, $admin))
        ->toThrow(ValidationException::class, 'La conexión está eliminada. Restáurala antes de activarla.');

    expect($connection->refresh()->is_active)->toBeFalse();
});

test('a restored connection stays inactive and must be verified again', function () {
    $admin = User::factory()->superAdministrator()->create();
    $connection = Connection::factory()->operational()->create();

    $this->actingAs($admin)->delete(route('connections.destroy', $connection));
    $this->actingAs($admin)->patch(route('connections.restore', $connection))->assertSessionHasNoErrors();

    expect($connection->refresh())
        ->isDeleted()->toBeFalse()
        ->is_active->toBeFalse()
        ->credentials_verified_at->toBeNull()
        ->isOperational()->toBeFalse()
        ->and(AuditLog::query()->where('action', AuditAction::ConnectionRestored)->count())->toBe(1);
});

test('the phone number id of a deleted connection stays reserved and the conflict is explained', function () {
    Connection::factory()->create(['name' => 'Antigua', 'phone_number_id' => '106540352242922', 'deleted_at' => now()]);

    $this->actingAs(User::factory()->superAdministrator()->create())
        ->post(route('connections.store'), newConnectionInput('106540352242922'))
        ->assertSessionHasErrors(['phone_number_id' => 'Este Phone number ID pertenece a la conexión eliminada «Antigua». Restáurala en lugar de registrarlo de nuevo.']);

    $this->actingAs(connectionManager())
        ->post(route('connections.store'), newConnectionInput('106540352242922'))
        ->assertSessionHasErrors(['phone_number_id' => 'Este Phone number ID pertenece a una conexión eliminada. Pide a quien pueda consultar registros eliminados que la restaure.']);

    expect(Connection::query()->count())->toBe(1);
});

test('without records.view-deleted a deleted connection answers as nonexistent and cannot be restored', function () {
    $manager = connectionManager();
    $connection = Connection::factory()->create(['deleted_at' => now()]);

    $this->actingAs($manager)
        ->get(route('connections.index', ['record' => 'deleted']))
        ->assertInertia(fn (Assert $page) => $page
            ->has('connections', 0)
            ->where('filters.record', 'active')
            ->where('recordStates', []));

    $this->actingAs($manager)->get(route('connections.show', $connection))->assertNotFound();
    $this->actingAs($manager)->patch(route('connections.restore', $connection))->assertForbidden();

    $this->actingAs(connectionManager([Permission::ViewDeletedRecords]))
        ->patch(route('connections.restore', $connection))
        ->assertSessionHasNoErrors();

    expect($connection->refresh()->isDeleted())->toBeFalse();
});

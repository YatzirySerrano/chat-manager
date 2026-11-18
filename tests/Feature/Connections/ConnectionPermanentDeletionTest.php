<?php

use App\Enums\AuditAction;
use App\Enums\DefaultRole;
use App\Enums\Permission;
use App\Models\AuditLog;
use App\Models\Connection;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

test('only the Superadministrador sees and may use «Eliminar definitivamente», apart from the logical deletion', function () {
    $connection = Connection::factory()->create();

    expect(Permission::PurgeConnections->isReservedToRoot())->toBeTrue()
        ->and(DefaultRole::Administrator->permissions())->not->toContain(Permission::PurgeConnections);

    $this->actingAs(User::factory()->superAdministrator()->create())
        ->get(route('connections.show', $connection))
        ->assertInertia(fn (Assert $page) => $page->where('can.delete', true)->where('can.deletePermanently', true));

    $administrator = User::factory()->administrator()->create();

    $this->actingAs($administrator)
        ->get(route('connections.show', $connection))
        ->assertInertia(fn (Assert $page) => $page->where('can.delete', true)->where('can.deletePermanently', false));

    $this->actingAs($administrator)
        ->delete(route('connections.destroy-permanently', $connection), ['confirmation' => 'ELIMINAR'])
        ->assertForbidden();

    $this->assertModelExists($connection);
});

test('erasing a connection for good takes its conversations, messages and keeps the contacts', function () {
    $admin = User::factory()->superAdministrator()->create();
    $connection = Connection::factory()->operational()->create();
    $conversation = Conversation::factory()->closed()->create(['connection_id' => $connection->id]);
    Message::factory()->count(2)->create(['conversation_id' => $conversation->id]);

    $this->actingAs($admin)
        ->delete(route('connections.destroy-permanently', $connection), ['confirmation' => 'eliminar'])
        ->assertSessionHasNoErrors();

    $this->assertModelMissing($connection);
    $this->assertModelMissing($conversation);
    $this->assertModelExists(Contact::query()->findOrFail($conversation->contact_id));

    expect(Message::query()->count())->toBe(0)
        ->and(AuditLog::query()->where('action', AuditAction::ConnectionPermanentlyDeleted)->sole()->properties)
        ->toMatchArray(['conversations' => 1, 'messages' => 2]);
});

test('«¿Transferir mensajes a otra conexión?»: the conversations go to the chosen one before the connection is erased', function () {
    $admin = User::factory()->superAdministrator()->create();
    $connection = Connection::factory()->create();
    $keep = Connection::factory()->operational()->create();
    $conversation = Conversation::factory()->closed()->create(['connection_id' => $connection->id]);
    Message::factory()->count(2)->create(['conversation_id' => $conversation->id]);

    $this->actingAs($admin)
        ->delete(route('connections.destroy-permanently', $connection), ['confirmation' => 'ELIMINAR', 'transfer_to' => $keep->id])
        ->assertSessionHasNoErrors();

    $this->assertModelMissing($connection);

    expect($conversation->refresh()->connection_id)->toBe($keep->id)
        ->and($conversation->messages()->count())->toBe(2);
});

test('the typed confirmation is required', function () {
    $admin = User::factory()->superAdministrator()->create();
    $connection = Connection::factory()->create();

    $this->actingAs($admin)
        ->delete(route('connections.destroy-permanently', $connection), ['confirmation' => 'si'])
        ->assertSessionHasErrors('confirmation');

    $this->assertModelExists($connection);
});

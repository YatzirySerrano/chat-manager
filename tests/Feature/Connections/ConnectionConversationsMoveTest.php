<?php

use App\Enums\AuditAction;
use App\Enums\ConversationOrigin;
use App\Enums\ConversationStatus;
use App\Enums\DataScope;
use App\Enums\Permission;
use App\Models\AuditLog;
use App\Models\Connection;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;

function whaticketHistory(Connection $connection, bool $archived = false): Conversation
{
    return Conversation::factory()->closed()->create([
        'connection_id' => $connection->id,
        'origin' => ConversationOrigin::Whaticket,
        'history_only_at' => $archived ? now() : null,
    ]);
}

test('the connection lists every conversation it holds, imported and archived history included, with where to open it', function () {
    $admin = User::factory()->superAdministrator()->create();
    $historical = Connection::factory()->create(['deleted_at' => now(), 'is_active' => false]);
    $imported = whaticketHistory($historical);
    $archived = whaticketHistory($historical, archived: true);

    $response = $this->actingAs($admin)->getJson(route('connections.conversations.index', $historical))->assertOk();

    expect($response->json('meta.total'))->toBe(2)
        ->and($response->json('counts'))->toMatchArray(['all' => 2, 'imported' => 1, 'archived' => 1])
        ->and(collect($response->json('data'))->pluck('url', 'id')->all())->toEqualCanonicalizing([
            $imported->id => route('conversations.show', $imported),
            $archived->id => route('admin.history.conversations.show', $archived),
        ]);

    $this->actingAs($admin)->getJson(route('connections.conversations.index', [$historical, 'kind' => 'archived']))
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('data.0.id', $archived->id);
});

test('every conversation of a deleted historical connection moves at once to the real one', function () {
    $admin = User::factory()->superAdministrator()->create();
    $historical = Connection::factory()->create(['deleted_at' => now(), 'is_active' => false]);
    $real = Connection::factory()->operational()->create();
    $imported = whaticketHistory($historical);
    $archived = whaticketHistory($historical, archived: true);
    Message::factory()->count(3)->create(['conversation_id' => $imported->id]);

    $this->actingAs($admin)
        ->post(route('connections.conversations.move', $historical), ['target_connection_id' => $real->id, 'all' => true, 'show_archived' => true])
        ->assertSessionHasNoErrors();

    expect($imported->refresh()->connection_id)->toBe($real->id)
        ->and($archived->refresh()->connection_id)->toBe($real->id)
        ->and($archived->history_only_at)->toBeNull()
        ->and($imported->messages()->count())->toBe(3)
        ->and(DB::table('connection_contact')->where('connection_id', $real->id)->count())->toBe(2)
        ->and(AuditLog::query()->where('action', AuditAction::ConnectionConversationsMoved)->sole()->properties)
        ->toMatchArray(['moved' => 2, 'closed' => 0, 'unarchived' => 1]);
});

test('only the selection moves and nothing of another connection can be slipped in', function () {
    $admin = User::factory()->superAdministrator()->create();
    $source = Connection::factory()->create();
    $target = Connection::factory()->create();
    $other = Connection::factory()->create();
    [$chosen, $left] = Conversation::factory()->closed()->count(2)->create(['connection_id' => $source->id]);
    $foreign = Conversation::factory()->closed()->create(['connection_id' => $other->id]);

    $this->actingAs($admin)->post(route('connections.conversations.move', $source), [
        'target_connection_id' => $target->id,
        'conversation_ids' => [$chosen->id, $foreign->id],
    ])->assertSessionHasNoErrors();

    expect($chosen->refresh()->connection_id)->toBe($target->id)
        ->and($left->refresh()->connection_id)->toBe($source->id)
        ->and($foreign->refresh()->connection_id)->toBe($other->id);
});

test('a contact active on both connections keeps its most recent conversation active and the other is closed', function () {
    $admin = User::factory()->superAdministrator()->create();
    $source = Connection::factory()->create();
    $target = Connection::factory()->create();
    $contact = Contact::factory()->create();
    $newer = Conversation::factory()->create(['connection_id' => $source->id, 'contact_id' => $contact->id, 'status' => ConversationStatus::Open, 'last_message_at' => now()]);
    $older = Conversation::factory()->create(['connection_id' => $target->id, 'contact_id' => $contact->id, 'status' => ConversationStatus::Open, 'last_message_at' => now()->subDay()]);

    $this->actingAs($admin)
        ->post(route('connections.conversations.move', $source), ['target_connection_id' => $target->id, 'all' => true])
        ->assertSessionHasNoErrors();

    expect($newer->refresh())->connection_id->toBe($target->id)->status->toBe(ConversationStatus::Open)
        ->and($older->refresh()->status)->toBe(ConversationStatus::Closed);
});

test('the destination must be another, not deleted connection of the same channel', function () {
    $admin = User::factory()->superAdministrator()->create();
    $source = Connection::factory()->create();
    $conversation = Conversation::factory()->closed()->create(['connection_id' => $source->id]);

    foreach ([$source, Connection::factory()->create(['deleted_at' => now()]), Connection::factory()->webChat()->create()] as $target) {
        $this->actingAs($admin)
            ->post(route('connections.conversations.move', $source), ['target_connection_id' => $target->id, 'all' => true])
            ->assertSessionHasErrors('target_connection_id');
    }

    expect($conversation->refresh()->connection_id)->toBe($source->id);
});

test('moving requires managing connections', function () {
    $viewer = User::factory()->withRole(Role::factory()->scope(DataScope::Global)->withPermissions([Permission::ViewConnections])->create())->create();
    $source = Connection::factory()->create();
    $target = Connection::factory()->create();

    $this->actingAs($viewer)
        ->post(route('connections.conversations.move', $source), ['target_connection_id' => $target->id, 'all' => true])
        ->assertForbidden();
});

test('the merge command leaves every conversation of the channel under one connection and erases the others', function () {
    User::factory()->superAdministrator()->create();
    $keep = Connection::factory()->operational()->create();
    $old = Connection::factory()->create(['deleted_at' => now(), 'is_active' => false]);
    $other = Connection::factory()->inactive()->create();
    $imported = whaticketHistory($old, archived: true);
    $live = Conversation::factory()->closed()->create(['connection_id' => $other->id]);

    $this->artisan('connections:merge', ['target' => $keep->id, '--all' => true, '--show-archived' => true, '--purge' => true, '--force' => true])
        ->assertSuccessful();

    expect($imported->refresh()->connection_id)->toBe($keep->id)
        ->and($imported->history_only_at)->toBeNull()
        ->and($live->refresh()->connection_id)->toBe($keep->id)
        ->and(Connection::query()->whereKey([$old->id, $other->id])->exists())->toBeFalse()
        ->and(Connection::query()->count())->toBe(1);
});

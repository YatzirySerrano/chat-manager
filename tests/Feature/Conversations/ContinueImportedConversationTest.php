<?php

use App\Enums\ConversationEventType;
use App\Enums\ConversationOrigin;
use App\Enums\ConversationStatus;
use App\Models\Connection;
use App\Models\Conversation;
use App\Models\User;

function importedOn(Connection $connection): Conversation
{
    return Conversation::factory()->closed()->create([
        'connection_id' => $connection->id,
        'origin' => ConversationOrigin::Whaticket,
    ]);
}

test('«Reabrir» on an imported conversation starts a live one with the customer and keeps the history read-only', function () {
    $admin = User::factory()->superAdministrator()->create();
    $connection = Connection::factory()->whatsAppWeb()->create();
    $imported = importedOn($connection);

    $this->actingAs($admin)
        ->post(route('conversations.reopen', $imported), ['version' => $imported->version])
        ->assertSessionHasNoErrors()
        ->assertRedirect();

    $live = Conversation::query()->whereKeyNot($imported->id)->where('contact_id', $imported->contact_id)->sole();

    expect($live->origin)->toBe(ConversationOrigin::Live)
        ->and($live->connection_id)->toBe($connection->id)
        ->and($live->status)->not->toBe(ConversationStatus::Closed)
        ->and($live->events()->where('type', ConversationEventType::Created)->value('actor_user_id'))->toBe($admin->id)
        ->and($imported->refresh()->status)->toBe(ConversationStatus::Closed)
        ->and($imported->origin)->toBe(ConversationOrigin::Whaticket);
});

test('it goes to the live conversation the customer already has instead of creating another', function () {
    $admin = User::factory()->superAdministrator()->create();
    $connection = Connection::factory()->whatsAppWeb()->create();
    $imported = importedOn($connection);
    $open = Conversation::factory()->create(['connection_id' => $connection->id, 'contact_id' => $imported->contact_id]);

    $this->actingAs($admin)
        ->post(route('conversations.reopen', $imported), ['version' => $imported->version])
        ->assertRedirect(route('conversations.show', $open));

    expect(Conversation::query()->where('contact_id', $imported->contact_id)->count())->toBe(2);
});

test('the button shows on imported history only while its connection exists', function () {
    $admin = User::factory()->superAdministrator()->create();
    $imported = importedOn(Connection::factory()->whatsAppWeb()->create());
    $orphan = importedOn(Connection::factory()->create(['deleted_at' => now(), 'is_active' => false]));

    expect($admin->can('continueImported', $imported))->toBeTrue()
        ->and($admin->can('continueImported', $orphan))->toBeFalse()
        ->and($admin->can('reopen', $imported))->toBeFalse();
});

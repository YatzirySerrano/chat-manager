<?php

use App\Enums\AuditAction;
use App\Enums\ConversationEventType;
use App\Enums\ConversationOrigin;
use App\Enums\DataScope;
use App\Enums\Permission;
use App\Models\AuditLog;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\ConversationNote;
use App\Models\DataTransfer;
use App\Models\Department;
use App\Models\Message;
use App\Models\MessageAttachment;
use App\Models\Role;
use App\Models\Tag;
use App\Models\User;
use App\Services\Storage\MediaThumbnails;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;

/**
 * A conversation with everything that hangs from it: two messages (one
 * replying to the other), a stored file with its preview, a note, a
 * timeline event, a tag, a notification and the keys of the importer.
 *
 * @return array{conversation: Conversation, contact: Contact, path: string, messages: list<int>}
 */
function conversationWithEverything(?Department $department = null): array
{
    Storage::fake('local');
    config(['filesystems.media_disk' => 'local']);

    $conversation = $department === null
        ? Conversation::factory()->create()
        : Conversation::factory()->inDepartment($department)->create();

    $first = Message::factory()->create(['conversation_id' => $conversation->id]);
    $second = Message::factory()->create(['conversation_id' => $conversation->id, 'reply_to_message_id' => $first->id]);

    $path = 'media/'.Str::uuid().'.jpg';
    Storage::disk('local')->put($path, 'file');

    foreach (MediaThumbnails::pathsFor($path) as $preview) {
        Storage::disk('local')->put($preview, 'preview');
    }

    MessageAttachment::query()->create([
        'message_id' => $second->id,
        'disk' => 'local',
        'path' => $path,
        'mime_type' => 'image/jpeg',
        'file_name' => 'foto.jpg',
        'size_bytes' => 4,
    ]);

    ConversationNote::factory()->create(['conversation_id' => $conversation->id]);
    $conversation->tags()->attach(Tag::factory()->create());

    DB::table('conversation_events')->insert([
        'conversation_id' => $conversation->id,
        'type' => ConversationEventType::cases()[0]->value,
        'created_at' => now(),
    ]);

    $reader = User::factory()->agent()->create();

    DB::table('notifications')->insert([
        'id' => (string) Str::uuid(),
        'type' => 'test',
        'notifiable_type' => 'user',
        'notifiable_id' => $reader->id,
        'kind' => 'conversation_message',
        'level' => 'info',
        'occurrences' => 1,
        'data' => json_encode(['conversation_id' => $conversation->id]),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $transfer = DataTransfer::factory()->create();

    foreach ([['conversation', $conversation->id, 'ticket-1'], ['message', $first->id, 'msg-1']] as [$type, $id, $source]) {
        DB::table('import_source_keys')->insert([
            'source_system' => 'whaticket',
            'source_instance' => 'test',
            'source_kind' => $type,
            'source_id' => $source,
            'target_type' => $type,
            'target_id' => $id,
            'data_transfer_id' => $transfer->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    return ['conversation' => $conversation, 'contact' => $conversation->contact, 'path' => $path, 'messages' => [$first->id, $second->id]];
}

test('the root authority erases a chat with its messages, files and history, and the contact survives', function () {
    $root = User::factory()->superAdministrator()->create();
    $set = conversationWithEverything();
    $conversation = $set['conversation'];

    $this->actingAs($root)
        ->delete(route('conversations.destroy-permanently', $conversation), ['confirmation' => 'eliminar'])
        ->assertRedirect();

    expect(Conversation::query()->whereKey($conversation->id)->exists())->toBeFalse()
        ->and(Message::query()->whereIn('id', $set['messages'])->count())->toBe(0)
        ->and(MessageAttachment::query()->where('path', $set['path'])->count())->toBe(0)
        ->and(DB::table('conversation_notes')->where('conversation_id', $conversation->id)->count())->toBe(0)
        ->and(DB::table('conversation_events')->where('conversation_id', $conversation->id)->count())->toBe(0)
        ->and(DB::table('conversation_tag')->where('conversation_id', $conversation->id)->count())->toBe(0)
        ->and(DB::table('notifications')->where('data->conversation_id', $conversation->id)->count())->toBe(0)
        ->and(DB::table('import_source_keys')->whereIn('source_id', ['ticket-1', 'msg-1'])->count())->toBe(0)
        ->and(Contact::query()->whereKey($set['contact']->id)->exists())->toBeTrue();

    Storage::disk('local')->assertMissing($set['path']);

    foreach (MediaThumbnails::pathsFor($set['path']) as $preview) {
        Storage::disk('local')->assertMissing($preview);
    }

    $entry = AuditLog::query()->where('action', AuditAction::ConversationPermanentlyDeleted)->sole();

    expect($entry->restricted)->toBeTrue()
        ->and($entry->user_id)->toBe($root->id)
        ->and($entry->properties)->toMatchArray(['conversation_id' => $conversation->id, 'messages' => 2, 'attachments' => 1, 'files' => 1])
        ->and(json_encode($entry->properties))->not->toContain('foto.jpg');
});

test('erasing a chat needs the typed confirmation', function () {
    $root = User::factory()->superAdministrator()->create();
    $conversation = conversationWithEverything()['conversation'];

    $this->actingAs($root)
        ->from(route('conversations.index'))
        ->delete(route('conversations.destroy-permanently', $conversation), ['confirmation' => 'borrar'])
        ->assertSessionHasErrors('confirmation');

    $this->actingAs($root)
        ->delete(route('conversations.destroy-permanently', $conversation))
        ->assertSessionHasErrors('confirmation');

    expect(Conversation::query()->whereKey($conversation->id)->exists())->toBeTrue();
});

test('nobody below the root authority erases a chat, even with the permission stored on their role', function () {
    $conversation = conversationWithEverything()['conversation'];
    $administrator = User::factory()->administrator()->create();

    // The permission is reserved: a role that somehow stores it never exercises it.
    $forged = Role::factory()->scope(DataScope::Global)->withPermissions([Permission::ViewConversations, Permission::SuperviseConversations])->create();
    DB::table('role_has_permissions')->insert([
        'permission_id' => DB::table('permissions')->where('name', Permission::PurgeConversations->value)->value('id'),
        'role_id' => $forged->id,
    ]);
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $holder = User::factory()->withRole($forged)->create();

    foreach ([$administrator, $holder] as $actor) {
        $this->actingAs($actor)
            ->delete(route('conversations.destroy-permanently', $conversation), ['confirmation' => 'ELIMINAR'])
            ->assertForbidden();
    }

    expect(Conversation::query()->whereKey($conversation->id)->exists())->toBeTrue()
        ->and($administrator->holdsPermission(Permission::PurgeConversations))->toBeFalse()
        ->and($holder->holdsPermission(Permission::PurgeConversations))->toBeFalse();
});

test('a chat out of reach answers like one that does not exist', function () {
    $department = Department::factory()->create();
    $conversation = conversationWithEverything($department)['conversation'];
    $agent = User::factory()->agent()->create();

    $this->actingAs($agent)
        ->delete(route('conversations.destroy-permanently', $conversation), ['confirmation' => 'ELIMINAR'])
        ->assertNotFound();

    expect(Conversation::query()->whereKey($conversation->id)->exists())->toBeTrue();
});

test('a guest is sent to sign in', function () {
    $conversation = conversationWithEverything()['conversation'];

    $this->delete(route('conversations.destroy-permanently', $conversation), ['confirmation' => 'ELIMINAR'])
        ->assertRedirect(route('login'));

    expect(Conversation::query()->whereKey($conversation->id)->exists())->toBeTrue();
});

test('a file another chat still uses is kept on the storage', function () {
    $root = User::factory()->superAdministrator()->create();
    $set = conversationWithEverything();

    $other = Message::factory()->create();
    MessageAttachment::query()->create([
        'message_id' => $other->id,
        'disk' => 'local',
        'path' => $set['path'],
        'mime_type' => 'image/jpeg',
        'file_name' => 'foto.jpg',
        'size_bytes' => 4,
    ]);

    $this->actingAs($root)
        ->delete(route('conversations.destroy-permanently', $set['conversation']), ['confirmation' => 'ELIMINAR'])
        ->assertRedirect();

    Storage::disk('local')->assertExists($set['path']);
    expect(MessageAttachment::query()->where('message_id', $other->id)->exists())->toBeTrue();
});

test('imported history can be erased too', function () {
    $root = User::factory()->superAdministrator()->create();
    $set = conversationWithEverything();
    $imported = collect(ConversationOrigin::cases())->first(fn (ConversationOrigin $origin): bool => $origin !== ConversationOrigin::Live);
    $set['conversation']->forceFill(['origin' => $imported, 'history_only_at' => now()])->save();

    $this->actingAs($root)
        ->delete(route('conversations.destroy-permanently', $set['conversation']), ['confirmation' => 'ELIMINAR'])
        ->assertRedirect();

    expect(Conversation::query()->whereKey($set['conversation']->id)->exists())->toBeFalse();
});

test('only the root authority is offered the deletion', function () {
    $root = User::factory()->superAdministrator()->create();
    $administrator = User::factory()->administrator()->create();
    $conversation = conversationWithEverything()['conversation'];

    expect($root->can('deletePermanently', $conversation))->toBeTrue()
        ->and($administrator->can('deletePermanently', $conversation))->toBeFalse();
});

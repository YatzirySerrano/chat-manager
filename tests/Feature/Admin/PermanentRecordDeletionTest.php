<?php

use App\Enums\AuditAction;
use App\Enums\DefaultRole;
use App\Enums\Permission;
use App\Models\AuditLog;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\ConversationCategory;
use App\Models\QuickReply;
use App\Models\Role;
use App\Models\Tag;
use App\Models\User;
use Illuminate\Support\Facades\DB;

test('the permanent deletion permissions are irreversible, sensitive, global and reserved to the root authority', function () {
    foreach (Permission::permanentDeletions() as $permission) {
        expect($permission->isIrreversible())->toBeTrue()
            ->and($permission->isSensitive())->toBeTrue()
            ->and($permission->requiresGlobalScope())->toBeTrue()
            ->and($permission->isReservedToRoot())->toBeTrue()
            ->and($permission->group()->value)->toBe('permanent-deletion');
    }

    expect(DefaultRole::SuperAdministrator->permissions())->toContain(Permission::PurgeConversations)
        ->and(DefaultRole::Administrator->permissions())->not->toContain(Permission::PurgeConversations)
        ->and(DefaultRole::Supervisor->permissions())->not->toContain(Permission::PurgeConversations)
        ->and(DefaultRole::Agent->permissions())->not->toContain(Permission::PurgeConversations);
});

test('the migration grants the permanent deletion permissions to the root role only', function () {
    $holders = DB::table('role_has_permissions')
        ->join('permissions', 'permissions.id', '=', 'role_has_permissions.permission_id')
        ->join('roles', 'roles.id', '=', 'role_has_permissions.role_id')
        ->where('permissions.name', 'like', 'permanent-deletion.%')
        ->pluck('roles.is_root')
        ->unique()
        ->all();

    expect(DB::table('permissions')->where('name', 'like', 'permanent-deletion.%')->count())->toBe(count(Permission::permanentDeletions()))
        ->and($holders)->each->toBeTruthy();
});

test('the roles screen shows the group to the root authority and hides it from everyone else', function () {
    $root = User::factory()->superAdministrator()->create();
    $administrator = User::factory()->administrator()->create();

    $groupOf = fn (User $viewer): ?array => collect(
        $this->actingAs($viewer)->get(route('admin.roles.index'))->assertOk()->viewData('page')['props']['catalog'],
    )->firstWhere('value', 'permanent-deletion');

    $group = $groupOf($root);

    expect($group)->not->toBeNull()
        ->and($group['label'])->toBe('Eliminación definitiva')
        ->and(collect($group['permissions'])->pluck('irreversible')->unique()->all())->toBe([true])
        ->and($groupOf($administrator))->toBeNull();
});

test('a role below the root authority can never be given a permanent deletion permission', function () {
    $root = User::factory()->superAdministrator()->create();
    $role = Role::factory()->create();

    $this->actingAs($root)
        ->from(route('admin.roles.index'))
        ->put(route('admin.roles.update', $role), [
            'label' => $role->label,
            'description' => $role->description,
            'scope' => $role->scope->value,
            'permissions' => [Permission::ViewDashboard->value, Permission::PurgeConversations->value],
        ])
        ->assertSessionHasErrors('permissions');

    expect($role->fresh()->permissionNames())->not->toContain(Permission::PurgeConversations->value);
});

test('a deleted tag is erased for good and taken off contacts and conversations', function () {
    $root = User::factory()->superAdministrator()->create();
    $tag = Tag::factory()->create();
    $contact = Contact::factory()->create();
    $conversation = Conversation::factory()->create();
    $contact->tags()->attach($tag);
    $conversation->tags()->attach($tag);

    // An active record is never erased: it has to be deleted first.
    $this->actingAs($root)
        ->delete(route('tags.destroy-permanently', $tag), ['confirmation' => 'ELIMINAR'])
        ->assertForbidden();

    $tag->forceFill(['is_active' => false])->save();

    $this->actingAs($root)
        ->delete(route('tags.destroy-permanently', $tag), ['confirmation' => 'ELIMINAR'])
        ->assertRedirect();

    expect(Tag::query()->whereKey($tag->id)->exists())->toBeFalse()
        ->and(DB::table('contact_tag')->where('tag_id', $tag->id)->count())->toBe(0)
        ->and(DB::table('conversation_tag')->where('tag_id', $tag->id)->count())->toBe(0)
        ->and($contact->fresh())->not->toBeNull()
        ->and($conversation->fresh())->not->toBeNull();

    $entry = AuditLog::query()->where('action', AuditAction::RecordPermanentlyDeleted)->sole();

    expect($entry->restricted)->toBeTrue()
        ->and($entry->properties)->toMatchArray(['type' => 'tag', 'contacts' => 1, 'conversations' => 1]);
});

test('a deleted category is erased for good and its conversations are left without category', function () {
    $root = User::factory()->superAdministrator()->create();
    $category = ConversationCategory::factory()->create();
    $conversation = Conversation::factory()->create();
    $conversation->forceFill(['category_id' => $category->id])->save();
    $category->forceFill(['is_active' => false])->save();

    $this->actingAs($root)
        ->delete(route('categories.destroy-permanently', $category), ['confirmation' => 'ELIMINAR'])
        ->assertRedirect();

    expect(ConversationCategory::query()->whereKey($category->id)->exists())->toBeFalse()
        ->and($conversation->fresh()->category_id)->toBeNull();
});

test('a deleted quick reply is erased for good', function () {
    $root = User::factory()->superAdministrator()->create();
    $reply = QuickReply::factory()->create();
    $reply->forceFill(['is_active' => false])->save();

    $this->actingAs($root)
        ->delete(route('quick-replies.destroy-permanently', $reply), ['confirmation' => 'ELIMINAR'])
        ->assertRedirect();

    expect(QuickReply::query()->whereKey($reply->id)->exists())->toBeFalse();
});

test('erasing a record needs the typed confirmation and the root authority', function () {
    $root = User::factory()->superAdministrator()->create();
    $administrator = User::factory()->administrator()->create();
    $tag = Tag::factory()->create();
    $tag->forceFill(['is_active' => false])->save();

    $this->actingAs($root)
        ->from(route('tags.index'))
        ->delete(route('tags.destroy-permanently', $tag), ['confirmation' => 'si'])
        ->assertSessionHasErrors('confirmation');

    $this->actingAs($administrator)
        ->delete(route('tags.destroy-permanently', $tag), ['confirmation' => 'ELIMINAR'])
        ->assertForbidden();

    expect(Tag::query()->whereKey($tag->id)->exists())->toBeTrue();
});

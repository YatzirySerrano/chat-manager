<?php

use App\Enums\AttachmentDeliveryMethod;
use App\Enums\DefaultRole;
use App\Models\ChatAppearance;
use App\Models\Connection;
use App\Models\Contact;
use App\Models\ContactAlias;
use App\Models\Conversation;
use App\Models\MessageAttachment;
use App\Models\QuickReply;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * @return list<string>
 */
function phaseFivePermissionsOf(Role $role): array
{
    return DB::table('role_has_permissions')
        ->join('permissions', 'permissions.id', '=', 'role_has_permissions.permission_id')
        ->where('role_id', $role->id)
        ->orderBy('permissions.name')
        ->pluck('permissions.name')
        ->all();
}

function phaseFivePermissionId(string $name): ?int
{
    $id = DB::table('permissions')->where('name', $name)->where('guard_name', 'web')->value('id');

    return $id === null ? null : (int) $id;
}

test('a user has one default chat appearance and one per conversation', function () {
    $user = User::factory()->create();
    $conversation = Conversation::factory()->create();

    $default = ChatAppearance::factory()->for($user)->create();
    $override = ChatAppearance::factory()->for($user)->forConversation($conversation)->create();

    expect($default->refresh()->conversation_key)->toBe(0)
        ->and($override->refresh()->conversation_key)->toBe($conversation->id)
        ->and($default->colors['light']['sent_bg'])->toBe('#DCF8C6')
        ->and(ChatAppearance::query()->defaults()->pluck('id')->all())->toBe([$default->id]);

    expect(fn () => ChatAppearance::factory()->for($user)->create())->toThrow(UniqueConstraintViolationException::class);
    expect(fn () => ChatAppearance::factory()->for($user)->forConversation($conversation)->create())->toThrow(UniqueConstraintViolationException::class);

    ChatAppearance::factory()->create();

    expect(ChatAppearance::query()->count())->toBe(3);
});

test('a user has a single alias per contact', function () {
    $alias = ContactAlias::factory()->create();

    expect($alias->contact->aliases->pluck('id')->all())->toBe([$alias->id])
        ->and($alias->user->contactAliases->pluck('id')->all())->toBe([$alias->id]);

    expect(fn () => ContactAlias::factory()->create(['user_id' => $alias->user_id, 'contact_id' => $alias->contact_id]))
        ->toThrow(UniqueConstraintViolationException::class);
});

test('contacts are saved or provisional', function () {
    $saved = Contact::factory()->create();
    $provisional = Contact::factory()->provisional()->create();

    expect($saved->isSaved())->toBeTrue()
        ->and($provisional->isSaved())->toBeFalse()
        ->and(Contact::query()->savedInDirectory()->pluck('id')->all())->toBe([$saved->id])
        ->and(Contact::query()->provisional()->pluck('id')->all())->toBe([$provisional->id]);
});

test('quick replies are personal when they have an owner', function () {
    $owner = User::factory()->create();
    $personal = QuickReply::factory()->personal($owner)->create();
    $corporate = QuickReply::factory()->create();
    QuickReply::factory()->personal(User::factory()->create())->create();

    expect($personal->isPersonal())->toBeTrue()
        ->and($corporate->isPersonal())->toBeFalse()
        ->and(QuickReply::query()->personal($owner)->pluck('id')->all())->toBe([$personal->id])
        ->and(QuickReply::query()->corporate()->pluck('id')->all())->toBe([$corporate->id])
        ->and($owner->personalQuickReplies->pluck('id')->all())->toBe([$personal->id]);
});

test('attachments are delivered natively unless stated otherwise', function () {
    expect((new MessageAttachment)->delivery_method)->toBe(AttachmentDeliveryMethod::Native);
});

test('the Meta account of a connection is unique per channel', function () {
    $first = Connection::factory()->create();
    DB::table('connections')->where('id', $first->id)->update(['channel' => 'messenger', 'external_account_id' => '1001']);
    $second = Connection::factory()->create();
    DB::table('connections')->where('id', $second->id)->update(['channel' => 'instagram', 'external_account_id' => '1001']);

    expect(fn () => DB::table('connections')->where('id', $second->id)->update(['channel' => 'messenger']))
        ->toThrow(UniqueConstraintViolationException::class);
});

test('the phase five permissions are granted to the holders of their predecessors and the root roles, and it can be reversed', function () {
    $new = ['conversations.supervise', 'templates.send', 'quick-replies.personal', 'supervision.view-personal', 'supervision.manage-personal'];
    DB::table('permissions')->whereIn('name', $new)->delete();

    $assigner = Role::factory()->create();
    $templates = Role::factory()->create();
    $usersEditor = Role::factory()->create();
    $quickReplies = Role::factory()->create();
    DB::table('role_has_permissions')->insert([
        ['role_id' => $assigner->id, 'permission_id' => phaseFivePermissionId('conversations.assign')],
        ['role_id' => $templates->id, 'permission_id' => phaseFivePermissionId('templates.manage')],
        ['role_id' => $usersEditor->id, 'permission_id' => phaseFivePermissionId('users.update')],
        ['role_id' => $quickReplies->id, 'permission_id' => phaseFivePermissionId('quick-replies.manage')],
    ]);
    $user = User::factory()->create();
    DB::table('model_has_permissions')->insert(['permission_id' => phaseFivePermissionId('templates.manage'), 'model_type' => 'user', 'model_id' => $user->id]);

    /** @var Migration $migration */
    $migration = require database_path('migrations/2026_10_24_185555_add_phase_five_permissions.php');
    $migration->up();
    $migration->up();

    expect(phaseFivePermissionsOf($assigner))->toBe(['conversations.assign', 'conversations.supervise'])
        ->and(phaseFivePermissionsOf($templates))->toBe(['templates.manage', 'templates.send'])
        ->and(phaseFivePermissionsOf($usersEditor))->toBe(['supervision.manage-personal', 'supervision.view-personal', 'users.update'])
        ->and(phaseFivePermissionsOf($quickReplies))->toBe(['quick-replies.manage', 'quick-replies.personal'])
        ->and(DB::table('model_has_permissions')->where('model_id', $user->id)->where('permission_id', phaseFivePermissionId('templates.send'))->exists())->toBeTrue()
        ->and(phaseFivePermissionsOf(role(DefaultRole::SuperAdministrator)))->toContain(...$new)
        ->and(phaseFivePermissionsOf(role(DefaultRole::Agent)))->not->toContain('templates.send', 'conversations.supervise', 'supervision.view-personal');

    $migration->down();

    expect(DB::table('permissions')->whereIn('name', $new)->exists())->toBeFalse()
        ->and(phaseFivePermissionsOf($assigner))->toBe(['conversations.assign']);
});

test('the default roles receive the new permissions', function () {
    expect(role(DefaultRole::Supervisor)->hasPermissionTo('conversations.supervise'))->toBeTrue()
        ->and(role(DefaultRole::Supervisor)->hasPermissionTo('templates.send'))->toBeFalse()
        ->and(role(DefaultRole::Agent)->hasPermissionTo('conversations.supervise'))->toBeFalse()
        ->and(role(DefaultRole::Administrator)->hasPermissionTo('supervision.manage-personal'))->toBeTrue()
        ->and(role(DefaultRole::Administrator)->hasPermissionTo('templates.send'))->toBeTrue();
});

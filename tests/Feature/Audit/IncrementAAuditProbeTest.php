<?php

use App\Enums\DataScope;
use App\Enums\Permission;
use App\Models\Connection;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Department;
use App\Models\QuickReply;
use App\Models\Role;
use App\Models\Tag;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

/*
| Probes written by the Increment A security audit. Each test asserts the
| SECURE/expected behavior, so a failing test documents a confirmed defect.
*/

test('connection secrets are not flashed into the session when validation fails', function () {
    $admin = User::factory()->administrator()->create();

    $this->actingAs($admin)
        ->from(route('connections.create'))
        ->post(route('connections.store'), [
            'name' => str_repeat('x', 200),
            'department_ids' => [],
            'access_token' => 'EAAGsecretTokenValue1234567890',
            'app_secret' => 'super-secret-app-secret-value',
            'webhook_verify_token' => 'super-secret-verify-token',
        ])
        ->assertSessionHasErrors('name');

    $old = session()->getOldInput();

    expect($old)->not->toHaveKey('access_token')
        ->and($old)->not->toHaveKey('app_secret')
        ->and($old)->not->toHaveKey('webhook_verify_token');
});

test('a shortcut conflict only names departments the actor shares', function () {
    [$sales, $support] = Department::factory()->count(2)->create();
    $sales->update(['name' => 'Ventas Secretas']);
    QuickReply::factory()->forDepartments($sales, $support)->create(['shortcut' => 'promo', 'title' => 'Descuento confidencial 40%']);

    $role = Role::factory()->scope(DataScope::Departments)
        ->withPermissions([Permission::UseQuickReplies, Permission::ManageQuickReplies])->create();
    $manager = User::factory()->withRole($role)->inDepartments($support)->create();

    $this->actingAs($manager)->post(route('quick-replies.store'), [
        'title' => 'Mi promo',
        'shortcut' => 'promo',
        'body' => 'Hola',
        'is_global' => false,
        'department_ids' => [$support->id],
    ])->assertSessionHasErrors('shortcut');

    $message = session('errors')->first('shortcut');

    // The reply shares Support with the manager, so it is already listed for them;
    // the department they do not belong to is never disclosed.
    expect($message)->toContain('Descuento confidencial')
        ->and($message)->not->toContain('Ventas Secretas');
});

test('a global shortcut conflict names the reply only because managers already see global replies', function () {
    $support = Department::factory()->create();
    QuickReply::factory()->inactive()->create(['shortcut' => 'vip', 'title' => 'Lista VIP interna', 'is_global' => true]);

    $role = Role::factory()->scope(DataScope::Departments)
        ->withPermissions([Permission::ManageQuickReplies])->create();
    $manager = User::factory()->withRole($role)->inDepartments($support)->create();

    $this->actingAs($manager)->post(route('quick-replies.store'), [
        'title' => 'Mi vip',
        'shortcut' => 'vip',
        'body' => 'Hola',
        'is_global' => false,
        'department_ids' => [$support->id],
    ])->assertSessionHasErrors('shortcut');

    // Managers list every global reply (active or not), so naming it discloses nothing new.
    expect(session('errors')->first('shortcut'))->toContain('Lista VIP interna');
});

test('contacts index does not expose the tag catalog to users without tags.view', function () {
    Tag::factory()->create(['name' => 'Moroso']);
    $role = Role::factory()->scope(DataScope::Global)->withPermissions([Permission::ViewContacts])->create();
    $user = User::factory()->withRole($role)->create();

    $this->actingAs($user)
        ->get(route('contacts.index'))
        ->assertInertia(fn (Assert $page) => $page->where('tags', null));
});

test('the dashboard does not report messaging as connected for an active connection with invalid credentials', function () {
    Connection::factory()->withInvalidCredentials()->create(['is_active' => true]);
    $admin = User::factory()->administrator()->create();

    $this->actingAs($admin)
        ->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page->loadDeferredProps(fn (Assert $reload) => $reload
            ->where('overview.messaging_connected', false)));
});

test('index pages run a constant number of queries regardless of rows', function (string $routeName, Closure $seed) {
    $admin = User::factory()->administrator()->create();
    $admin->load(['roles.permissions', 'departments']);

    $count = function () use ($admin, $routeName): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->actingAs($admin)->get(route($routeName))->assertOk();
        $n = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $n;
    };

    $count();
    $seed(2);
    $few = $count();
    $seed(10);
    $many = $count();

    expect($many)->toBe($few);
})->with([
    'contacts' => ['contacts.index', function (int $n) {
        $tag = Tag::factory()->create();
        Contact::factory()->count($n)->create()->each(fn (Contact $c) => $c->tags()->attach($tag));
    }],
    'quick replies' => ['quick-replies.index', function (int $n) {
        $department = Department::factory()->create();
        QuickReply::factory()->count($n)->forDepartments($department)->create();
    }],
    'connections' => ['connections.index', function (int $n) {
        $department = Department::factory()->create();
        Connection::factory()->count($n)->create()->each(fn (Connection $c) => $c->departments()->attach($department));
    }],
    'tags' => ['tags.index', fn (int $n) => Tag::factory()->count($n)->create()],
]);

test('a departments-scoped user cannot read another department contact through the available quick replies endpoint', function () {
    [$sales, $support] = Department::factory()->count(2)->create();
    $hidden = Contact::factory()->create(['name' => 'Cliente Oculto']);
    Conversation::factory()->inDepartment($sales)->create(['contact_id' => $hidden->id]);
    QuickReply::factory()->create(['shortcut' => 'hola', 'body' => 'Hola {contacto.nombre}', 'is_global' => true]);
    $agent = User::factory()->agent()->inDepartments($support)->create();

    $this->actingAs($agent)
        ->getJson(route('quick-replies.available', ['contact_id' => $hidden->id, 'department_id' => $sales->id]))
        ->assertOk()
        ->assertJsonMissing(['text' => 'Hola Cliente Oculto'])
        ->assertJsonPath('data.0.missing', ['contacto.nombre']);
});

test('contact tag sync on a hidden contact answers not found', function () {
    [$sales, $support] = Department::factory()->count(2)->create();
    $hidden = Contact::factory()->create();
    Conversation::factory()->inDepartment($sales)->create(['contact_id' => $hidden->id]);
    $tag = Tag::factory()->create();
    $role = Role::factory()->scope(DataScope::Departments)->withPermissions([Permission::ViewContacts, Permission::AssignTags])->create();
    $user = User::factory()->withRole($role)->inDepartments($support)->create();

    $this->actingAs($user)->put(route('contacts.tags.sync', $hidden), ['tag_ids' => [$tag->id]])->assertNotFound();
    expect($hidden->tags()->count())->toBe(0);
});

test('a departments-scoped user cannot change hours or welcome message of another department', function () {
    [$sales, $support] = Department::factory()->count(2)->create();
    $role = Role::factory()->scope(DataScope::Departments)->withPermissions([Permission::ViewDepartments, Permission::UpdateDepartments])->create();
    $user = User::factory()->withRole($role)->inDepartments($support)->create();

    $this->actingAs($user)->put(route('departments.operations.update', $sales), ['welcome_message' => 'x'])->assertForbidden();
    $this->actingAs($user)->put(route('departments.hours.update', $sales), ['uses_custom_hours' => false])->assertForbidden();
});

test('deactivated users cannot reach the new json endpoint', function () {
    $user = User::factory()->administrator()->create(['is_active' => false]);

    $response = $this->actingAs($user)->getJson(route('quick-replies.available'));

    expect($response->status())->toBeIn([401, 403, 302]);
});

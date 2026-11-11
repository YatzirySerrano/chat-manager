<?php

use App\Enums\AuditAction;
use App\Enums\DataScope;
use App\Enums\DefaultRole;
use App\Enums\Permission;
use App\Models\AuditLog;
use App\Models\Conversation;
use App\Models\ConversationCategory;
use App\Models\Role;
use App\Models\User;
use App\Services\ServiceRules;
use Inertia\Testing\AssertableInertia as Assert;

test('the catalog is reserved to people who manage categories', function (DefaultRole $role) {
    $user = User::factory()->withRole($role)->create();
    $category = ConversationCategory::factory()->create();

    $this->actingAs($user)->get(route('categories.index'))->assertForbidden();
    $this->actingAs($user)->post(route('categories.store'), ['name' => 'Garantías', 'color' => 'blue'])->assertForbidden();
    $this->actingAs($user)->put(route('categories.update', $category), ['name' => 'Garantías', 'color' => 'blue'])->assertForbidden();
    $this->actingAs($user)->put(route('categories.rules.update', $category), ['rules' => ['queue' => ['min_wait_minutes' => 5]]])->assertForbidden();
    $this->actingAs($user)->delete(route('categories.destroy', $category))->assertForbidden();
    $this->actingAs($user)->patch(route('categories.restore', $category))->assertForbidden();

    expect($category->refresh()->is_active)->toBeTrue()
        ->and($category->service_rules)->toBeNull()
        ->and(ConversationCategory::query()->count())->toBe(1);
})->with([
    'supervisor' => DefaultRole::Supervisor,
    'agent' => DefaultRole::Agent,
]);

test('managing categories is reserved to global roles even when the permission is granted', function () {
    $role = Role::factory()->scope(DataScope::Departments)->withPermissions([Permission::ManageCategories])->create();
    $manager = User::factory()->withRole($role)->create();

    $this->actingAs($manager)->get(route('categories.index'))->assertForbidden();
    $this->actingAs($manager)->post(route('categories.store'), ['name' => 'Garantías', 'color' => 'blue'])->assertForbidden();
});

test('guests are sent to the login', function () {
    $this->get(route('categories.index'))->assertRedirect(route('login'));
});

test('administrators list the catalog with its usage and the rules of each category', function () {
    $admin = User::factory()->administrator()->create();
    $used = ConversationCategory::factory()->withServiceRules(['queue.min_wait_minutes' => 5])->create(['name' => 'Garantías']);
    ConversationCategory::factory()->create(['name' => 'Cotizaciones', 'description' => 'Precios y presupuestos']);
    ConversationCategory::factory()->inactive()->create(['name' => 'Antigua']);
    Conversation::factory()->count(2)->create(['category_id' => $used->id]);

    $this->actingAs($admin)
        ->get(route('categories.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->component('categories/Index')
            ->where('can.manage', true)
            ->has('categories.data', 2)
            ->where('categories.data.0.name', 'Cotizaciones')
            ->where('categories.data.0.overrides_count', 0)
            ->where('categories.data.1.name', 'Garantías')
            ->where('categories.data.1.conversations_count', 2)
            ->where('categories.data.1.overrides_count', 1)
            ->where('categories.data.1.rules', function ($rules): bool {
                $rules = collect($rules)->toArray();

                return $rules['queue.min_wait_minutes']['source'] === 'category'
                    && $rules['queue.min_wait_minutes']['value'] === 5
                    && $rules['queue.chronological_order']['overridable'] === false;
            })
        );

    $this->actingAs($admin)
        ->get(route('categories.index', ['search' => 'presupuestos']))
        ->assertInertia(fn (Assert $page) => $page
            ->has('categories.data', 1)
            ->where('categories.data.0.name', 'Cotizaciones')
        );
});

test('deleted categories are listed only for people who can consult deleted records', function () {
    $root = User::factory()->superAdministrator()->create();
    $role = Role::factory()->scope(DataScope::Global)->withPermissions([Permission::ManageCategories])->create();
    $manager = User::factory()->withRole($role)->create();
    ConversationCategory::factory()->create(['name' => 'Vigente']);
    ConversationCategory::factory()->inactive()->create(['name' => 'Retirada']);

    $this->actingAs($root)
        ->get(route('categories.index', ['record' => 'deleted']))
        ->assertInertia(fn (Assert $page) => $page
            ->has('categories.data', 1)
            ->where('categories.data.0.name', 'Retirada')
            ->where('categories.data.0.is_deleted', true)
            ->where('categories.data.0.can.restore', true)
            ->where('categories.data.0.can.update', false)
        );

    $this->actingAs($manager)
        ->get(route('categories.index', ['record' => 'deleted']))
        ->assertInertia(fn (Assert $page) => $page
            ->has('categories.data', 1)
            ->where('categories.data.0.name', 'Vigente')
            ->where('filters.record', 'active')
        );
});

test('a category is created, edited and both changes are audited', function () {
    $admin = User::factory()->superAdministrator()->create();

    $this->actingAs($admin)
        ->post(route('categories.store'), ['name' => '  Soporte   técnico ', 'description' => ' Fallas del producto ', 'color' => 'emerald'])
        ->assertSessionHasNoErrors()
        ->assertRedirect();

    $category = ConversationCategory::query()->sole();

    expect($category->name)->toBe('Soporte técnico')
        ->and($category->description)->toBe('Fallas del producto')
        ->and($category->color->value)->toBe('emerald')
        ->and($category->is_active)->toBeTrue()
        ->and($category->service_rules)->toBeNull()
        ->and(AuditLog::query()->where('action', AuditAction::ConversationCategoryCreated)->sole()->auditable_id)->toBe($category->id);

    $this->actingAs($admin)
        ->put(route('categories.update', $category), ['name' => 'Soporte', 'description' => '', 'color' => 'emerald'])
        ->assertSessionHasNoErrors();

    expect($category->refresh()->name)->toBe('Soporte')
        ->and($category->description)->toBeNull()
        ->and(AuditLog::query()->where('action', AuditAction::ConversationCategoryUpdated)->sole()->properties['changes'])->toBe([
            'name' => ['old' => 'Soporte técnico', 'new' => 'Soporte'],
            'description' => ['old' => 'Fallas del producto', 'new' => null],
        ]);

    $this->actingAs($admin)
        ->put(route('categories.update', $category), ['name' => 'Soporte', 'color' => 'emerald'])
        ->assertSessionHasNoErrors();

    expect(AuditLog::query()->where('action', AuditAction::ConversationCategoryUpdated)->count())->toBe(1);
});

test('category data is validated and names are unique without regard to case', function () {
    $admin = User::factory()->superAdministrator()->create();
    $existing = ConversationCategory::factory()->create(['name' => 'Garantías']);
    $other = ConversationCategory::factory()->create(['name' => 'Ventas']);

    $this->actingAs($admin)
        ->post(route('categories.store'), ['name' => '', 'color' => 'no-es-color', 'description' => str_repeat('a', 256)])
        ->assertSessionHasErrors(['name', 'color', 'description']);

    $this->actingAs($admin)
        ->post(route('categories.store'), ['name' => str_repeat('a', 121), 'color' => 'blue'])
        ->assertSessionHasErrors('name');

    $this->actingAs($admin)
        ->post(route('categories.store'), ['name' => 'GARANTÍAS', 'color' => 'blue'])
        ->assertSessionHasErrors(['name' => 'Ya existe una categoría con ese nombre (no se distinguen mayúsculas de minúsculas).']);

    $this->actingAs($admin)
        ->put(route('categories.update', $other), ['name' => 'garantías', 'color' => 'blue'])
        ->assertSessionHasErrors('name');

    // Keeping its own name is not a duplicate.
    $this->actingAs($admin)
        ->put(route('categories.update', $existing), ['name' => 'Garantías', 'color' => 'rose'])
        ->assertSessionHasNoErrors();

    expect(ConversationCategory::query()->count())->toBe(2);
});

test('deleting is logical: the category leaves the options, stays on its conversations and can be restored', function () {
    $admin = User::factory()->superAdministrator()->create();
    $category = ConversationCategory::factory()->withServiceRules(['queue.min_wait_minutes' => 5])->create(['name' => 'Garantías', 'color' => 'blue']);
    $kept = ConversationCategory::factory()->create(['name' => 'Ventas', 'color' => 'emerald']);
    $conversation = Conversation::factory()->create(['category_id' => $category->id]);

    expect(ConversationCategory::options())->toBe([
        ['id' => $category->id, 'name' => 'Garantías', 'color' => 'blue'],
        ['id' => $kept->id, 'name' => 'Ventas', 'color' => 'emerald'],
    ]);

    $this->actingAs($admin)->delete(route('categories.destroy', $category))->assertSessionHasNoErrors()->assertRedirect();

    expect($category->refresh()->isDeleted())->toBeTrue()
        ->and($conversation->refresh()->category_id)->toBe($category->id)
        ->and(ConversationCategory::options())->toBe([['id' => $kept->id, 'name' => 'Ventas', 'color' => 'emerald']])
        ->and(app(ServiceRules::class)->forConversation($conversation)->minWaitSeconds())->toBe(0)
        ->and(AuditLog::query()->where('action', AuditAction::ConversationCategoryDeleted)->sole()->properties['conversations_kept'])->toBe(1);

    // Read-only while deleted.
    $this->actingAs($admin)->put(route('categories.update', $category), ['name' => 'Otra', 'color' => 'blue'])->assertForbidden();
    $this->actingAs($admin)->put(route('categories.rules.update', $category), ['rules' => []])->assertForbidden();
    $this->actingAs($admin)->delete(route('categories.destroy', $category))->assertForbidden();

    $this->actingAs($admin)->patch(route('categories.restore', $category))->assertSessionHasNoErrors();

    expect($category->refresh()->isDeleted())->toBeFalse()
        ->and(app(ServiceRules::class)->forConversation($conversation)->minWaitSeconds())->toBe(300)
        ->and(AuditLog::query()->where('action', AuditAction::ConversationCategoryRestored)->sole()->auditable_id)->toBe($category->id);

    $this->actingAs($admin)->patch(route('categories.restore', $category))->assertForbidden();
});

test('restoring requires consulting deleted records', function () {
    $role = Role::factory()->scope(DataScope::Global)->withPermissions([Permission::ManageCategories])->create();
    $manager = User::factory()->withRole($role)->create();
    $category = ConversationCategory::factory()->inactive()->create();

    $this->actingAs($manager)->patch(route('categories.restore', $category))->assertForbidden();

    expect($category->refresh()->isDeleted())->toBeTrue();
});

test('a category saves the rules it overrides, audited per rule, and inherits the rest', function () {
    $admin = User::factory()->superAdministrator()->create();
    $category = ConversationCategory::factory()->withServiceRules(['sla.response_minutes' => 20])->create();

    $this->actingAs($admin)
        ->put(route('categories.rules.update', $category), ['rules' => [
            'sla' => ['first_response_minutes' => 5, 'response_minutes' => null],
            'intake' => ['confirmation_required' => true],
        ]])
        ->assertSessionHasNoErrors()
        ->assertRedirect();

    expect($category->refresh()->service_rules)->toBe([
        'sla.first_response_minutes' => 5,
        'intake.confirmation_required' => true,
    ])
        ->and(AuditLog::query()->where('action', AuditAction::ConversationCategoryRulesUpdated)->sole()->properties['changes'])->toBe([
            'sla.first_response_minutes' => ['old' => null, 'new' => 5],
            'sla.response_minutes' => ['old' => 20, 'new' => null],
            'intake.confirmation_required' => ['old' => null, 'new' => true],
        ]);
});

test('a category cannot set the rules reserved to the department or the platform', function (array $rules, string $error) {
    $admin = User::factory()->superAdministrator()->create();
    $category = ConversationCategory::factory()->create();

    $this->actingAs($admin)
        ->put(route('categories.rules.update', $category), ['rules' => $rules])
        ->assertSessionHasErrors($error);

    expect($category->refresh()->service_rules)->toBeNull();
})->with([
    'business hours only' => [['sla' => ['business_hours_only' => false]], 'rules.sla'],
    'chronological order' => [['queue' => ['chronological_order' => true]], 'rules.queue'],
    'alerts' => [['sla_alerts' => ['reminder_limit' => 2]], 'rules'],
    'out of range' => [['sla' => ['resolution_minutes' => 525601]], 'rules.sla.resolution_minutes'],
]);

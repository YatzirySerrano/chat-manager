<?php

use App\Enums\AuditAction;
use App\Enums\DataScope;
use App\Enums\Permission;
use App\Models\AuditLog;
use App\Models\Department;
use App\Models\QuickReply;
use App\Models\Role;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * A departments-scoped user that can manage quick replies.
 */
function departmentReplyManager(Department ...$departments): User
{
    $role = Role::factory()
        ->scope(DataScope::Departments)
        ->withPermissions([Permission::UseQuickReplies, Permission::ManageQuickReplies])
        ->create();

    return User::factory()->withRole($role)->inDepartments($departments)->create();
}

/**
 * @return array<string, mixed>
 */
function quickReplyInput(array $overrides = []): array
{
    return array_merge([
        'title' => 'Saludo inicial',
        'shortcut' => 'saludo',
        'body' => 'Hola {contacto.nombre}, soy {agente.nombre}.',
        'is_global' => true,
        'department_ids' => [],
    ], $overrides);
}

test('the list requires permission to use or manage quick replies', function () {
    $role = Role::factory()->scope(DataScope::Departments)->withPermissions([Permission::ViewDashboard])->create();

    $this->actingAs(User::factory()->withRole($role)->create())
        ->get(route('quick-replies.index'))
        ->assertForbidden();
});

test('users that can only use replies see the active ones available to them', function () {
    [$sales, $support] = Department::factory()->count(2)->create();
    $agent = User::factory()->agent()->inDepartments($support)->create();
    $global = QuickReply::factory()->create(['shortcut' => 'a-global']);
    $shared = QuickReply::factory()->forDepartments($support)->create(['shortcut' => 'b-support']);
    QuickReply::factory()->forDepartments($sales)->create();
    QuickReply::factory()->inactive()->create();

    $this->actingAs($agent)
        ->get(route('quick-replies.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->component('quick-replies/Index')
            ->has('quickReplies.data', 2)
            ->where('quickReplies.data.0.id', $global->id)
            ->where('quickReplies.data.1.id', $shared->id)
            ->where('quickReplies.data.1.can.update', false)
            ->where('can.create', false)
            ->where('can.use', true)
        );
});

test('the list can be searched by shortcut, title or text and filtered', function () {
    $admin = User::factory()->superAdministrator()->create();
    $sales = Department::factory()->create();
    QuickReply::factory()->create(['shortcut' => 'horario', 'title' => 'Horario', 'body' => 'Abrimos de 9 a 18.']);
    $envio = QuickReply::factory()->forDepartments($sales)->create(['shortcut' => 'envio', 'title' => 'Costo de envío', 'body' => 'El envío es gratis.']);
    QuickReply::factory()->inactive()->create(['shortcut' => 'envio-viejo', 'title' => 'Envío anterior', 'body' => 'Ya no aplica.']);

    $this->actingAs($admin)
        ->get(route('quick-replies.index', ['search' => '/envio']))
        ->assertInertia(fn (Assert $page) => $page->has('quickReplies.data', 1)->where('quickReplies.data.0.id', $envio->id));

    $this->actingAs($admin)
        ->get(route('quick-replies.index', ['search' => '/envio', 'record' => 'all']))
        ->assertInertia(fn (Assert $page) => $page->has('quickReplies.data', 2));

    $this->actingAs($admin)
        ->get(route('quick-replies.index', ['search' => 'gratis']))
        ->assertInertia(fn (Assert $page) => $page->has('quickReplies.data', 1)->where('quickReplies.data.0.id', $envio->id));

    $this->actingAs($admin)
        ->get(route('quick-replies.index', ['department' => $sales->id, 'record' => 'active', 'availability' => 'departments']))
        ->assertInertia(fn (Assert $page) => $page->has('quickReplies.data', 1)->where('quickReplies.data.0.id', $envio->id));

    $this->actingAs($admin)
        ->get(route('quick-replies.index', ['record' => 'deleted']))
        ->assertInertia(fn (Assert $page) => $page
            ->has('quickReplies.data', 1)
            ->where('quickReplies.data.0.shortcut', 'envio-viejo')
            ->where('quickReplies.data.0.can.restore', true)
            ->where('quickReplies.data.0.can.update', false));
});

test('administrators can create a global reply and it is audited', function () {
    $admin = User::factory()->superAdministrator()->create();

    $this->actingAs($admin)
        ->post(route('quick-replies.store'), quickReplyInput(['shortcut' => '/Saludo']))
        ->assertSessionHasNoErrors();

    $reply = QuickReply::query()->sole();

    expect($reply)
        ->shortcut->toBe('saludo')
        ->is_global->toBeTrue()
        ->is_active->toBeTrue()
        ->created_by_user_id->toBe($admin->id);

    expect(AuditLog::query()->where('action', AuditAction::QuickReplyCreated)->sole()->properties)
        ->toBe(['shortcut' => 'saludo', 'is_global' => true, 'departments' => []]);
});

test('replies restricted to departments store them', function () {
    $admin = User::factory()->superAdministrator()->create();
    [$sales, $support] = Department::factory()->count(2)->create();

    $this->actingAs($admin)
        ->post(route('quick-replies.store'), quickReplyInput(['is_global' => false, 'department_ids' => [$sales->id, $support->id]]))
        ->assertSessionHasNoErrors();

    expect(QuickReply::query()->sole()->departments()->pluck('departments.id')->all())
        ->toEqualCanonicalizing([$sales->id, $support->id]);
});

test('fields are validated with clear messages', function () {
    $admin = User::factory()->superAdministrator()->create();

    $this->actingAs($admin)
        ->post(route('quick-replies.store'), [
            'title' => '',
            'shortcut' => 'hola mundo',
            'body' => str_repeat('a', 4097),
            'is_global' => false,
            'department_ids' => [],
        ])
        ->assertSessionHasErrors([
            'title' => 'El campo título es obligatorio.',
            'shortcut' => 'El atajo solo puede tener letras minúsculas sin acentos, números, guion (-) y guion bajo (_), y debe empezar con una letra o un número.',
            'body' => 'El mensaje no debe tener más de 4096 caracteres (límite de WhatsApp para un mensaje de texto).',
            'department_ids' => 'Elige al menos un departamento o márcala como disponible para todos.',
        ]);
});

test('the body only accepts catalog variables', function (string $body, string $message) {
    $admin = User::factory()->superAdministrator()->create();

    $this->actingAs($admin)
        ->post(route('quick-replies.store'), quickReplyInput(['body' => $body]))
        ->assertSessionHasErrors(['body' => $message]);

    expect(QuickReply::query()->exists())->toBeFalse();
})->with([
    'unknown variable' => [
        'Hola {cliente.nombre}',
        'La variable {cliente.nombre} no existe. Solo puedes usar: {contacto.nombre}, {agente.nombre}, {departamento.nombre}, {empresa.nombre}.',
    ],
    'expression' => [
        'Total: {1+1}',
        'La variable {1+1} no existe. Solo puedes usar: {contacto.nombre}, {agente.nombre}, {departamento.nombre}, {empresa.nombre}.',
    ],
    'stray braces' => [
        'Hola {{contacto.nombre}}',
        'Las llaves { } solo pueden usarse para escribir variables completas. Variables disponibles: {contacto.nombre}, {agente.nombre}, {departamento.nombre}, {empresa.nombre}.',
    ],
    'html tag' => [
        'Hola <b>cliente</b>',
        'El mensaje no puede contener etiquetas HTML (texto como <b> o </p>). WhatsApp envía solo texto; usa *negritas*, _cursivas_ o ~tachado~ para dar formato.',
    ],
    'script' => [
        '<script>alert(1)</script>',
        'El mensaje no puede contener etiquetas HTML (texto como <b> o </p>). WhatsApp envía solo texto; usa *negritas*, _cursivas_ o ~tachado~ para dar formato.',
    ],
]);

test('comparison signs that are not tags are allowed in the body', function () {
    $admin = User::factory()->superAdministrator()->create();

    $this->actingAs($admin)
        ->post(route('quick-replies.store'), quickReplyInput(['body' => 'Pedidos < 500 pesos pagan envío; > 500 es gratis.']))
        ->assertSessionHasNoErrors();
});

test('shortcuts must be unique within their scope', function (bool $existingGlobal, array $existingDepartments, bool $newGlobal, array $newDepartments, bool $conflicts) {
    $admin = User::factory()->superAdministrator()->create();
    $departments = [
        'sales' => Department::factory()->create(['name' => 'Ventas']),
        'support' => Department::factory()->create(['name' => 'Soporte']),
    ];
    $ids = fn (array $keys): array => array_map(fn (string $key): int => $departments[$key]->id, $keys);

    $existing = QuickReply::factory()->create(['shortcut' => 'hola', 'title' => 'Hola existente', 'is_global' => $existingGlobal]);
    $existing->departments()->attach($ids($existingDepartments));

    $response = $this->actingAs($admin)->post(route('quick-replies.store'), quickReplyInput([
        'shortcut' => 'hola',
        'is_global' => $newGlobal,
        'department_ids' => $ids($newDepartments),
    ]));

    if ($conflicts) {
        $response->assertSessionHasErrors('shortcut');
        expect(QuickReply::query()->count())->toBe(1);
    } else {
        $response->assertSessionHasNoErrors();
        expect(QuickReply::query()->count())->toBe(2);
    }
})->with([
    'global vs global' => [true, [], true, [], true],
    'global vs department' => [true, [], false, ['sales'], true],
    'department vs global' => [false, ['sales'], true, [], true],
    'shared department' => [false, ['sales', 'support'], false, ['support'], true],
    'different departments' => [false, ['sales'], false, ['support'], false],
]);

test('the shortcut conflict message explains where it is used', function () {
    $admin = User::factory()->superAdministrator()->create();
    $sales = Department::factory()->create(['name' => 'Ventas']);
    QuickReply::factory()->forDepartments($sales)->create(['shortcut' => 'hola', 'title' => 'Saludo de ventas']);

    $this->actingAs($admin)
        ->post(route('quick-replies.store'), quickReplyInput(['shortcut' => 'hola', 'is_global' => false, 'department_ids' => [$sales->id]]))
        ->assertSessionHasErrors(['shortcut' => 'El atajo /hola ya se usa en «Saludo de ventas» en Ventas.']);
});

test('inactive replies keep their shortcut reserved', function () {
    $admin = User::factory()->superAdministrator()->create();
    QuickReply::factory()->inactive()->create(['shortcut' => 'hola']);

    $this->actingAs($admin)
        ->post(route('quick-replies.store'), quickReplyInput(['shortcut' => 'hola']))
        ->assertSessionHasErrors('shortcut');
});

test('updating a reply keeps its own shortcut and audits the changes', function () {
    $admin = User::factory()->superAdministrator()->create();
    [$sales, $support] = Department::factory()->count(2)->create();
    $reply = QuickReply::factory()->forDepartments($sales)->create(['shortcut' => 'hola', 'title' => 'Antes']);

    $this->actingAs($admin)
        ->put(route('quick-replies.update', $reply), quickReplyInput([
            'title' => 'Después',
            'shortcut' => 'hola',
            'body' => 'Nuevo texto',
            'is_global' => false,
            'department_ids' => [$support->id],
        ]))
        ->assertSessionHasNoErrors();

    expect($reply->refresh())
        ->title->toBe('Después')
        ->updated_by_user_id->toBe($admin->id)
        ->and($reply->departments()->pluck('departments.id')->all())->toBe([$support->id]);

    $changes = AuditLog::query()->where('action', AuditAction::QuickReplyUpdated)->sole()->properties['changes'];

    expect($changes['title'])->toBe(['old' => 'Antes', 'new' => 'Después'])
        ->and($changes['body'])->toBe(['changed' => true])
        ->and($changes['departments'])->toBe(['old' => [$sales->name], 'new' => [$support->name]]);
});

test('department managers create replies only for their own departments', function () {
    [$own, $other] = Department::factory()->count(2)->create();
    $manager = departmentReplyManager($own);

    $this->actingAs($manager)
        ->post(route('quick-replies.store'), quickReplyInput(['is_global' => true]))
        ->assertSessionHasErrors(['is_global' => 'Solo quienes ven toda la organización pueden crear respuestas para todos los departamentos. Elige tus departamentos.']);

    $this->actingAs($manager)
        ->post(route('quick-replies.store'), quickReplyInput(['is_global' => false, 'department_ids' => [$own->id, $other->id]]))
        ->assertSessionHasErrors(['department_ids' => 'Solo puedes elegir departamentos a los que perteneces.']);

    expect(QuickReply::query()->exists())->toBeFalse();

    $this->actingAs($manager)
        ->post(route('quick-replies.store'), quickReplyInput(['is_global' => false, 'department_ids' => [$own->id]]))
        ->assertSessionHasNoErrors();

    expect(QuickReply::query()->sole()->departments()->pluck('departments.id')->all())->toBe([$own->id]);
});

test('department managers cannot change global replies or replies of other departments', function () {
    [$own, $other] = Department::factory()->count(2)->create();
    $manager = departmentReplyManager($own);
    $global = QuickReply::factory()->create();
    $mixed = QuickReply::factory()->forDepartments($own, $other)->create();
    $foreign = QuickReply::factory()->forDepartments($other)->create();
    $mine = QuickReply::factory()->forDepartments($own)->create();

    // Replies the manager can use are refused; one shared only with another
    // department answers like an id that does not exist.
    foreach ([[$global, 403], [$mixed, 403], [$foreign, 404]] as [$reply, $status]) {
        $this->actingAs($manager)
            ->put(route('quick-replies.update', $reply), quickReplyInput(['shortcut' => $reply->shortcut, 'is_global' => false, 'department_ids' => [$own->id]]))
            ->assertStatus($status);
        $this->actingAs($manager)->delete(route('quick-replies.destroy', $reply))->assertStatus($status);
    }

    $this->actingAs($manager)
        ->get(route('quick-replies.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->has('quickReplies.data', 3)
            ->where('can.manageGlobal', false)
            ->has('assignableDepartments', 1)
            ->where('assignableDepartments.0.id', $own->id)
        );

    $this->actingAs($manager)->delete(route('quick-replies.destroy', $mine))->assertSessionHasNoErrors();

    expect($mine->refresh()->is_active)->toBeFalse();

    // Without records.view-deleted the deleted reply answers like an id that does not exist.
    $this->actingAs($manager)->patch(route('quick-replies.restore', $mine))->assertNotFound();
});

test('a deleted reply cannot be used, keeps its shortcut reserved and can be restored', function () {
    $admin = User::factory()->superAdministrator()->create();
    $sales = Department::factory()->create();
    $agent = User::factory()->agent()->inDepartments($sales)->create();
    $reply = QuickReply::factory()->forDepartments($sales)->create(['shortcut' => 'adios', 'body' => 'Hasta luego']);

    $this->actingAs($admin)->delete(route('quick-replies.destroy', $reply))->assertSessionHasNoErrors();

    $this->assertModelExists($reply);
    expect($reply->refresh()->isDeleted())->toBeTrue()
        ->and($this->actingAs($agent)->getJson(route('quick-replies.available'))->json('data'))->toBe([]);

    $this->actingAs($admin)
        ->post(route('quick-replies.store'), quickReplyInput(['shortcut' => 'adios', 'is_global' => false, 'department_ids' => [$sales->id]]))
        ->assertSessionHasErrors('shortcut');

    $this->actingAs($admin)->put(route('quick-replies.update', $reply), quickReplyInput(['shortcut' => 'adios']))->assertForbidden();
    $this->actingAs($admin)->patch(route('quick-replies.restore', $reply))->assertSessionHasNoErrors();

    expect($reply->refresh()->is_active)->toBeTrue()
        ->and(AuditLog::query()->where('action', AuditAction::QuickReplyDeleted)->count())->toBe(1)
        ->and(AuditLog::query()->where('action', AuditAction::QuickReplyRestored)->count())->toBe(1);
});

test('users that can only use replies cannot manage them', function () {
    $agent = User::factory()->agent()->create();
    $reply = QuickReply::factory()->create();

    $this->actingAs($agent)->post(route('quick-replies.store'), quickReplyInput())->assertForbidden();
    $this->actingAs($agent)->delete(route('quick-replies.destroy', $reply))->assertForbidden();
});

test('saving a reply without effective changes writes nothing and says so', function () {
    $admin = User::factory()->superAdministrator()->create();
    [$sales, $support] = Department::factory()->count(2)->create();
    $reply = QuickReply::factory()->forDepartments($sales, $support)->create([
        'title' => 'Saludo inicial',
        'shortcut' => 'saludo',
        'body' => "Hola,\nbienvenido.",
    ]);
    $updatedAt = $reply->updated_at;
    $this->travel(1)->hours();

    $writes = databaseWritesDuring(fn () => $this->actingAs($admin)
        ->put(route('quick-replies.update', $reply), quickReplyInput([
            'title' => ' Saludo   inicial ',
            'shortcut' => '/Saludo',
            'body' => "Hola,\r\nbienvenido.  ",
            'is_global' => false,
            'department_ids' => [$support->id, $sales->id],
        ]))
        ->assertSessionHasNoErrors()
        ->assertInertiaFlash('toast', ['type' => 'info', 'message' => 'No había cambios por guardar.']));

    expect($writes)->toBe([])
        ->and($reply->refresh()->updated_at->equalTo($updatedAt))->toBeTrue()
        ->and(AuditLog::query()->where('action', AuditAction::QuickReplyUpdated)->exists())->toBeFalse();
});

test('a real change to a reply is confirmed with a success toast', function () {
    $admin = User::factory()->superAdministrator()->create();
    $reply = QuickReply::factory()->create(['shortcut' => 'saludo', 'title' => 'Antes']);

    $this->actingAs($admin)
        ->put(route('quick-replies.update', $reply), quickReplyInput(['title' => 'Después', 'body' => $reply->body]))
        ->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'Respuesta rápida actualizada.']);
});

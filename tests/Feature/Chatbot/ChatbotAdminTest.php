<?php

use App\Enums\AuditAction;
use App\Enums\DefaultRole;
use App\Enums\Permission;
use App\Models\AuditLog;
use App\Models\ChatbotKeywordRule;
use App\Models\ChatbotMenu;
use App\Models\Connection;
use App\Models\Department;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

/*
| «Chatbot» under Administración: who may open it, saving the welcome menu
| of a connection and the keyword rules, with validation and audit.
*/

beforeEach(function () {
    $this->admin = User::factory()->administrator()->create();
    $this->connection = Connection::factory()->create(['name' => 'Solicitudes MC']);
    $this->control = Department::factory()->create(['name' => 'Mesa de control']);
    $this->orizaba = Department::factory()->create(['name' => 'Orizaba']);
});

test('the roles that manage connections configure the chatbot; supervisors and agents do not', function () {
    expect(DefaultRole::Administrator->permissions())->toContain(Permission::ManageChatbot)
        ->and(DefaultRole::Supervisor->permissions())->not->toContain(Permission::ManageChatbot)
        ->and(DefaultRole::Agent->permissions())->not->toContain(Permission::ManageChatbot);

    $this->actingAs($this->admin)
        ->get(route('admin.chatbot.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('chatbot/Index')
            ->where('connections.0.name', 'Solicitudes MC')
            ->where('connections.0.menu.is_enabled', false)
            ->where('connections.0.can.update', true)
            ->where('can.manage', true)
            ->has('variables', 4)
            ->where('defaults.retry_message', 'Responde con el número de una opción.'));

    foreach ([User::factory()->supervisor()->create(), User::factory()->agent()->create()] as $user) {
        $this->actingAs($user)->get(route('admin.chatbot.index'))->assertForbidden();
        $this->actingAs($user)->post(route('admin.chatbot.rules.store'), [])->assertForbidden();
        $this->actingAs($user)->put(route('admin.chatbot.menus.update', $this->connection), [])->assertForbidden();
    }
});

test('the welcome menu is saved with its options in order and audited on the connection', function () {
    $this->actingAs($this->admin)
        ->put(route('admin.chatbot.menus.update', $this->connection), [
            'is_enabled' => true,
            'greeting' => "  ¡Hola {{userName}}!\r\nElige una opción:  ",
            'retry_message' => '',
            'max_attempts' => 3,
            'options' => [
                ['department_id' => $this->orizaba->id, 'label' => '', 'reply_message' => 'Te atiende Orizaba.'],
                ['department_id' => $this->control->id, 'label' => '🧑‍💻 Mesa', 'reply_message' => null],
            ],
        ])
        ->assertSessionHasNoErrors()
        ->assertRedirect();

    $menu = ChatbotMenu::query()->with('options')->sole();

    expect($menu)
        ->connection_id->toBe($this->connection->id)
        ->is_enabled->toBeTrue()
        ->greeting->toBe("¡Hola {{userName}}!\nElige una opción:")
        ->retry_message->toBeNull()
        ->max_attempts->toBe(3)
        ->updated_by_user_id->toBe($this->admin->id)
        ->and($menu->options->map->only(['department_id', 'label', 'reply_message', 'position'])->all())->toBe([
            ['department_id' => $this->orizaba->id, 'label' => null, 'reply_message' => 'Te atiende Orizaba.', 'position' => 0],
            ['department_id' => $this->control->id, 'label' => '🧑‍💻 Mesa', 'reply_message' => null, 'position' => 1],
        ]);

    $audit = AuditLog::query()->where('action', AuditAction::ChatbotMenuUpdated)->sole();

    expect($audit->auditable_id)->toBe($this->connection->id)
        ->and($audit->properties['changes']['options']['new'])->toBe(['1. Orizaba · con respuesta: Te atiende Orizaba.', '2. Mesa de control («🧑‍💻 Mesa»)']);

    // Saving the same data writes nothing.
    $this->actingAs($this->admin)
        ->put(route('admin.chatbot.menus.update', $this->connection), [
            'is_enabled' => true,
            'greeting' => "¡Hola {{userName}}!\nElige una opción:",
            'max_attempts' => 3,
            'options' => [
                ['department_id' => $this->orizaba->id, 'reply_message' => 'Te atiende Orizaba.'],
                ['department_id' => $this->control->id, 'label' => '🧑‍💻 Mesa'],
            ],
        ])
        ->assertSessionHasNoErrors();

    expect(AuditLog::query()->where('action', AuditAction::ChatbotMenuUpdated)->count())->toBe(1);
});

test('an active menu needs a greeting and an option; new options need an active department the connection may route to', function () {
    $other = Department::factory()->create();
    $this->connection->departments()->sync([$this->control->id, $this->orizaba->id]);
    $inactive = Department::factory()->inactive()->create();

    $this->actingAs($this->admin)
        ->put(route('admin.chatbot.menus.update', $this->connection), ['is_enabled' => true, 'greeting' => '', 'max_attempts' => 2, 'options' => []])
        ->assertSessionHasErrors(['greeting' => 'Escribe el saludo para activar el menú.']);

    $this->actingAs($this->admin)
        ->put(route('admin.chatbot.menus.update', $this->connection), ['is_enabled' => true, 'greeting' => 'Hola', 'max_attempts' => 2, 'options' => []])
        ->assertSessionHasErrors(['options' => 'Agrega al menos una opción para activar el menú.']);

    $this->actingAs($this->admin)
        ->put(route('admin.chatbot.menus.update', $this->connection), ['is_enabled' => true, 'greeting' => 'Hola', 'max_attempts' => 9, 'options' => [
            ['department_id' => $inactive->id],
            ['department_id' => $other->id],
            ['department_id' => $this->control->id],
            ['department_id' => $this->control->id],
        ]])
        ->assertSessionHasErrors([
            'max_attempts',
            'options.2.department_id' => 'Cada departamento puede aparecer una sola vez en el menú.',
        ]);

    $this->actingAs($this->admin)
        ->put(route('admin.chatbot.menus.update', $this->connection), ['is_enabled' => true, 'greeting' => 'Hola', 'max_attempts' => 2, 'options' => [
            ['department_id' => $inactive->id],
            ['department_id' => $other->id],
        ]])
        ->assertSessionHasErrors([
            'options.0.department_id' => 'Elige un departamento activo.',
            'options.1.department_id' => 'Este departamento no está autorizado para recibir conversaciones de esta conexión.',
        ]);

    expect(ChatbotMenu::query()->count())->toBe(0);
});

test('keyword rules are created, edited, turned off, reordered and deleted, each audited', function () {
    $this->actingAs($this->admin)
        ->post(route('admin.chatbot.rules.store'), [
            'name' => '  Horario   de atención ',
            'keywords' => 'horario, Horario,  a qué hora ,',
            'reply_message' => 'Nuestro horario es de 9:00 a 16:00 hrs.',
            'is_active' => true,
            'throttle_minutes' => 30,
            'connection_ids' => [$this->connection->id],
            'department_ids' => [],
        ])
        ->assertSessionHasNoErrors();

    $rule = ChatbotKeywordRule::query()->sole();

    expect($rule)
        ->name->toBe('Horario de atención')
        ->keywords->toBe(['horario', 'a qué hora'])
        ->created_by_user_id->toBe($this->admin->id)
        ->and($rule->connections()->pluck('connections.id')->all())->toBe([$this->connection->id])
        ->and(AuditLog::query()->where('action', AuditAction::ChatbotRuleCreated)->sole()->auditable_label)->toBe('Palabra clave «Horario de atención»');

    $this->actingAs($this->admin)
        ->put(route('admin.chatbot.rules.update', $rule), [
            'name' => 'Horario de atención',
            'keywords' => ['horario', 'a qué hora', 'abren'],
            'reply_message' => 'Nuestro horario es de 9:00 a 16:00 hrs.',
            'is_active' => true,
            'throttle_minutes' => 60,
            'connection_ids' => [],
            'department_ids' => [$this->control->id],
        ])
        ->assertSessionHasNoErrors();

    $second = ChatbotKeywordRule::query()->create(['name' => 'Precios', 'keywords' => ['precio'], 'reply_message' => 'Consulta la lista.', 'position' => 5]);

    $this->actingAs($this->admin)->patch(route('admin.chatbot.rules.active', $rule), ['is_active' => false])->assertSessionHasNoErrors();
    $this->actingAs($this->admin)->put(route('admin.chatbot.rules.order'), ['ids' => [$second->id, $rule->id]])->assertSessionHasNoErrors();

    expect($rule->refresh())
        ->keywords->toBe(['horario', 'a qué hora', 'abren'])
        ->throttle_minutes->toBe(60)
        ->is_active->toBeFalse()
        ->position->toBe(1)
        ->and($second->refresh()->position)->toBe(0)
        ->and($rule->connections()->count())->toBe(0)
        ->and($rule->departments()->pluck('departments.id')->all())->toBe([$this->control->id])
        ->and(AuditLog::query()->where('action', AuditAction::ChatbotRuleUpdated)->count())->toBe(3);

    $this->actingAs($this->admin)->delete(route('admin.chatbot.rules.destroy', $rule))->assertSessionHasNoErrors();

    expect(ChatbotKeywordRule::query()->pluck('id')->all())->toBe([$second->id])
        ->and(AuditLog::query()->where('action', AuditAction::ChatbotRuleDeleted)->sole()->properties['keywords'])->toBe(['horario', 'a qué hora', 'abren']);
});

test('a rule needs a name, a reply and at least one keyword', function () {
    $this->actingAs($this->admin)
        ->post(route('admin.chatbot.rules.store'), [
            'name' => '',
            'keywords' => ' , ;',
            'reply_message' => '',
            'throttle_minutes' => 0,
            'connection_ids' => [],
            'department_ids' => [],
        ])
        ->assertSessionHasErrors(['name', 'reply_message', 'throttle_minutes']);

    $this->actingAs($this->admin)
        ->post(route('admin.chatbot.rules.store'), [
            'name' => 'Vacía',
            'keywords' => ' , ;',
            'reply_message' => 'Hola',
            'throttle_minutes' => 30,
            'connection_ids' => [],
            'department_ids' => [],
        ])
        ->assertSessionHasErrors(['keywords' => 'Escribe al menos una palabra clave.']);

    expect(ChatbotKeywordRule::query()->count())->toBe(0);
});

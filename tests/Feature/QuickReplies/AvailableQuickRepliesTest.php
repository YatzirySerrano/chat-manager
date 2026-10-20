<?php

use App\Enums\DataScope;
use App\Enums\Permission;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Department;
use App\Models\QuickReply;
use App\Models\Role;
use App\Models\User;

test('using quick replies requires the use permission', function () {
    $role = Role::factory()->scope(DataScope::Global)->withPermissions([Permission::ManageQuickReplies])->create();

    $this->actingAs(User::factory()->withRole($role)->create())
        ->getJson(route('quick-replies.available'))
        ->assertForbidden();
});

test('only active replies that are global or shared with the user departments are offered', function () {
    [$sales, $support] = Department::factory()->count(2)->create();
    $agent = User::factory()->agent()->inDepartments($support)->create();
    $global = QuickReply::factory()->create(['shortcut' => 'global']);
    $shared = QuickReply::factory()->forDepartments($support)->create(['shortcut' => 'soporte']);
    QuickReply::factory()->forDepartments($sales)->create(['shortcut' => 'ventas']);
    QuickReply::factory()->inactive()->create(['shortcut' => 'inactiva']);
    QuickReply::factory()->inactive()->forDepartments($support)->create(['shortcut' => 'soporte-vieja']);

    $this->actingAs($agent)
        ->getJson(route('quick-replies.available'))
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0.id', $global->id)
        ->assertJsonPath('data.1.id', $shared->id);
});

test('replies are filtered by what was typed after the slash', function () {
    $admin = User::factory()->administrator()->create();
    QuickReply::factory()->create(['shortcut' => 'envio', 'title' => 'Costo de envío']);
    QuickReply::factory()->create(['shortcut' => 'horario', 'title' => 'Horario de envíos']);
    QuickReply::factory()->create(['shortcut' => 'saludo', 'title' => 'Saludo']);

    $this->actingAs($admin)
        ->getJson(route('quick-replies.available', ['q' => '/env']))
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0.shortcut', 'envio')
        ->assertJsonPath('data.1.shortcut', 'horario');
});

test('replies are rendered for a visible contact and the missing values are reported', function () {
    $department = Department::factory()->create(['name' => 'Soporte']);
    $agent = User::factory()->agent()->inDepartments($department)->create(['name' => 'Carlos']);
    $visible = Contact::factory()->create(['name' => 'Ana']);
    Conversation::factory()->inDepartment($department)->assignedTo($agent)->for($visible)->create();
    $hidden = Contact::factory()->create(['name' => 'Oculto']);
    QuickReply::factory()->create(['body' => 'Hola {contacto.nombre}, soy {agente.nombre} de {departamento.nombre}.']);

    $this->actingAs($agent)
        ->getJson(route('quick-replies.available', ['contact_id' => $visible->id, 'department_id' => $department->id]))
        ->assertJsonPath('data.0.text', 'Hola Ana, soy Carlos de Soporte.')
        ->assertJsonPath('data.0.missing', []);

    $this->actingAs($agent)
        ->getJson(route('quick-replies.available', ['contact_id' => $hidden->id, 'department_id' => Department::factory()->create()->id]))
        ->assertJsonPath('data.0.text', 'Hola, soy Carlos de.')
        ->assertJsonPath('data.0.missing', ['contacto.nombre', 'departamento.nombre']);
});

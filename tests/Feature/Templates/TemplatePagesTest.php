<?php

use App\Enums\DataScope;
use App\Enums\Permission;
use App\Models\Connection;
use App\Models\Department;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Templates\TemplateFixtures;

/*
| Templates administration screens and export.
*/

beforeEach(function () {
    $this->connection = Connection::factory()->create(['name' => 'Ventas']);
    $this->template = TemplateFixtures::stored($this->connection);
});

test('template viewers see the list with filters, status and preview', function () {
    TemplateFixtures::stored($this->connection, ['name' => 'promo_otono', 'status' => 'REJECTED', 'category' => 'MARKETING', 'rejected_reason' => 'PROMOTIONAL']);
    $viewer = conversationUser([Permission::ViewTemplates], DataScope::Global);

    $this->actingAs($viewer)
        ->get(route('templates.index', ['status' => 'REJECTED']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('templates/Index')
            ->has('templates.data', 1)
            ->where('templates.data.0.name', 'promo_otono')
            ->where('templates.data.0.status.label', 'Rechazada')
            ->where('templates.data.0.sendable', false)
            ->where('stats.total', 2)
            ->where('stats.approved', 1)
            ->where('connections.0.can_sync', false));
});

test('the detail shows the structure and variables of the template', function () {
    $viewer = conversationUser([Permission::ViewTemplates], DataScope::Global);

    $this->actingAs($viewer)
        ->get(route('templates.show', $this->template))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('templates/Show')
            ->where('template.structure.header.text', 'Pedido {{1}}')
            ->where('template.structure.footer', 'Gracias por tu compra')
            ->has('template.parameters', 4)
            ->where('template.preview', "*Pedido A-100*\n\nHola Ana, tu pedido llegará el lunes.\n\n_Gracias por tu compra_\n\n[Rastrear]\n[Hablar con un agente]"));
});

test('without the permission the screens are forbidden', function () {
    $agent = User::factory()->agent()->create();

    $this->actingAs($agent)->get(route('templates.index'))->assertForbidden();
    $this->actingAs($agent)->getJson(route('templates.index'))->assertForbidden();
    $this->actingAs($agent)->getJson(route('templates.show', $this->template))->assertForbidden();
});

test('department-scoped viewers only reach templates of their connections', function () {
    $department = Department::factory()->create();
    $mine = Connection::factory()->create();
    $mine->departments()->attach($department);
    $own = TemplateFixtures::stored($mine, ['name' => 'own_template']);
    $viewer = conversationUser([Permission::ViewTemplates], DataScope::Departments, [$department]);

    $this->actingAs($viewer)
        ->get(route('templates.index'))
        ->assertInertia(fn (Assert $page) => $page->has('templates.data', 1)->where('templates.data.0.name', 'own_template'));
    $this->actingAs($viewer)->getJson(route('templates.show', $this->template))->assertNotFound();
    $this->actingAs($viewer)->getJson(route('templates.show', $own))->assertOk();
});

test('templates removed from Meta are only listed for people who can see deleted records', function () {
    $removed = TemplateFixtures::stored($this->connection, ['name' => 'retirada', 'deleted_at' => now()]);
    $viewer = conversationUser([Permission::ViewTemplates], DataScope::Global);

    $this->actingAs($viewer)
        ->get(route('templates.index', ['record' => 'deleted']))
        ->assertInertia(fn (Assert $page) => $page->where('templates.data.0.name', 'order_update'));
    $this->actingAs($viewer)->getJson(route('templates.show', $removed))->assertNotFound();
});

test('the templates list can be exported to Excel', function () {
    $admin = User::factory()->superAdministrator()->create();

    $this->actingAs($admin)
        ->get(route('exports.generate', ['report' => 'message-templates', 'format' => 'xlsx']))
        ->assertOk()
        ->assertDownload();
});

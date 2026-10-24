<?php

use App\Enums\ConnectionChannel;
use App\Jobs\SyncConnectionTemplates;
use App\Models\Connection;
use App\Models\Department;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Support\WebChatFixtures;

/*
| A web chat connection is configured by its widget and authorized origins,
| never by Meta credentials: it can be activated and reply without them, and
| nothing ever calls Meta for it.
*/

beforeEach(function () {
    Http::preventStrayRequests();
    Queue::fake();
    $this->admin = User::factory()->superAdministrator()->create();
});

test('a web chat connection with a widget and origins is activated without Meta credentials', function () {
    $webChat = WebChatFixtures::connection([], ['is_active' => false]);

    $this->actingAs($this->admin)
        ->patch(route('connections.status.update', $webChat), ['is_active' => true])
        ->assertSessionHasNoErrors();

    expect($webChat->refresh())
        ->is_active->toBeTrue()
        ->hasRequiredCredentials()->toBeFalse()
        ->isConfigured()->toBeTrue()
        ->sendingBlockReason()->toBeNull();
    Queue::assertNotPushed(SyncConnectionTemplates::class);
    Http::assertNothingSent();
});

test('a web chat connection without authorized origins cannot be activated', function () {
    $webChat = WebChatFixtures::connection(['allowed_origins' => []], ['is_active' => false]);

    $this->actingAs($this->admin)
        ->patchJson(route('connections.status.update', $webChat), ['is_active' => true])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['is_active' => 'Para activar el chat web configura primero su widget con al menos un dominio autorizado.']);

    expect($webChat->refresh()->is_active)->toBeFalse();
});

test('a WhatsApp connection still needs its Meta credentials to be activated', function () {
    $whatsapp = Connection::factory()->unconfigured()->inactive()->create();

    $this->actingAs($this->admin)
        ->patchJson(route('connections.status.update', $whatsapp), ['is_active' => true])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['is_active' => 'Para activar la conexión guarda primero el Phone number ID, el WABA ID y el token de acceso.']);
});

test('the reason a reply cannot be sent names the channel of the connection', function (Closure $connection, ?string $expected) {
    expect($connection()->sendingBlockReason())->toBe($expected);
})->with([
    'web chat ready' => [fn () => WebChatFixtures::connection([], ['name' => 'Sitio']), null],
    'web chat deactivated' => [fn () => WebChatFixtures::connection([], ['name' => 'Sitio', 'is_active' => false]), 'La conexión de Chat web «Sitio» está desactivada: no se pueden enviar mensajes hasta que un administrador la vuelva a activar.'],
    'web chat deleted' => [fn () => WebChatFixtures::connection([], ['name' => 'Sitio', 'is_active' => false, 'deleted_at' => now()]), 'La conexión de Chat web «Sitio» fue eliminada: ya no se pueden enviar mensajes desde esta conversación. El historial sigue disponible.'],
    'web chat without origins' => [fn () => WebChatFixtures::connection(['allowed_origins' => []], ['name' => 'Sitio']), 'La conexión de Chat web «Sitio» no tiene configurado su widget ni sus dominios autorizados: un administrador debe completarlos antes de enviar mensajes.'],
    'whatsapp without credentials' => [fn () => Connection::factory()->unconfigured()->create(['name' => 'Ventas']), 'La conexión de WhatsApp «Ventas» no tiene guardados sus identificadores o su token de acceso de Meta: un administrador debe completarlos antes de enviar mensajes.'],
    'whatsapp rejected by Meta' => [fn () => Connection::factory()->withInvalidCredentials()->create(['name' => 'Ventas']), 'Meta rechazó las credenciales de la conexión de WhatsApp «Ventas»: un administrador debe corregirlas y verificarlas antes de enviar mensajes.'],
]);

test('the web chat channel has no window, no templates and its own contact source', function () {
    expect(ConnectionChannel::WebChat)
        ->label()->toBe('Chat web')
        ->usesCustomerServiceWindow()->toBeFalse()
        ->supportsTemplates()->toBeFalse()
        ->defaultProvider()->value->toBe('web_widget')
        ->contactSource()->value->toBe('web_chat')
        ->fallbackContactName('wv:01jabcdefghjkmnpqrstvwxyz')->toBe('Visitante web tvwxyz')
        ->and(ConnectionChannel::Whatsapp->fallbackContactName('5215512345678'))->toBe('WhatsApp 5215512345678');
});

test('the health of a connection list never lazy loads the widget, wherever it is built', function () {
    $department = Department::factory()->create();
    $whatsapp = Connection::factory()->verified()->create();
    $webChat = WebChatFixtures::connection();
    $department->connections()->attach([$whatsapp->id, $webChat->id]);

    expect(Connection::query()->whereKey([$whatsapp->id, $webChat->id])->get()->map->health()->all())->toHaveCount(2)
        ->and(Connection::query()->findOrFail($webChat->id)->isConfigured())->toBeTrue();

    $this->actingAs($this->admin)->get(route('departments.show', $department))->assertOk();
    $this->actingAs($this->admin)->get(route('connections.index'))->assertOk();
});

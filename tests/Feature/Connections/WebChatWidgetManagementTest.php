<?php

use App\Enums\AuditAction;
use App\Enums\ConnectionChannel;
use App\Enums\ConnectionProvider;
use App\Enums\DataScope;
use App\Enums\Permission;
use App\Models\AuditLog;
use App\Models\Connection;
use App\Models\Role;
use App\Models\User;
use App\Models\WebChatSession;
use App\Models\WebChatWidget;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\WebChatFixtures;

/*
| A web chat connection is created from Conexiones without any Meta data and
| configured through its widget: authorized origins, appearance,
| identification, pre-chat form and availability. Its public key and its
| identity secret can be rotated; the secret is shown only once.
*/

beforeEach(function () {
    Http::preventStrayRequests();
    $this->admin = User::factory()->superAdministrator()->create();
});

/**
 * @return array<string, mixed>
 */
function widgetPayload(array $overrides = []): array
{
    return [
        'allowed_origins' => ['https://tienda.test'],
        'title' => 'Chatea con nosotros',
        'subtitle' => 'Te respondemos en minutos',
        'welcome_text' => '¡Hola! ¿En qué podemos ayudarte?',
        'offline_text' => 'Déjanos tu mensaje.',
        'position' => 'right',
        'accent_color' => '#2563eb',
        'launcher_enabled' => true,
        'launcher_label' => 'Ayuda',
        'identity_mode' => 'anonymous',
        'prechat' => ['fields' => ['name'], 'required' => false, 'privacy_notice' => null],
        'availability_mode' => 'always',
        'session_idle_days' => 30,
        ...$overrides,
    ];
}

test('a web chat connection is created without Meta data and gets its widget', function () {
    $this->actingAs($this->admin)
        ->post(route('connections.store'), [
            'channel' => 'web_chat',
            'name' => 'Sitio web',
            'department_ids' => [],
        ])
        ->assertSessionHasNoErrors();

    $connection = Connection::query()->with('webChatWidget')->sole();

    expect($connection)
        ->channel->toBe(ConnectionChannel::WebChat)
        ->provider->toBe(ConnectionProvider::WebWidget)
        ->is_active->toBeFalse()
        ->phone_number_id->toBeNull()
        ->and($connection->webChatWidget)
        ->title->toBe('Sitio web')
        ->allowed_origins->toBe([])
        ->and($connection->configurationHealth()->value)->toBe('not_configured');
});

test('the create form offers the chosen channel and its provider', function () {
    $this->actingAs($this->admin)
        ->get(route('connections.create', ['channel' => 'web_chat']))
        ->assertInertia(fn (Assert $page) => $page
            ->component('connections/Form')
            ->where('channel', ['value' => 'web_chat', 'label' => 'Chat web'])
            ->where('provider', ['value' => 'web_widget', 'label' => 'Widget de chat web'])
            ->where('graphVersion', null)
            ->has('widgetOptions.positions', 2));

    $this->actingAs($this->admin)
        ->get(route('connections.create'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('channel.value', 'whatsapp')
            ->where('provider.value', 'meta_cloud_api')
            ->where('widgetOptions', null));
});

test('Meta fields are rejected for a web chat connection', function (string $field, string $value) {
    $this->actingAs($this->admin)
        ->postJson(route('connections.store'), [
            'channel' => 'web_chat',
            'name' => 'Sitio web',
            'department_ids' => [],
            $field => $value,
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors([$field => 'Este dato solo existe en las conexiones de WhatsApp.']);

    $webChat = WebChatFixtures::connection();

    $this->actingAs($this->admin)
        ->putJson(route('connections.update', $webChat), ['name' => 'Sitio', 'department_ids' => [], $field => $value])
        ->assertUnprocessable()
        ->assertJsonValidationErrors([$field]);

    expect(Connection::query()->count())->toBe(1);
})->with([
    'phone number id' => ['phone_number_id', '106540352242922'],
    'access token' => ['access_token', 'EAAGplainAccessToken1234567890abcdef'],
    'display phone number' => ['display_phone_number', '+52 55 1234 5678'],
]);

test('the channel cannot be changed when updating', function () {
    $webChat = WebChatFixtures::connection();

    $this->actingAs($this->admin)
        ->putJson(route('connections.update', $webChat), ['channel' => 'whatsapp', 'name' => 'Sitio', 'department_ids' => []])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['channel' => 'El canal de una conexión no se puede cambiar. Crea una conexión nueva para otro canal.']);

    expect($webChat->refresh()->channel)->toBe(ConnectionChannel::WebChat);
});

test('an unknown channel is rejected', function () {
    $this->actingAs($this->admin)
        ->postJson(route('connections.store'), ['channel' => 'instagram', 'name' => 'Otro', 'department_ids' => []])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['channel']);
});

test('the widget settings are saved with normalized origins and audited', function () {
    $webChat = WebChatFixtures::connection(['allowed_origins' => []], ['is_active' => false]);

    $this->actingAs($this->admin)
        ->put(route('connections.widget.update', $webChat), widgetPayload([
            'allowed_origins' => ['HTTPS://Tienda.Example.com:443/', 'http://localhost:5173', 'http://127.0.0.1:8080'],
            'accent_color' => '#1D4ED8',
            'identity_mode' => 'optional',
            'prechat' => ['fields' => ['email', 'name'], 'required' => true, 'privacy_notice' => 'Aviso de privacidad'],
            'session_idle_days' => 7,
        ]))
        ->assertSessionHasNoErrors()
        ->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'Configuración del chat web guardada.']);

    $widget = $webChat->webChatWidget()->sole();

    expect($widget)
        ->allowed_origins->toBe(['https://tienda.example.com', 'http://localhost:5173', 'http://127.0.0.1:8080'])
        ->accent_color->toBe('#1d4ed8')
        ->identity_mode->value->toBe('optional')
        ->prechat->toBe(['fields' => ['name', 'email'], 'required' => true, 'privacy_notice' => 'Aviso de privacidad'])
        ->session_idle_days->toBe(7)
        ->and($webChat->refresh()->load('webChatWidget')->isConfigured())->toBeTrue();

    $audit = AuditLog::query()->where('action', AuditAction::WebChatWidgetUpdated)->sole();

    expect($audit->properties['changes']['allowed_origins']['added'])->toHaveCount(3);
});

test('saving the same widget settings writes nothing', function () {
    $webChat = WebChatFixtures::connection();
    $this->actingAs($this->admin)->put(route('connections.widget.update', $webChat), widgetPayload())->assertSessionHasNoErrors();

    $this->actingAs($this->admin)
        ->put(route('connections.widget.update', $webChat), widgetPayload(['allowed_origins' => ['https://TIENDA.test/']]))
        ->assertInertiaFlash('toast', ['type' => 'info', 'message' => 'No había cambios por guardar.']);

    expect(AuditLog::query()->where('action', AuditAction::WebChatWidgetUpdated)->count())->toBe(1);
});

test('authorized origins are validated', function (array $origins, string $error) {
    $webChat = WebChatFixtures::connection();

    $this->actingAs($this->admin)
        ->putJson(route('connections.widget.update', $webChat), widgetPayload(['allowed_origins' => $origins]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors([$error]);

    expect($webChat->webChatWidget()->sole()->allowed_origins)->toBe(['https://tienda.test']);
})->with([
    'none' => [[], 'allowed_origins'],
    'plain http outside localhost' => [['http://tienda.example.com'], 'allowed_origins.0'],
    'with a path' => [['https://tienda.example.com/tienda'], 'allowed_origins.0'],
    'with a query' => [['https://tienda.example.com?x=1'], 'allowed_origins.0'],
    'wildcard subdomain' => [['https://*.example.com'], 'allowed_origins.0'],
    'bare wildcard' => [['*'], 'allowed_origins.0'],
    'not an origin' => [['tienda.example.com'], 'allowed_origins.0'],
    'other scheme' => [['ftp://tienda.example.com'], 'allowed_origins.0'],
    'duplicated after normalizing' => [['https://tienda.example.com', 'https://TIENDA.example.com:443'], 'allowed_origins'],
    'more than twenty' => [array_map(fn (int $i): string => "https://s{$i}.example.com", range(1, 21)), 'allowed_origins'],
]);

test('the wildcard and http errors explain what to write', function () {
    $webChat = WebChatFixtures::connection();

    $this->actingAs($this->admin)
        ->putJson(route('connections.widget.update', $webChat), widgetPayload(['allowed_origins' => ['https://*.example.com', 'http://tienda.example.com']]))
        ->assertJsonValidationErrors([
            'allowed_origins.0' => 'No se admiten comodines: escribe cada dominio completo, por ejemplo https://tienda.example.com.',
            'allowed_origins.1' => 'El dominio debe usar https. Solo localhost y 127.0.0.1 se admiten con http, para desarrollo.',
        ]);
});

test('appearance and limits are validated', function (array $overrides, string $error) {
    $webChat = WebChatFixtures::connection();

    $this->actingAs($this->admin)
        ->putJson(route('connections.widget.update', $webChat), widgetPayload($overrides))
        ->assertUnprocessable()
        ->assertJsonValidationErrors([$error]);
})->with([
    'accent color too light for white text' => [['accent_color' => '#facc15'], 'accent_color'],
    'accent color not hexadecimal' => [['accent_color' => 'blue'], 'accent_color'],
    'title missing' => [['title' => ''], 'title'],
    'title too long' => [['title' => str_repeat('a', 61)], 'title'],
    'launcher label too long' => [['launcher_label' => str_repeat('a', 41)], 'launcher_label'],
    'unknown position' => [['position' => 'top'], 'position'],
    'unknown identity mode' => [['identity_mode' => 'magic'], 'identity_mode'],
    'unknown pre-chat field' => [['prechat' => ['fields' => ['phone'], 'required' => false]], 'prechat.fields.0'],
    'idle days out of range' => [['session_idle_days' => 91], 'session_idle_days'],
]);

test('the avatar is stored privately and travels inlined', function () {
    Storage::fake('local');
    config(['filesystems.media_disk' => 'local']);
    $webChat = WebChatFixtures::connection();

    $this->actingAs($this->admin)
        ->post(route('connections.widget.update', $webChat), [
            ...widgetPayload(),
            '_method' => 'put',
            'avatar' => UploadedFile::fake()->image('logo.png', 64, 64),
        ])
        ->assertSessionHasNoErrors();

    $path = $webChat->webChatWidget()->sole()->avatar_path;
    Storage::disk('local')->assertExists($path);

    $this->actingAs($this->admin)
        ->get(route('connections.show', $webChat))
        ->assertInertia(fn (Assert $page) => $page
            ->where('connection.web_chat_widget.avatar_url', fn (string $url) => str_starts_with($url, 'data:image/png;base64,')));
});

test('an avatar that is not a real image or is too heavy is rejected', function (Closure $file) {
    Storage::fake('local');
    config(['filesystems.media_disk' => 'local']);
    $webChat = WebChatFixtures::connection();

    $this->actingAs($this->admin)
        ->post(route('connections.widget.update', $webChat), [...widgetPayload(), '_method' => 'put', 'avatar' => $file()], ['Accept' => 'application/json'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['avatar']);
})->with([
    'svg' => [fn () => UploadedFile::fake()->createWithContent('logo.svg', '<svg xmlns="http://www.w3.org/2000/svg"></svg>')],
    'text renamed as png' => [fn () => UploadedFile::fake()->createWithContent('logo.png', 'not really an image')],
    'over 128 KB' => [fn () => UploadedFile::fake()->image('logo.png')->size(200)],
]);

test('rotating the public key replaces it and revokes the visitor sessions', function () {
    $webChat = WebChatFixtures::connection();
    $previousKey = $webChat->webChatWidget->public_key;
    ['session' => $session] = WebChatFixtures::session($webChat);

    $this->actingAs($this->admin)
        ->post(route('connections.widget.rotate-key', $webChat))
        ->assertSessionHasNoErrors();

    $widget = $webChat->webChatWidget()->sole();

    expect($widget->public_key)->not->toBe($previousKey)
        ->and($widget->key_rotated_at)->not->toBeNull()
        ->and($session->refresh())->revoked_at->not->toBeNull()->revoke_reason->toBe('key_rotated')
        ->and(AuditLog::query()->where('action', AuditAction::WebChatKeyRotated)->sole()->properties)->toBe(['revoked_sessions' => 1]);
});

test('rotating the identity secret shows it once and never serializes it', function () {
    $webChat = WebChatFixtures::connection();

    $response = $this->actingAs($this->admin)
        ->post(route('connections.widget.rotate-identity-secret', $webChat))
        ->assertSessionHasNoErrors();

    $secret = $webChat->webChatWidget()->sole()->identity_secret;

    expect($secret)->toHaveLength(64);
    $response->assertInertiaFlash('identity_secret', $secret);

    // The redirected page receives it once; the following visits never do.
    $this->actingAs($this->admin)->get(route('connections.show', $webChat))
        ->assertSee($secret, escape: false);

    $show = $this->actingAs($this->admin)->get(route('connections.show', $webChat));
    $index = $this->actingAs($this->admin)->get(route('connections.index'));

    $show->assertInertia(fn (Assert $page) => $page
        ->where('connection.web_chat_widget.identity_secret_set', true)
        ->missing('connection.web_chat_widget.identity_secret'));

    foreach ([$show, $index] as $page) {
        expect($page->getContent())->not->toContain($secret);
    }

    expect(AuditLog::query()->where('action', AuditAction::WebChatIdentitySecretRotated)->sole()->properties)->toBeEmpty()
        ->and(json_encode(AuditLog::query()->get()->toArray()))->not->toContain($secret);
});

test('the connection page shows the widget snippet and the preview frame', function () {
    $webChat = WebChatFixtures::connection();
    $key = $webChat->webChatWidget->public_key;

    $this->actingAs($this->admin)
        ->get(route('connections.show', $webChat))
        ->assertInertia(fn (Assert $page) => $page
            ->component('connections/Form')
            ->where('channel.value', 'web_chat')
            ->where('diagnostics', null)
            ->where('connection.has_required_credentials', true)
            ->where('connection.configuration_health.value', 'configured')
            ->where('connection.configuration_health.label', 'Configurada')
            ->where('connection.web_chat_widget.public_key', $key)
            ->where('connection.web_chat_widget.allowed_origins', ['https://tienda.test'])
            ->where('connection.web_chat_widget.frame_url', url('/widget/v1/frame/'.$key))
            ->where('connection.web_chat_widget.snippet', fn (string $snippet) => str_contains($snippet, url('/widget/v1/chat-manager.js'))
                && str_contains($snippet, "ChatManager('init',{key:'{$key}'})"))
            ->where('connection.can.verify', false));
});

test('the list includes web chat connections without lazy loading their widget', function () {
    WebChatFixtures::connection([], ['name' => 'Sitio A']);
    WebChatFixtures::connection(['allowed_origins' => []], ['name' => 'Sitio B', 'is_active' => false]);
    Connection::factory()->create(['name' => 'WhatsApp Ventas']);

    $this->actingAs($this->admin)
        ->get(route('connections.index', ['channel' => 'web_chat']))
        ->assertInertia(fn (Assert $page) => $page
            ->has('connections', 2)
            ->where('connections.0.name', 'Sitio A')
            ->where('connections.0.channel', ['value' => 'web_chat', 'label' => 'Chat web'])
            ->where('connections.1.configuration_health.value', 'not_configured')
            ->where('connections.1.configuration_health.label', 'Sin configurar')
            ->where('stats.verified', 0));
});

test('people without connections.manage cannot change the widget', function (Closure $makeUser) {
    $webChat = WebChatFixtures::connection();
    $key = $webChat->webChatWidget->public_key;
    $user = $makeUser();

    $this->actingAs($user)->put(route('connections.widget.update', $webChat), widgetPayload(['title' => 'Otro']))->assertForbidden();
    $this->actingAs($user)->post(route('connections.widget.rotate-key', $webChat))->assertForbidden();
    $this->actingAs($user)->post(route('connections.widget.rotate-identity-secret', $webChat))->assertForbidden();

    expect($webChat->webChatWidget()->sole())->title->toBe('Chatea con nosotros')->public_key->toBe($key);
})->with([
    'agent' => [fn () => User::factory()->agent()->create()],
    'viewer' => [fn () => User::factory()->withRole(Role::factory()->scope(DataScope::Global)->withPermissions([Permission::ViewConnections])->create())->create()],
]);

test('the widget routes do not exist for a WhatsApp or deleted connection', function () {
    $whatsapp = Connection::factory()->create();
    $deleted = WebChatFixtures::connection([], ['is_active' => false, 'deleted_at' => now()]);

    foreach ([$whatsapp, $deleted] as $connection) {
        $this->actingAs($this->admin)->put(route('connections.widget.update', $connection), widgetPayload())->assertForbidden();
        $this->actingAs($this->admin)->post(route('connections.widget.rotate-key', $connection))->assertForbidden();
    }
});

test('verifying credentials or the app subscription is refused for a web chat connection', function () {
    $webChat = WebChatFixtures::connection();

    $this->actingAs($this->admin)->post(route('connections.verify', $webChat))->assertForbidden();
    $this->actingAs($this->admin)->post(route('connections.subscription.check', $webChat))->assertForbidden();

    Http::assertNothingSent();
});

test('guests are redirected to the login from the widget routes', function () {
    $webChat = WebChatFixtures::connection();

    $this->put(route('connections.widget.update', $webChat), widgetPayload())->assertRedirect(route('login'));
    expect(WebChatSession::query()->count())->toBe(0)
        ->and(WebChatWidget::query()->sole()->title)->toBe('Chatea con nosotros');
});

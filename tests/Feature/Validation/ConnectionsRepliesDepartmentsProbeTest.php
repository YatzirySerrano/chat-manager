<?php

use App\Enums\ConnectionHealth;
use App\Models\AuditLog;
use App\Models\Connection;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\ConversationEvent;
use App\Models\Department;
use App\Models\Message;
use App\Models\QuickReply;
use App\Models\User;
use App\Services\PlatformSettings;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;

const PROBE_TOKEN = 'EAAGprobeAccessTokenValue0123456789abcdef';
const PROBE_APP_SECRET = 'probe-app-secret-0123456789';
const PROBE_VERIFY_TOKEN = 'probe-verify-token-0123456789';
const PROBE_PHONE_ID = '106540352242999';

/**
 * @return array<string, mixed>
 */
function probeConnectionPayload(array $overrides = []): array
{
    return [
        'name' => 'Probe WhatsApp',
        'display_phone_number' => '',
        'phone_number_id' => '',
        'business_account_id' => '',
        'meta_app_id' => '',
        'access_token' => '',
        'app_secret' => '',
        'webhook_verify_token' => '',
        'department_ids' => [],
        'default_department_id' => null,
        'welcome_enabled' => false,
        'welcome_message' => '',
        'away_enabled' => false,
        'away_message' => '',
        'farewell_enabled' => false,
        'farewell_message' => '',
        'reopen_window_minutes' => null,
        ...$overrides,
    ];
}

/*
| Connections
*/

test('a connection created without credentials is completed later and every value persists without exposing secrets', function () {
    $admin = User::factory()->administrator()->create();
    $sales = Department::factory()->create(['name' => 'Ventas']);
    $support = Department::factory()->create(['name' => 'Soporte']);

    $this->actingAs($admin)->post(route('connections.store'), probeConnectionPayload())->assertSessionHasNoErrors();

    $connection = Connection::query()->sole();
    expect($connection->is_active)->toBeFalse()
        ->and($connection->configurationHealth())->toBe(ConnectionHealth::NotConfigured);

    $this->actingAs($admin)->put(route('connections.update', $connection), probeConnectionPayload([
        'phone_number_id' => PROBE_PHONE_ID,
        'business_account_id' => '102290129340398',
        'access_token' => PROBE_TOKEN,
        'app_secret' => PROBE_APP_SECRET,
        'webhook_verify_token' => PROBE_VERIFY_TOKEN,
        'department_ids' => [$sales->id, $support->id],
        'default_department_id' => $support->id,
        'welcome_enabled' => true,
        'welcome_message' => 'Hola, gracias por escribir.',
        'away_enabled' => true,
        'away_message' => 'Estamos fuera de horario.',
        'farewell_message' => 'Texto guardado aunque esté desactivado.',
        'reopen_window_minutes' => 60,
    ]))->assertSessionHasNoErrors();

    $raw = DB::table('connections')->find($connection->id);
    expect($raw->access_token)->not->toContain(PROBE_TOKEN)
        ->and($raw->app_secret)->not->toContain(PROBE_APP_SECRET)
        ->and($raw->webhook_verify_token)->not->toContain(PROBE_VERIFY_TOKEN)
        ->and(decrypt($raw->access_token, false))->toBe(PROBE_TOKEN);

    $show = $this->actingAs($admin)->get(route('connections.show', $connection));
    $show->assertInertia(fn (Assert $page) => $page
        ->where('connection.health.value', 'inactive')
        ->where('connection.configuration_health.value', 'configured')
        ->where('connection.default_department_id', $support->id)
        ->where('connection.messages.welcome', ['enabled' => true, 'text' => 'Hola, gracias por escribir.'])
        ->where('connection.messages.away', ['enabled' => true, 'text' => 'Estamos fuera de horario.'])
        ->where('connection.messages.farewell', ['enabled' => false, 'text' => 'Texto guardado aunque esté desactivado.'])
        ->where('connection.reopen_window_minutes', 60)
        ->where('connection.departments', fn ($departments) => collect($departments)->pluck('name')->all() === ['Soporte', 'Ventas']));

    $leaks = [
        $show->getContent(),
        $this->actingAs($admin)->get(route('connections.index'))->getContent(),
        $this->actingAs($admin)->get(route('departments.show', $sales))->getContent(),
        AuditLog::query()->get()->toJson(),
    ];

    foreach ($leaks as $content) {
        expect($content)->not->toContain(PROBE_TOKEN, PROBE_APP_SECRET, PROBE_VERIFY_TOKEN);
    }
});

test('the verification endpoint only changes health from a faked Meta answer and never reaches the real API', function () {
    Http::preventStrayRequests();
    Http::fake(['graph.facebook.com/*' => Http::response([
        'id' => PROBE_PHONE_ID,
        'verified_name' => 'Probe SA',
        'display_phone_number' => '+52 55 0000 0000',
        'status' => 'CONNECTED',
        'quality_rating' => 'GREEN',
    ])]);

    $admin = User::factory()->administrator()->create();
    $connection = Connection::factory()->create(['phone_number_id' => PROBE_PHONE_ID, 'access_token' => PROBE_TOKEN]);

    expect($connection->health())->toBe(ConnectionHealth::Configured);

    $this->actingAs($admin)->post(route('connections.verify', $connection))->assertSessionHasNoErrors();

    Http::assertSentCount(1);
    Http::assertSent(fn ($request) => str_contains($request->url(), 'graph.facebook.com') && str_contains($request->url(), PROBE_PHONE_ID));

    $this->actingAs($admin)
        ->get(route('connections.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('connections.0.health.value', 'credentials_verified')
            ->where('connections.0.checks.last_inbound_at', null)
            ->where('stats.verified', 1));

    $supervisor = User::factory()->supervisor()->create();
    $this->actingAs($supervisor)->post(route('connections.verify', $connection))->assertForbidden();
    Http::assertSentCount(1);
});

test('an enabled automatic message made only of spaces is rejected', function () {
    $admin = User::factory()->administrator()->create();

    $this->actingAs($admin)
        ->post(route('connections.store'), probeConnectionPayload(['welcome_enabled' => true, 'welcome_message' => "   \n  "]))
        ->assertSessionHasErrors(['welcome_message' => 'Escribe el mensaje o desactívalo.']);

    expect(Connection::query()->exists())->toBeFalse();
});

test('current behavior: automatic connection messages accept HTML while quick replies reject it', function () {
    $admin = User::factory()->administrator()->create();

    $this->actingAs($admin)
        ->post(route('connections.store'), probeConnectionPayload([
            'welcome_enabled' => true,
            'welcome_message' => 'Hola <b>cliente</b> <script>alert(1)</script>',
        ]))
        ->assertSessionHasNoErrors();

    expect(Connection::query()->sole()->welcome_message)->toContain('<script>');
});

test('current behavior: the default department of an active connection can be deactivated', function () {
    $admin = User::factory()->administrator()->create();
    $department = Department::factory()->create();
    $connection = Connection::factory()->create(['default_department_id' => $department->id]);
    $connection->departments()->attach($department);

    $this->actingAs($admin)
        ->patch(route('departments.status.update', $department), ['is_active' => false])
        ->assertSessionHasNoErrors();

    expect($department->refresh()->is_active)->toBeFalse()
        ->and($connection->refresh()->is_active)->toBeTrue()
        ->and($connection->default_department_id)->toBe($department->id);
});

/*
| Quick replies
*/

test('the picker only offers global replies and those of the conversation department', function () {
    [$sales, $support] = [Department::factory()->create(['name' => 'Ventas']), Department::factory()->create(['name' => 'Soporte'])];
    $agent = User::factory()->agent()->inDepartments([$sales, $support])->create();
    $salesReply = QuickReply::factory()->forDepartments($sales)->create(['shortcut' => 'hola', 'title' => 'Saludo', 'body' => 'Hola de Ventas']);
    QuickReply::factory()->forDepartments($support)->create(['shortcut' => 'hola', 'title' => 'Saludo', 'body' => 'Hola de Soporte']);

    $replies = $this->actingAs($agent)
        ->getJson(route('quick-replies.available', ['q' => 'hola', 'department_id' => $sales->id]))
        ->assertOk()
        ->json('data');

    expect(collect($replies)->pluck('id')->all())->toBe([$salesReply->id]);
});

test('replies with the same shortcut are labeled with their department to tell them apart', function () {
    [$sales, $support] = [Department::factory()->create(['name' => 'Ventas']), Department::factory()->create(['name' => 'Soporte'])];
    $agent = User::factory()->agent()->inDepartments([$sales, $support])->create();
    QuickReply::factory()->forDepartments($sales)->create(['shortcut' => 'hola', 'title' => 'Saludo']);
    QuickReply::factory()->forDepartments($support)->create(['shortcut' => 'hola', 'title' => 'Saludo']);

    $replies = collect($this->actingAs($agent)
        ->getJson(route('quick-replies.available', ['q' => 'hola']))
        ->assertOk()
        ->json('data'));

    expect($replies)->toHaveCount(2)
        ->and($replies->every(fn (array $reply): bool => array_key_exists('departments', $reply)))->toBeTrue();
});

test('rendering replies in the picker has no side effects', function () {
    Http::preventStrayRequests();
    Queue::fake();
    Mail::fake();
    Notification::fake();

    $department = Department::factory()->create(['name' => 'Soporte']);
    $agent = User::factory()->agent()->inDepartments($department)->create(['name' => 'Carlos']);
    $contact = Contact::factory()->create(['name' => 'Ana']);
    $conversation = Conversation::factory()->inDepartment($department)->assignedTo($agent)->for($contact)->create();
    QuickReply::factory()->forDepartments($department)->create(['shortcut' => 'hola', 'body' => 'Hola {contacto.nombre}, soy {agente.nombre}.']);
    $before = [Message::query()->count(), ConversationEvent::query()->count(), AuditLog::query()->count(), $conversation->refresh()->updated_at];

    $this->actingAs($agent)
        ->getJson(route('quick-replies.available', ['q' => 'hola', 'contact_id' => $contact->id, 'department_id' => $department->id]))
        ->assertOk()
        ->assertJsonPath('data.0.text', 'Hola Ana, soy Carlos.')
        ->assertJsonPath('data.0.missing', []);

    expect([Message::query()->count(), ConversationEvent::query()->count(), AuditLog::query()->count(), $conversation->refresh()->updated_at])->toEqual($before);
    Http::assertNothingSent();
    Queue::assertNothingPushed();
    Mail::assertNothingSent();
    Notification::assertNothingSent();
});

test('a variable without value is blank and reported when the department is outside the agent scope', function () {
    $own = Department::factory()->create(['name' => 'Soporte']);
    $foreign = Department::factory()->create(['name' => 'Dirección']);
    $agent = User::factory()->agent()->inDepartments($own)->create();
    QuickReply::factory()->create(['shortcut' => 'depto', 'body' => 'Te atiende {departamento.nombre}.']);

    $this->actingAs($agent)
        ->getJson(route('quick-replies.available', ['q' => 'depto', 'department_id' => $foreign->id]))
        ->assertJsonPath('data.0.text', 'Te atiende.')
        ->assertJsonPath('data.0.missing', ['departamento.nombre']);
});

test('moving a reply into a department where its shortcut is already used is rejected', function () {
    $admin = User::factory()->administrator()->create();
    [$sales, $support] = [Department::factory()->create(['name' => 'Ventas']), Department::factory()->create(['name' => 'Soporte'])];
    QuickReply::factory()->forDepartments($sales)->create(['shortcut' => 'hola', 'title' => 'Saludo ventas']);
    $supportReply = QuickReply::factory()->forDepartments($support)->create(['shortcut' => 'hola', 'title' => 'Saludo soporte', 'body' => 'Hola']);

    $this->actingAs($admin)
        ->put(route('quick-replies.update', $supportReply), [
            'title' => 'Saludo soporte',
            'shortcut' => 'hola',
            'body' => 'Hola',
            'is_global' => false,
            'department_ids' => [$support->id, $sales->id],
        ])
        ->assertSessionHasErrors(['shortcut' => 'El atajo /hola ya se usa en «Saludo ventas» en Ventas.']);

    expect($supportReply->departments()->pluck('departments.id')->all())->toBe([$support->id]);

    $this->actingAs($admin)
        ->put(route('quick-replies.update', $supportReply), [
            'title' => 'Saludo soporte',
            'shortcut' => 'hola',
            'body' => 'Hola',
            'is_global' => true,
        ])
        ->assertSessionHasErrors('shortcut');

    expect($supportReply->refresh()->is_global)->toBeFalse();
});

/*
| Business rule changed on purpose (closing Increment A): a reply restricted to
| departments is only offered while one of those departments is active and the
| user belongs to it. It previously stayed available after deactivation.
*/
test('replies of a deactivated department stop being offered, while global ones and other active departments keep theirs', function () {
    $admin = User::factory()->administrator()->create();
    [$closing, $open] = Department::factory()->count(2)->create();
    $agent = User::factory()->agent()->inDepartments([$closing, $open])->create();
    $onlyClosing = QuickReply::factory()->forDepartments($closing)->create(['shortcut' => 'viejo']);
    $shared = QuickReply::factory()->forDepartments($closing, $open)->create(['shortcut' => 'compartida']);
    $global = QuickReply::factory()->create(['shortcut' => 'global', 'is_global' => true]);

    $this->actingAs($admin)
        ->patch(route('departments.status.update', $closing), ['is_active' => false])
        ->assertSessionHasNoErrors();

    $offered = fn (User $user, array $query = []) => collect($this->actingAs($user)
        ->getJson(route('quick-replies.available', $query))
        ->assertOk()
        ->json('data'));

    expect($offered($agent)->pluck('id')->sort()->values()->all())->toBe(collect([$shared->id, $global->id])->sort()->values()->all())
        ->and($offered($agent)->firstWhere('id', $shared->id)['departments'])->toBe([['id' => $open->id, 'name' => $open->name]])
        ->and($offered($admin)->pluck('id')->all())->not->toContain($onlyClosing->id)
        ->and($offered($agent, ['department_id' => $closing->id])->pluck('id')->all())->toBe([$global->id]);

    $this->actingAs($admin)->get(route('quick-replies.index'))
        ->assertInertia(fn ($page) => $page->has('quickReplies.data', 3));
});

/*
| Departments
*/

test('closed today is counted from midnight in the platform timezone', function () {
    expect(app(PlatformSettings::class)->get('general.timezone'))->toBe('America/Mexico_City');

    $this->travelTo(now()->setTimezone('UTC')->setDate(2026, 10, 17)->setTime(5, 30));

    $admin = User::factory()->administrator()->create();
    $department = Department::factory()->create();

    Conversation::factory()->inDepartment($department)->closed()->create(['closed_at' => now()->setTime(5, 0)]);
    Conversation::factory()->inDepartment($department)->closed()->create(['closed_at' => now()->subDay()->setTime(7, 0)]);
    Conversation::factory()->inDepartment($department)->closed()->create(['closed_at' => now()->subDay()->setTime(5, 0)]);

    $this->actingAs($admin)
        ->get(route('departments.show', $department))
        ->assertInertia(fn (Assert $page) => $page
            ->where('metrics.closed_today', 2)
            ->where('metrics.closed_total', 3)
            ->where('metrics.total', 3));
});

test('supervisors cannot open or change departments outside their scope', function () {
    [$own, $foreign] = Department::factory()->count(2)->create();
    $supervisor = User::factory()->supervisor()->inDepartments($own)->create();

    $this->actingAs($supervisor)->get(route('departments.show', $own))->assertOk();
    $this->actingAs($supervisor)->get(route('departments.show', $foreign))->assertForbidden();
    $this->actingAs($supervisor)->patch(route('departments.status.update', $foreign), ['is_active' => false])->assertForbidden();
    $this->actingAs($supervisor)->put(route('departments.update', $foreign), ['name' => 'Tomado'])->assertForbidden();

    expect($foreign->refresh()->is_active)->toBeTrue();
});

test('an inactive connection with traffic does not mark messaging as connected on the dashboard', function () {
    $admin = User::factory()->administrator()->create();
    Connection::factory()->operational()->inactive()->create();

    $this->actingAs($admin)
        ->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page
            ->loadDeferredProps(fn (Assert $reload) => $reload
                ->where('overview.messaging_connected', false)
                ->where('overview.conversations.active', null)));
});

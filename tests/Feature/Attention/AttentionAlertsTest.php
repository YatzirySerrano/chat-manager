<?php

use App\Enums\AuditAction;
use App\Enums\DataScope;
use App\Enums\Permission;
use App\Events\Broadcasts\RealtimeSignal;
use App\Models\AttentionAlert;
use App\Models\AttentionAlertRecipient;
use App\Models\AuditLog;
use App\Models\Department;
use App\Models\User;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

/*
| Attention alerts: an administrator sends them to one agent, a selection or
| every agent within their reach; each recipient gets a real-time signal
| (ids only) and finds them pending until they acknowledge them, with
| delivery and acknowledgement recorded apart.
*/

beforeEach(function () {
    $this->support = Department::factory()->create();
    $this->sales = Department::factory()->create();
    $this->admin = User::factory()->administrator()->create(['name' => 'Laura Admin']);
    $this->ana = User::factory()->agent()->inDepartments($this->support)->create(['name' => 'Ana']);
    $this->beto = User::factory()->agent()->inDepartments($this->support)->create(['name' => 'Beto']);
    $this->carla = User::factory()->agent()->inDepartments($this->sales)->create(['name' => 'Carla']);
});

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function alertPayload(array $overrides = []): array
{
    return [
        'client_key' => (string) Str::uuid(),
        'audience' => 'individual',
        'recipient_ids' => [],
        'title' => 'Clientes esperando',
        'body' => 'Revisa tus conversaciones pendientes, por favor.',
        'priority' => 'important',
        'color' => 'amber',
        'sound' => 'priority',
        'link_pending' => true,
        ...$overrides,
    ];
}

test('an individual alert reaches only its recipient through their personal channel', function () {
    Event::fake([RealtimeSignal::class]);

    $this->actingAs($this->admin)
        ->post(route('attention.alerts.store'), alertPayload(['recipient_ids' => [$this->ana->id]]))
        ->assertSessionHasNoErrors()
        ->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'Alerta enviada a 1 agente.']);

    $alert = AttentionAlert::query()->sole();
    expect($alert)
        ->sender_id->toBe($this->admin->id)
        ->recipients_count->toBe(1)
        ->action->toBe('pending_conversations')
        ->and($alert->recipients()->pluck('user_id')->all())->toBe([$this->ana->id])
        ->and($alert->recipients()->sole())->delivered_at->toBeNull()->acknowledged_at->toBeNull()
        ->and(AuditLog::query()->where('action', AuditAction::AttentionAlertSent)->sole()->user_id)->toBe($this->admin->id);
    Event::assertDispatched(RealtimeSignal::class, fn (RealtimeSignal $signal): bool => $signal->channels === ["users.{$this->ana->id}"]
        && $signal->event === 'attention.alert'
        && $signal->payload === ['alert_id' => $alert->id]);
});

test('a selection and a general alert reach the chosen agents and every agent within reach', function () {
    $this->actingAs($this->admin)
        ->post(route('attention.alerts.store'), alertPayload(['audience' => 'selection', 'recipient_ids' => [$this->ana->id, $this->carla->id]]))
        ->assertSessionHasNoErrors();
    $this->actingAs($this->admin)
        ->post(route('attention.alerts.store'), alertPayload(['audience' => 'everyone', 'title' => 'Aviso general']))
        ->assertSessionHasNoErrors();

    [$selection, $everyone] = AttentionAlert::query()->orderBy('id')->get();
    expect($selection->recipients()->orderBy('user_id')->pluck('user_id')->all())->toBe([$this->ana->id, $this->carla->id])
        ->and($everyone->recipients()->orderBy('user_id')->pluck('user_id')->all())->toBe([$this->ana->id, $this->beto->id, $this->carla->id]);
});

test('a departments sender only reaches the agents of their departments', function () {
    $sender = conversationUser([Permission::ViewConversations, Permission::ReplyConversations, Permission::ViewAttention, Permission::SendAttentionAlerts], DataScope::Departments, [$this->support]);

    $this->actingAs($sender)
        ->post(route('attention.alerts.store'), alertPayload(['audience' => 'selection', 'recipient_ids' => [$this->ana->id, $this->carla->id]]))
        ->assertSessionHasErrors(['recipient_ids' => 'Algunos de los agentes elegidos ya no pueden recibir alertas. Actualiza la página y vuelve a elegirlos.']);
    $this->actingAs($sender)
        ->post(route('attention.alerts.store'), alertPayload(['audience' => 'everyone']))
        ->assertSessionHasNoErrors();

    expect(AttentionAlert::query()->sole()->recipients()->orderBy('user_id')->pluck('user_id')->all())->toBe([$this->ana->id, $this->beto->id]);
});

test('invalid alerts are refused with a clear message and store nothing', function (array $overrides, array $errors) {
    $this->actingAs($this->admin)
        ->post(route('attention.alerts.store'), alertPayload(['recipient_ids' => [$this->ana->id], ...$overrides]))
        ->assertSessionHasErrors($errors);

    expect(AttentionAlert::query()->count())->toBe(0);
})->with([
    'no recipient' => [['recipient_ids' => []], ['recipient_ids' => 'Elige al menos un agente.']],
    'individual with two agents' => [fn () => ['recipient_ids' => [test()->ana->id, test()->beto->id]], ['recipient_ids' => 'Una alerta individual va dirigida a un solo agente.']],
    'priority outside the catalog' => [['priority' => 'critical'], ['priority']],
    'sound outside the catalog' => [['sound' => 'https://example.com/sound.mp3'], ['sound']],
    'color outside the catalog' => [['color' => '#ff0000'], ['color']],
    'style injection as a color' => [['color' => 'blue;background:url(https://example.com/x)'], ['color']],
    'title too long' => [['title' => str_repeat('a', 121)], ['title']],
    'empty message' => [['body' => '   '], ['body']],
]);

test('a general alert goes to exactly the agents the composer listed by name, and a list that changed meanwhile is refused', function () {
    $listed = $this->actingAs($this->admin)->get(route('attention.index'))
        ->assertInertia(fn (Assert $page) => $page->where('agents', fn ($agents) => collect($agents)->pluck('name')->sort()->values()->all() === ['Ana', 'Beto', 'Carla']))
        ->viewData('page')['props']['agents'];
    $listedIds = collect($listed)->pluck('id')->all();

    $this->actingAs($this->admin)
        ->post(route('attention.alerts.store'), alertPayload(['audience' => 'everyone', 'expected_recipient_ids' => array_reverse($listedIds)]))
        ->assertSessionHasNoErrors();
    expect(AttentionAlert::query()->sole()->recipients()->orderBy('user_id')->pluck('user_id')->all())->toBe(collect($listedIds)->sort()->values()->all());

    $this->beto->forceFill(['is_active' => false])->save();
    User::factory()->agent()->inDepartments($this->sales)->create(['name' => 'Dora']);

    $this->actingAs($this->admin)
        ->post(route('attention.alerts.store'), alertPayload(['audience' => 'everyone', 'title' => 'Otra', 'expected_recipient_ids' => $listedIds]))
        ->assertSessionHasErrors(['recipient_ids' => 'La lista de destinatarios cambió mientras redactabas la alerta: ahora son 3 agentes. Revísala y vuelve a enviarla.']);

    expect(AttentionAlert::query()->count())->toBe(1);
});

test('the chosen background color is kept for the recipient and in the history', function () {
    $this->actingAs($this->admin)->post(route('attention.alerts.store'), alertPayload(['recipient_ids' => [$this->ana->id], 'color' => 'rose']))->assertSessionHasNoErrors();
    $alert = AttentionAlert::query()->sole();

    $this->actingAs($this->ana)->getJson(route('my-alerts.index'))->assertJsonPath('data.0.color', 'rose');
    $this->actingAs($this->admin)->getJson(route('attention.alerts.show', $alert))->assertJsonPath('alert.color', 'rose');
    $this->actingAs($this->admin)->get(route('attention.index'))
        ->assertInertia(fn (Assert $page) => $page->where('alerts.0.color', 'rose'));
});

test('repeating the request or the same alert within a minute does not send it twice', function () {
    $payload = alertPayload(['recipient_ids' => [$this->ana->id]]);

    $this->actingAs($this->admin)->post(route('attention.alerts.store'), $payload)->assertSessionHasNoErrors();
    $this->actingAs($this->admin)->post(route('attention.alerts.store'), $payload)->assertSessionHasNoErrors();
    $this->actingAs($this->admin)
        ->post(route('attention.alerts.store'), [...$payload, 'client_key' => (string) Str::uuid()])
        ->assertSessionHasErrors(['body' => 'Acabas de enviar esta misma alerta. Espera un minuto antes de repetirla.']);

    expect(AttentionAlert::query()->count())->toBe(1)
        ->and(AttentionAlertRecipient::query()->count())->toBe(1);
});

test('an agent cannot send alerts', function () {
    $this->actingAs($this->ana)
        ->post(route('attention.alerts.store'), alertPayload(['recipient_ids' => [$this->beto->id]]))
        ->assertForbidden();

    expect(AttentionAlert::query()->count())->toBe(0);
});

test('the recipient finds the alert pending until acknowledging it, with delivery recorded apart', function () {
    $this->actingAs($this->admin)->post(route('attention.alerts.store'), alertPayload(['recipient_ids' => [$this->ana->id], 'priority' => 'urgent']));
    $row = AttentionAlertRecipient::query()->sole();

    $this->actingAs($this->ana)->getJson(route('my-alerts.index'))
        ->assertOk()
        ->assertJsonPath('total', 1)
        ->assertJsonPath('data.0.id', $row->id)
        ->assertJsonPath('data.0.title', 'Clientes esperando')
        ->assertJsonPath('data.0.priority', 'urgent')
        ->assertJsonPath('data.0.priority_label', 'Urgente')
        ->assertJsonPath('data.0.sound', 'siren')
        ->assertJsonPath('data.0.sender', 'Laura Admin')
        ->assertJsonPath('data.0.action', 'pending_conversations');

    $this->actingAs($this->ana)->postJson(route('my-alerts.delivered'), ['ids' => [$row->id]])->assertJsonPath('updated', 1);
    expect($row->refresh())->delivered_at->not->toBeNull()->acknowledged_at->toBeNull();
    $this->actingAs($this->ana)->getJson(route('my-alerts.index'))->assertJsonPath('total', 1);

    Event::fake([RealtimeSignal::class]);
    $this->actingAs($this->ana)->postJson(route('my-alerts.acknowledge', $row->id))->assertOk();

    expect($row->refresh()->acknowledged_at)->not->toBeNull();
    $this->actingAs($this->ana)->getJson(route('my-alerts.index'))->assertJsonPath('total', 0);
    Event::assertDispatched(RealtimeSignal::class, fn (RealtimeSignal $signal): bool => $signal->channels === ["users.{$this->ana->id}"]
        && $signal->payload === ['alert_id' => $row->attention_alert_id, 'acknowledged' => true]);
});

test('nobody can see, deliver or acknowledge someone else\'s alert', function () {
    $this->actingAs($this->admin)->post(route('attention.alerts.store'), alertPayload(['recipient_ids' => [$this->ana->id]]));
    $row = AttentionAlertRecipient::query()->sole();

    $this->actingAs($this->beto)->getJson(route('my-alerts.index'))->assertJsonPath('total', 0)->assertJsonPath('data', []);
    $this->actingAs($this->beto)->postJson(route('my-alerts.delivered'), ['ids' => [$row->id]])->assertJsonPath('updated', 0);
    $this->actingAs($this->beto)->postJson(route('my-alerts.acknowledge', $row->id))->assertNotFound();

    expect($row->refresh())->delivered_at->toBeNull()->acknowledged_at->toBeNull();
});

test('the history tells how many recipients got and acknowledged each alert, and who', function () {
    $this->actingAs($this->admin)->post(route('attention.alerts.store'), alertPayload(['audience' => 'selection', 'recipient_ids' => [$this->ana->id, $this->beto->id]]));
    $alert = AttentionAlert::query()->sole();
    $anaRow = $alert->recipients()->where('user_id', $this->ana->id)->sole();
    $this->actingAs($this->ana)->postJson(route('my-alerts.acknowledge', $anaRow->id));

    $this->actingAs($this->admin)->getJson(route('attention.alerts.show', $alert))
        ->assertOk()
        ->assertJsonPath('alert.recipients_count', 2)
        ->assertJsonPath('alert.delivered_count', 1)
        ->assertJsonPath('alert.acknowledged_count', 1)
        ->assertJsonPath('recipients.0.name', 'Ana')
        ->assertJsonPath('recipients.1.name', 'Beto')
        ->assertJsonPath('recipients.1.acknowledged_at', null);
});

test('a sender with departments scope only consults their own alerts', function () {
    $alert = AttentionAlert::factory()->create(['sender_id' => $this->admin->id]);
    $sender = conversationUser([Permission::ViewAttention, Permission::SendAttentionAlerts], DataScope::Departments, [$this->support]);

    $this->actingAs($sender)->getJson(route('attention.alerts.show', $alert))->assertNotFound();
});

test('the history never counts protected accounts for people who must not see them', function () {
    $root = User::factory()->superAdministrator()->create();
    User::factory()->superAdministrator()->create();
    $this->actingAs($root)->post(route('attention.alerts.store'), alertPayload(['audience' => 'everyone']))->assertSessionHasNoErrors();
    $alert = AttentionAlert::query()->sole();

    $this->actingAs($this->admin)->getJson(route('attention.alerts.show', $alert))
        ->assertOk()
        ->assertJsonPath('alert.recipients_count', 4)
        ->assertJsonCount(4, 'recipients');

    expect($alert->recipients_count)->toBe(5);
});

<?php

use App\Actions\Templates\QueueTemplateMessage;
use App\Enums\MessageStatus;
use App\Enums\MessageType;
use App\Enums\Permission;
use App\Jobs\DeliverOutboundMessage;
use App\Models\Connection;
use App\Models\ContactIdentity;
use App\Models\Conversation;
use App\Models\Department;
use App\Models\Message;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Templates\TemplateFixtures;

/*
| Templates from the composer: only approved templates of the conversation's
| own connection, validated variables, allowed outside the 24 hour window,
| and re-checked by the delivery job. Sending one is administrative: it
| requires templates.send (global only) on top of being able to reply.
*/

beforeEach(function () {
    Http::preventStrayRequests();
    config(['services.meta.graph_version' => 'v26.0', 'whatsapp.transport' => 'meta']);
    $this->travelTo(CarbonImmutable::parse('2026-10-20 15:00:00', 'UTC'));
    $this->department = Department::factory()->create();
    // The responsible person holds templates.send (Administrador); plain agents do not.
    $this->agent = User::factory()->administrator()->inDepartments($this->department)->create();
    $this->connection = Connection::factory()->verified()->create(['phone_number_id' => '106540352242922']);
    // The customer wrote two days ago: free text is no longer allowed.
    $this->conversation = Conversation::factory()->inDepartment($this->department)->assignedTo($this->agent)->create([
        'connection_id' => $this->connection->id,
        'last_inbound_at' => now()->subDays(2),
    ]);
    ContactIdentity::factory()->create(['contact_id' => $this->conversation->contact_id, 'external_id' => '5215511112222']);
    $this->template = TemplateFixtures::stored($this->connection);
    $this->values = ['header.1' => 'A-100', 'body.1' => 'Ana', 'body.2' => 'lunes', 'button.0.1' => 'A-100'];
    $this->send = fn (array $data, ?User $user = null) => $this->actingAs($user ?? $this->agent)->postJson(route('conversations.templates.store', $this->conversation), [
        'template_id' => $this->template->id,
        'client_reference' => (string) Str::uuid(),
        ...$data,
    ]);
});

test('the composer lists only approved, sendable templates of the conversation connection', function () {
    TemplateFixtures::stored($this->connection, ['name' => 'pending_one', 'status' => 'PENDING']);
    TemplateFixtures::stored($this->connection, ['name' => 'removed_one', 'deleted_at' => now()]);
    TemplateFixtures::stored($this->connection, ['name' => 'otp', 'category' => 'AUTHENTICATION']);
    TemplateFixtures::stored(Connection::factory()->create(), ['name' => 'other_connection']);

    $this->actingAs($this->agent)
        ->getJson(route('conversations.templates.index', $this->conversation))
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.name', 'order_update')
        ->assertJsonPath('data.0.sendable', true)
        ->assertJsonPath('data.0.preview', "*Pedido A-100*\n\nHola Ana, tu pedido llegará el lunes.\n\n_Gracias por tu compra_\n\n[Rastrear]\n[Hablar con un agente]")
        ->assertJsonPath('data.0.parameters.0.key', 'header.1')
        ->assertJsonPath('window.status', 'expired');
});

test('a template is sent outside the window with its variables and stored with the rendered text', function () {
    Http::fake(['graph.facebook.com/v26.0/106540352242922/messages' => Http::response(['messages' => [['id' => 'wamid.template.1']]])]);

    ($this->send)(['parameters' => $this->values])->assertStatus(202)->assertJsonPath('data.status', 'queued');

    $message = Message::query()->sole();
    expect($message)
        ->type->toBe(MessageType::Template)
        ->status->toBe(MessageStatus::Accepted)
        ->body->toBe("*Pedido A-100*\n\nHola Ana, tu pedido llegará el lunes.\n\n_Gracias por tu compra_\n\n[Rastrear]\n[Hablar con un agente]")
        ->sender_user_id->toBe($this->agent->id)
        ->and($message->metadata['template'])->toMatchArray(['id' => $this->template->id, 'name' => 'order_update', 'language' => 'es_MX']);

    Http::assertSent(fn (Request $request): bool => $request['type'] === 'template'
        && $request['template'] === [
            'name' => 'order_update',
            'language' => ['code' => 'es_MX'],
            'components' => [
                ['type' => 'header', 'parameters' => [['type' => 'text', 'text' => 'A-100']]],
                ['type' => 'body', 'parameters' => [['type' => 'text', 'text' => 'Ana'], ['type' => 'text', 'text' => 'lunes']]],
                ['type' => 'button', 'sub_type' => 'url', 'index' => '0', 'parameters' => [['type' => 'text', 'text' => 'A-100']]],
            ],
        ]);
});

test('variables are validated against the template and Meta rules', function (array $values, string $field) {
    Queue::fake();

    ($this->send)(['parameters' => $values])->assertUnprocessable()->assertJsonValidationErrors($field);

    expect(Message::query()->count())->toBe(0);
    Queue::assertNothingPushed();
})->with([
    'missing variable' => [['header.1' => 'A-100', 'body.1' => 'Ana', 'button.0.1' => 'A-100'], 'parameters.body.2'],
    'line break' => [['header.1' => 'A-100', 'body.1' => "Ana\nLópez", 'body.2' => 'lunes', 'button.0.1' => 'A-100'], 'parameters.body.1'],
    'five spaces' => [['header.1' => 'A-100', 'body.1' => 'Ana     López', 'body.2' => 'lunes', 'button.0.1' => 'A-100'], 'parameters.body.1'],
    'header too long' => [['header.1' => str_repeat('x', 61), 'body.1' => 'Ana', 'body.2' => 'lunes', 'button.0.1' => 'A-100'], 'parameters.header.1'],
    'unknown variable' => [['header.1' => 'A-100', 'body.1' => 'Ana', 'body.2' => 'lunes', 'button.0.1' => 'A-100', 'body.3' => 'extra'], 'parameters.body.3'],
]);

test('templates not approved by Meta or of another connection are refused', function (Closure $change, string $field) {
    Queue::fake();
    $change($this->template);

    ($this->send)(['parameters' => $this->values])->assertUnprocessable()->assertJsonValidationErrors($field);

    Queue::assertNothingPushed();
})->with([
    'pending' => [fn ($template) => $template->forceFill(['status' => 'PENDING'])->save(), 'template_id'],
    'paused' => [fn ($template) => $template->forceFill(['status' => 'PAUSED'])->save(), 'template_id'],
    'deleted in Meta' => [fn ($template) => $template->forceFill(['deleted_at' => now()])->save(), 'template_id'],
    'other connection' => [fn ($template) => $template->forceFill(['connection_id' => Connection::factory()->create()->id])->save(), 'template_id'],
]);

test('a template is refused at once when the connection cannot send', function (array $state, string $reason) {
    Queue::fake();
    $this->connection->forceFill($state)->save();

    ($this->send)(['parameters' => $this->values])
        ->assertStatus(409)
        ->assertJsonPath('message', fn (string $message): bool => str_contains($message, $reason));

    expect(Message::query()->count())->toBe(0);
    Queue::assertNothingPushed();
})->with([
    'deactivated' => [['is_active' => false], 'está desactivada'],
    'deleted' => [['deleted_at' => now(), 'is_active' => false], 'fue eliminada'],
    'missing credentials' => [['access_token' => null], 'no tiene guardados sus identificadores'],
    'credentials rejected by Meta' => [['credentials_error' => 'Invalid OAuth access token.'], 'Meta rechazó las credenciales'],
]);

test('a template paused after being queued is not sent', function () {
    Queue::fake();
    Http::fake();

    ($this->send)(['parameters' => $this->values])->assertStatus(202);
    $message = Message::query()->sole();
    $this->template->forceFill(['status' => 'PAUSED'])->save();

    app()->call([new DeliverOutboundMessage($message->id), 'handle']);

    expect($message->refresh())->status->toBe(MessageStatus::Failed)->error_code->toBe('template_unavailable');
    Http::assertNothingSent();
});

test('a repeated submission stores the template message once', function () {
    Queue::fake();
    $reference = (string) Str::uuid();

    ($this->send)(['parameters' => $this->values, 'client_reference' => $reference])->assertStatus(202);
    ($this->send)(['parameters' => $this->values, 'client_reference' => $reference])->assertStatus(202);

    expect(Message::query()->count())->toBe(1);
    Queue::assertPushedTimes(DeliverOutboundMessage::class, 1);
});

test('an agent responsible for the conversation cannot list nor send templates without templates.send', function () {
    Queue::fake();
    $agent = User::factory()->agent()->inDepartments($this->department)->create();
    $this->conversation->forceFill(['assigned_user_id' => $agent->id])->save();

    $this->actingAs($agent)->getJson(route('conversations.templates.index', $this->conversation))->assertForbidden();
    ($this->send)(['parameters' => $this->values], $agent)->assertForbidden();

    expect(fn () => app(QueueTemplateMessage::class)->handle($this->conversation, $agent, $this->template, $this->values, (string) Str::uuid()))
        ->toThrow(AuthorizationException::class)
        ->and(Message::query()->count())->toBe(0);
    Queue::assertNothingPushed();
});

test('the conversation tells the composer whether the viewer may send templates', function () {
    $agent = User::factory()->agent()->inDepartments($this->department)->create();
    $agentsConversation = Conversation::factory()->inDepartment($this->department)->assignedTo($agent)->create(['connection_id' => $this->connection->id]);

    $this->actingAs($this->agent)
        ->get(route('conversations.index', ['c' => $this->conversation->id]))
        ->assertInertia(fn (Assert $page) => $page->where('conversation.can.send_templates', true));

    $this->actingAs($agent)
        ->get(route('conversations.index', ['c' => $agentsConversation->id]))
        ->assertInertia(fn (Assert $page) => $page
            ->where('conversation.can.reply', true)
            ->where('conversation.can.send_templates', false));
});

test('a template queued before templates.send was revoked is not delivered', function () {
    Queue::fake();
    Http::fake();

    ($this->send)(['parameters' => $this->values])->assertStatus(202);
    $message = Message::query()->sole();
    $role = $this->agent->roles()->firstOrFail();
    $role->revokePermissionTo(Permission::SendTemplates->value);

    app()->call([new DeliverOutboundMessage($message->id), 'handle']);

    expect($message->refresh())->status->toBe(MessageStatus::Failed)->error_code->toBe('sender_not_allowed');
    Http::assertNothingSent();
});

test('the integrations API offers no way to send a template', function () {
    $apiTemplateRoutes = collect(app('router')->getRoutes()->getRoutes())
        ->filter(fn ($route): bool => str_starts_with($route->uri(), 'api/v1') && str_contains($route->uri(), 'template'));

    expect($apiTemplateRoutes)->toBeEmpty();
});

test('only the responsible agent can list or send templates; others get nothing', function () {
    Queue::fake();
    $colleague = User::factory()->agent()->inDepartments($this->department)->create();
    $outsider = User::factory()->agent()->inDepartments(Department::factory()->create())->create();
    $supervisor = User::factory()->supervisor()->inDepartments($this->department)->create();

    $this->actingAs($outsider)->getJson(route('conversations.templates.index', $this->conversation))->assertNotFound();
    $this->actingAs($supervisor)->getJson(route('conversations.templates.index', $this->conversation))->assertForbidden();
    ($this->send)(['parameters' => $this->values], $outsider)->assertNotFound();
    ($this->send)(['parameters' => $this->values], $supervisor)->assertForbidden();
    ($this->send)(['parameters' => $this->values], $colleague)->assertNotFound();

    expect(Message::query()->count())->toBe(0);
});

test('an administrator holding templates.send who is not the responsible agent cannot list or send templates', function () {
    Queue::fake();
    $otherAdmin = User::factory()->administrator()->inDepartments($this->department)->create();
    $pending = Conversation::factory()->inDepartment($this->department)->create(['connection_id' => $this->connection->id, 'last_inbound_at' => now()->subDays(2)]);

    expect($otherAdmin->holdsPermission(Permission::SendTemplates))->toBeTrue();

    $this->actingAs($otherAdmin)->getJson(route('conversations.templates.index', $this->conversation))->assertForbidden();
    ($this->send)(['parameters' => $this->values], $otherAdmin)->assertForbidden();
    $this->actingAs($otherAdmin)->getJson(route('conversations.templates.index', $pending))->assertForbidden();
    $this->actingAs($otherAdmin)->postJson(route('conversations.templates.store', $pending), [
        'template_id' => $this->template->id,
        'client_reference' => (string) Str::uuid(),
        'parameters' => $this->values,
    ])->assertForbidden();

    expect(Message::query()->count())->toBe(0);
});

test('templates never open an expired Messenger or Instagram conversation', function (string $channel) {
    $meta = Connection::factory()->{$channel}()->create();
    $conversation = Conversation::factory()->inDepartment($this->department)->assignedTo($this->agent)->create([
        'connection_id' => $meta->id,
        'last_inbound_at' => now()->subDays(2),
    ]);
    // Even a template row stored for that connection cannot be sent.
    $forged = TemplateFixtures::stored($meta, ['name' => 'forged_one']);
    Http::fake();

    $this->actingAs($this->agent)->postJson(route('conversations.templates.store', $conversation), [
        'template_id' => $forged->id,
        'client_reference' => (string) Str::uuid(),
        'parameters' => $this->values,
    ])->assertForbidden();

    $this->actingAs($this->agent)->postJson(route('conversations.templates.store', $conversation), [
        'template_id' => $this->template->id,
        'client_reference' => (string) Str::uuid(),
        'parameters' => $this->values,
    ])->assertForbidden();
    $this->actingAs($this->agent)->getJson(route('conversations.templates.index', $conversation))->assertForbidden();

    $this->actingAs($this->agent)
        ->get(route('conversations.index', ['c' => $conversation->id]))
        ->assertInertia(fn (Assert $page) => $page->where('conversation.can.send_templates', false));

    expect(Message::query()->count())->toBe(0);
    Http::assertNothingSent();
})->with(['messenger', 'instagram']);

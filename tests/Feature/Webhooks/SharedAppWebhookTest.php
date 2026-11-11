<?php

use App\Actions\Conversations\ProcessInboundMessages;
use App\Actions\Messaging\ProcessWebhookPayload;
use App\Enums\ConnectionHealth;
use App\Enums\MessageDirection;
use App\Enums\MessageStatus;
use App\Enums\MessageTemplateStatus;
use App\Enums\WebhookEventStatus;
use App\Models\Connection;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use App\Models\WebhookEvent;
use App\Services\Meta\ConnectionDiagnostics;
use App\Services\Meta\ConnectionMonitor;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Templates\TemplateFixtures;
use Tests\Support\WhatsAppFixtures;

/*
| Several WhatsApp numbers under the same Meta app: Meta may deliver the
| events of every number to the webhook URL of one connection. Content of a
| number whose connection provably shares the app (same app secret) is
| processed for that connection; anything else is refused and reported on
| the receiving connection. Nothing reaches Meta.
*/

/**
 * Another number of the same Meta app as the receiving connection, in its
 * own business account unless one is given.
 *
 * @param  array<string, mixed>  $attributes
 */
function sharedAppSibling(Connection $receiving, array $attributes = [], string $state = 'verified'): Connection
{
    return Connection::factory()->{$state}()->create(['app_secret' => $receiving->app_secret, ...$attributes]);
}

/**
 * A "messages" webhook body for the connection's number, under its own
 * business account (the shared whatsappWebhook() envelope has a fixed one).
 *
 * @param  list<array<string, mixed>>  $messages
 * @return array<string, mixed>
 */
function sharedAppMessages(Connection $owner, array $messages): array
{
    $payload = whatsappWebhook($owner, $messages);
    $payload['entry'][0]['id'] = (string) $owner->business_account_id;

    return $payload;
}

/**
 * Warnings an administrator sees on the diagnostics of the connection.
 *
 * @return list<array{code: string, message: string, at: string|null}>
 */
function sharedAppWarnings(Connection $connection): array
{
    return app(ConnectionDiagnostics::class)->for($connection->refresh())['warnings'];
}

beforeEach(function () {
    Http::preventStrayRequests();
    $this->receiving = Connection::factory()->verified()->create(['name' => 'Ventas', 'business_account_id' => '102290129340398']);
});

test('a message for another number of the same app is stored on that number\'s connection', function () {
    $sibling = sharedAppSibling($this->receiving, ['name' => 'Soporte']);

    WhatsAppFixtures::post($this, $this->receiving, sharedAppMessages($sibling, [whatsappTextMessage('5215512345678', 'Hola soporte', id: 'wamid.sibling.1')]))->assertOk();

    $message = Message::query()->where('whatsapp_message_id', 'wamid.sibling.1')->sole();
    $event = WebhookEvent::query()->sole();

    expect($message->conversation->connection_id)->toBe($sibling->id)
        ->and(Conversation::query()->where('connection_id', $this->receiving->id)->exists())->toBeFalse()
        ->and($event->connection_id)->toBe($this->receiving->id)
        ->and($event->status)->toBe(WebhookEventStatus::Processed)
        ->and($event->summary)->toMatchArray(['messages' => 0, 'rerouted' => 1])
        // The evidence belongs to the connection that owns the number.
        ->and($sibling->refresh()->last_inbound_at)->not->toBeNull()
        ->and($sibling->last_webhook_at)->not->toBeNull()
        ->and($this->receiving->refresh()->last_inbound_at)->toBeNull()
        ->and(sharedAppWarnings($this->receiving))->toBe([]);
});

test('one delivery carrying messages of both numbers gives each connection its own', function () {
    $sibling = sharedAppSibling($this->receiving, ['business_account_id' => $this->receiving->business_account_id]);
    $payload = whatsappWebhook($this->receiving, [whatsappTextMessage('5215511111111', 'Para ventas', id: 'wamid.mixed.own')]);
    $payload['entry'][0]['changes'][] = whatsappWebhook($sibling, [whatsappTextMessage('5215522222222', 'Para soporte', id: 'wamid.mixed.sibling')])['entry'][0]['changes'][0];

    WhatsAppFixtures::post($this, $this->receiving, $payload)->assertOk();

    expect(Message::query()->where('whatsapp_message_id', 'wamid.mixed.own')->sole()->conversation->connection_id)->toBe($this->receiving->id)
        ->and(Message::query()->where('whatsapp_message_id', 'wamid.mixed.sibling')->sole()->conversation->connection_id)->toBe($sibling->id)
        ->and(WebhookEvent::query()->sole()->summary)->toMatchArray(['messages' => 1, 'rerouted' => 1]);
});

test('a status callback for another number of the same app updates that connection\'s message', function () {
    $sibling = sharedAppSibling($this->receiving);
    $message = WhatsAppFixtures::outbound(Conversation::factory()->create(['connection_id' => $sibling->id]), [
        'status' => 'accepted', 'whatsapp_message_id' => 'wamid.out.sibling', 'accepted_at' => now(),
    ]);

    WhatsAppFixtures::post($this, $this->receiving, WhatsAppFixtures::statusWebhook($sibling, [
        WhatsAppFixtures::status('wamid.out.sibling', 'delivered', now()->subMinute()->toImmutable()),
    ]))->assertOk();

    expect($message->refresh()->status)->toBe(MessageStatus::Delivered)
        ->and(WebhookEvent::query()->sole()->summary)->toMatchArray(['statuses' => 0, 'rerouted' => 1]);
});

test('a template update of another business account of the same app reaches its templates', function () {
    $sibling = sharedAppSibling($this->receiving);
    $template = TemplateFixtures::stored($sibling, ['meta_template_id' => '779']);

    WhatsAppFixtures::post($this, $this->receiving, WhatsAppFixtures::envelope($sibling, 'message_template_status_update', [
        'event' => 'REJECTED', 'message_template_id' => 779, 'reason' => 'INCORRECT_CATEGORY',
    ]))->assertOk();

    expect($template->refresh()->status)->toBe(MessageTemplateStatus::Rejected)
        ->and(WebhookEvent::query()->sole()->summary)->toMatchArray(['templates' => 0, 'rerouted' => 1])
        ->and(sharedAppWarnings($this->receiving))->toBe([]);
});

test('an account notice reaches every connection of that account under the same app', function () {
    $sibling = sharedAppSibling($this->receiving, ['business_account_id' => $this->receiving->business_account_id]);

    WhatsAppFixtures::post($this, $this->receiving, WhatsAppFixtures::envelope($this->receiving, 'account_update', ['event' => 'ACCOUNT_VIOLATION']))->assertOk();

    expect($this->receiving->refresh()->last_error_message)->toContain('ACCOUNT_VIOLATION')
        ->and($sibling->refresh()->last_error_message)->toContain('ACCOUNT_VIOLATION');
});

test('the same delivery arriving on both webhook URLs stores the message once', function () {
    $sibling = sharedAppSibling($this->receiving);
    $payload = sharedAppMessages($sibling, [whatsappTextMessage('5215512345678', 'Hola', id: 'wamid.both-urls')]);

    WhatsAppFixtures::post($this, $this->receiving, $payload)->assertOk();
    WhatsAppFixtures::post($this, $sibling, $payload)->assertOk();
    WhatsAppFixtures::post($this, $this->receiving, $payload)->assertOk();

    expect(Message::query()->where('whatsapp_message_id', 'wamid.both-urls')->count())->toBe(1)
        ->and(Message::query()->count())->toBe(1)
        ->and(Conversation::query()->count())->toBe(1)
        ->and(WebhookEvent::query()->count())->toBe(2);
});

test('a number registered with a different app secret is refused and reported without secrets or content', function () {
    $other = Connection::factory()->verified()->create(['name' => 'Otra app']);

    WhatsAppFixtures::post($this, $this->receiving, sharedAppMessages($other, [whatsappTextMessage('5215512345678', 'Texto privado del cliente', id: 'wamid.other-app')]))->assertOk();

    $warnings = sharedAppWarnings($this->receiving);
    $stored = json_encode([$warnings, $this->receiving->provider_signals]);

    expect(Message::query()->count())->toBe(0)
        ->and(Conversation::query()->count())->toBe(0)
        ->and($other->refresh()->last_inbound_at)->toBeNull()
        ->and(WebhookEvent::query()->sole()->summary)->toMatchArray(['messages' => 0, 'refused' => 1])
        ->and($warnings)->toHaveCount(1)
        ->and($warnings[0]['code'])->toBe('unattributed_other_app')
        ->and($warnings[0]['message'])->toContain("ID {$other->phone_number_id}")
        ->and($stored)->not->toContain((string) $other->app_secret)
        ->and($stored)->not->toContain((string) $this->receiving->app_secret)
        ->and($stored)->not->toContain('Texto privado')
        ->and($stored)->not->toContain('5215512345678')
        ->and(sharedAppWarnings($other))->toBe([]);
});

test('a number that matches no connection is refused and reported once however often it arrives', function () {
    $ghost = Connection::factory()->make(['phone_number_id' => '199999999999999', 'business_account_id' => $this->receiving->business_account_id]);

    WhatsAppFixtures::post($this, $this->receiving, whatsappWebhook($ghost, [whatsappTextMessage('5215512345678', 'Hola', id: 'wamid.ghost.1')]))->assertOk();
    WhatsAppFixtures::post($this, $this->receiving, whatsappWebhook($ghost, [whatsappTextMessage('5215512345678', 'Hola', id: 'wamid.ghost.2')]))->assertOk();

    $warnings = sharedAppWarnings($this->receiving);

    expect(Message::query()->count())->toBe(0)
        ->and($warnings)->toHaveCount(1)
        ->and($warnings[0]['code'])->toBe('unattributed_unknown')
        ->and($warnings[0]['message'])->toContain('otro número (ID 199999999999999) que no está registrado aquí');
});

test('the warning about an unregistered number disappears once it stops arriving', function () {
    $ghost = Connection::factory()->make(['phone_number_id' => '199999999999999']);
    WhatsAppFixtures::post($this, $this->receiving, whatsappWebhook($ghost, [whatsappTextMessage('5215512345678', id: 'wamid.ghost.old')]))->assertOk();

    $this->travel(config('whatsapp.monitoring.warning_days') + 1)->days();

    expect(sharedAppWarnings($this->receiving))->toBe([]);
});

test('a paused sibling still stores the customer message but sends nothing', function () {
    $transport = WhatsAppFixtures::transport();
    $sibling = sharedAppSibling($this->receiving, ['is_active' => false, 'welcome_enabled' => true, 'welcome_message' => 'Bienvenido']);

    WhatsAppFixtures::post($this, $this->receiving, sharedAppMessages($sibling, [whatsappTextMessage('5215512345678', 'Hola', id: 'wamid.paused.sibling')]))->assertOk();

    expect(Message::query()->where('whatsapp_message_id', 'wamid.paused.sibling')->sole()->conversation->connection_id)->toBe($sibling->id)
        ->and(Message::query()->where('direction', MessageDirection::Outbound)->count())->toBe(0)
        ->and($transport->sent)->toBe([]);
});

test('a deleted sibling stores nothing and the receiving connection says why', function () {
    $sibling = sharedAppSibling($this->receiving);
    $sibling->forceFill(['deleted_at' => now(), 'is_active' => false])->save();

    WhatsAppFixtures::post($this, $this->receiving, sharedAppMessages($sibling, [whatsappTextMessage('5215512345678', 'Hola', id: 'wamid.deleted.sibling')]))->assertOk();

    expect(Message::query()->count())->toBe(0)
        ->and(Conversation::query()->count())->toBe(0)
        ->and(sharedAppWarnings($this->receiving)[0]['code'])->toBe('unattributed_deleted');
});

test('a sibling without app secret, or with a contradicting Meta App ID, is not trusted', function (array $receiving, array $sibling) {
    $this->receiving->forceFill($receiving)->save();
    $other = sharedAppSibling($this->receiving, $sibling);

    WhatsAppFixtures::post($this, $this->receiving, sharedAppMessages($other, [whatsappTextMessage('5215512345678', 'Hola', id: 'wamid.untrusted')]))->assertOk();

    expect(Message::query()->count())->toBe(0)
        ->and(sharedAppWarnings($this->receiving)[0]['code'])->toBe('unattributed_other_app');
})->with([
    'no secret on the sibling' => [[], ['app_secret' => null]],
    'different Meta App IDs' => [['meta_app_id' => '111111'], ['meta_app_id' => '222222']],
]);

test('content of the number under a business account other than the one saved on its connection is refused', function () {
    $sibling = sharedAppSibling($this->receiving);

    WhatsAppFixtures::post($this, $this->receiving, whatsappWebhook($sibling, [whatsappTextMessage('5215512345678', 'Hola', id: 'wamid.wrong-account')]))->assertOk();

    expect(Message::query()->count())->toBe(0)
        ->and(sharedAppWarnings($this->receiving)[0]['code'])->toBe('unattributed_other_account');
});

test('a delivery that only concerns the receiving connection is processed exactly as before', function () {
    sharedAppSibling($this->receiving);

    WhatsAppFixtures::post($this, $this->receiving, whatsappWebhook($this->receiving, [whatsappTextMessage('5215512345678', 'Hola', id: 'wamid.own')]))->assertOk();

    expect(WebhookEvent::query()->sole()->summary)->toBe(['messages' => 1, 'statuses' => 0, 'templates' => 0, 'signals' => 0, 'ignored' => 0])
        ->and(Message::query()->where('whatsapp_message_id', 'wamid.own')->sole()->conversation->connection_id)->toBe($this->receiving->id)
        ->and($this->receiving->refresh()->provider_signals)->toBeNull();
});

test('the warning is shown on the connection page to people who may see connections', function () {
    $ghost = Connection::factory()->make(['phone_number_id' => '199999999999999']);
    WhatsAppFixtures::post($this, $this->receiving, whatsappWebhook($ghost, [whatsappTextMessage('5215512345678', id: 'wamid.ghost.page')]))->assertOk();

    $this->actingAs(User::factory()->administrator()->create())
        ->get(route('connections.show', $this->receiving))
        ->assertInertia(fn (Assert $page) => $page
            ->where('connection.monitoring.warnings.0.code', 'unattributed_unknown')
            ->where('diagnostics.warnings.0.code', 'unattributed_unknown')
            ->where('diagnostics.signals', []));
});

/**
 * Make the storage of the customer messages of one connection fail until
 * the returned switch is turned off; every other connection is processed
 * by the real pipeline.
 *
 * @return object{failing: bool}
 */
function sharedAppBrokenInbound(Connection $broken): object
{
    $switch = new class
    {
        public bool $failing = true;
    };
    $real = app(ProcessInboundMessages::class);
    $double = Mockery::mock(ProcessInboundMessages::class);
    $double->shouldReceive('handle')->andReturnUsing(function (array $payload, ?Connection $through = null) use ($real, $broken, $switch): array {
        if ($switch->failing && $through?->id === $broken->id) {
            throw new RuntimeException('The messages of this connection could not be stored.');
        }

        return $real->handle($payload, $through);
    });
    app()->instance(ProcessInboundMessages::class, $double);

    return $switch;
}

test('a failure applying the changes of one sibling never skips the other siblings nor the refusals, and the retry repeats nothing', function () {
    $broken = sharedAppSibling($this->receiving, ['name' => 'Soporte', 'business_account_id' => $this->receiving->business_account_id]);
    $healthy = sharedAppSibling($this->receiving, ['name' => 'Cobranza', 'business_account_id' => $this->receiving->business_account_id]);
    $ghost = Connection::factory()->make(['phone_number_id' => '199999999999999']);
    $change = fn (Connection $owner, string $wamid): array => whatsappWebhook($owner, [whatsappTextMessage('5215512345678', 'Hola', id: $wamid)])['entry'][0]['changes'][0];
    $payload = whatsappWebhook($this->receiving, [whatsappTextMessage('5215511111111', 'Para ventas', id: 'wamid.isolated.own')]);
    // The broken sibling comes first: before the fix it took the rest of the delivery down with it.
    $payload['entry'][0]['changes'][] = $change($broken, 'wamid.isolated.broken');
    $payload['entry'][0]['changes'][] = $change($healthy, 'wamid.isolated.healthy');
    $payload['entry'][0]['changes'][] = $change($ghost, 'wamid.isolated.ghost');
    // A change-level error for the healthy sibling, dated by its entry.
    $payload['entry'][0]['time'] = now()->subMinute()->getTimestamp();
    $payload['entry'][0]['changes'][] = [
        'field' => 'messages',
        'value' => ['messaging_product' => 'whatsapp', 'metadata' => ['phone_number_id' => $healthy->phone_number_id], 'errors' => [['code' => 131000, 'title' => 'Something went wrong']]],
    ];
    $switch = sharedAppBrokenInbound($broken);

    // The job still fails, so the queue retries it...
    expect(fn () => app(ProcessWebhookPayload::class)->handle($this->receiving, $payload))->toThrow(RuntimeException::class, 'could not be stored');

    // ...but everything that could be applied was applied.
    expect(Message::query()->where('whatsapp_message_id', 'wamid.isolated.own')->sole()->conversation->connection_id)->toBe($this->receiving->id)
        ->and(Message::query()->where('whatsapp_message_id', 'wamid.isolated.healthy')->sole()->conversation->connection_id)->toBe($healthy->id)
        ->and(Message::query()->where('whatsapp_message_id', 'wamid.isolated.broken')->exists())->toBeFalse()
        ->and(sharedAppWarnings($this->receiving))->toHaveCount(1)
        // The change-level error of the healthy sibling was recorded too.
        ->and($healthy->refresh()->last_error_at)->not->toBeNull();

    $errorAt = $healthy->last_error_at;
    $unattributed = $this->receiving->refresh()->provider_signals[ConnectionMonitor::UNATTRIBUTED_SIGNAL];

    // The channel of the healthy sibling works again before the retry runs.
    $this->travel(30)->seconds();
    app(ConnectionMonitor::class)->outboundAccepted($healthy);
    expect($healthy->refresh()->health())->not->toBe(ConnectionHealth::Error);

    $this->travel(30)->seconds();
    $switch->failing = false;
    $summary = app(ProcessWebhookPayload::class)->handle(Connection::query()->findOrFail($this->receiving->id), $payload);

    expect($summary)->toMatchArray(['refused' => 1])
        ->and(Message::query()->where('whatsapp_message_id', 'wamid.isolated.broken')->sole()->conversation->connection_id)->toBe($broken->id)
        ->and(Message::query()->count())->toBe(3)
        // The retry is not new evidence: same error, same instant, still superseded.
        ->and($healthy->refresh()->last_error_at->equalTo($errorAt))->toBeTrue()
        ->and($healthy->health())->not->toBe(ConnectionHealth::Error)
        ->and($this->receiving->refresh()->provider_signals[ConnectionMonitor::UNATTRIBUTED_SIGNAL]['value'])->toHaveCount(1)
        ->and($this->receiving->provider_signals[ConnectionMonitor::UNATTRIBUTED_SIGNAL]['value']["phone_number:{$ghost->phone_number_id}"]['first_at'])
        ->toBe($unattributed['value']["phone_number:{$ghost->phone_number_id}"]['first_at']);
});

test('a failure applying the receiving connection\'s own changes still hands the siblings theirs', function () {
    $sibling = sharedAppSibling($this->receiving, ['business_account_id' => $this->receiving->business_account_id]);
    $payload = whatsappWebhook($this->receiving, [whatsappTextMessage('5215511111111', 'Para ventas', id: 'wamid.own.broken')]);
    $payload['entry'][0]['changes'][] = whatsappWebhook($sibling, [whatsappTextMessage('5215522222222', 'Para soporte', id: 'wamid.own.sibling')])['entry'][0]['changes'][0];
    sharedAppBrokenInbound($this->receiving);

    expect(fn () => app(ProcessWebhookPayload::class)->handle($this->receiving, $payload))->toThrow(RuntimeException::class)
        ->and(Message::query()->where('whatsapp_message_id', 'wamid.own.sibling')->sole()->conversation->connection_id)->toBe($sibling->id);
});

test('a webhook field named like an internal note of the platform never reaches the connection signals', function (string $field) {
    $monitor = app(ConnectionMonitor::class);
    $monitor->checked($this->receiving, 'verified');
    $monitor->unattributed($this->receiving, 'phone_number', '199999999999999', 'unknown');
    $this->receiving->forceFill(['provider_signals' => [...$this->receiving->refresh()->provider_signals, 'meta_authorization' => ['at' => now()->toIso8601String(), 'value' => ['user_id' => '1']], 'token_refresh' => ['at' => now()->toIso8601String(), 'value' => []]]])->save();
    $before = $this->receiving->refresh()->provider_signals;

    WhatsAppFixtures::post($this, $this->receiving, WhatsAppFixtures::envelope($this->receiving, $field, ['outcome' => 'forged', 'user_id' => '999', 'event' => 'FLAGGED']))->assertOk();

    expect($this->receiving->refresh()->provider_signals)->toBe($before)
        ->and(WebhookEvent::query()->sole()->summary)->toMatchArray(['signals' => 0, 'ignored' => 1]);
})->with(['platform.check', 'platform.unattributed', 'platform.error', 'Platform.Check', ' platform.anything', 'meta_authorization', 'token_refresh']);

test('an ordinary notice of Meta is still kept as a signal', function () {
    WhatsAppFixtures::post($this, $this->receiving, WhatsAppFixtures::envelope($this->receiving, 'business_capability_update', ['max_daily_conversation_per_phone' => 1000]))->assertOk();

    expect($this->receiving->refresh()->provider_signals['business_capability_update']['value'])->toBe(['max_daily_conversation_per_phone' => 1000]);
});

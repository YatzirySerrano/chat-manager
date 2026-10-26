<?php

use App\Actions\Messaging\ProcessWebhookPayload;
use App\Jobs\ProcessWhatsAppWebhook;
use App\Models\Connection;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\WebhookEvent;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Tests\Support\WhatsAppFixtures;

/*
| WhatsApp Groups API content is not supported: group messages, their
| statuses and group_* change fields must never become 1:1 conversations or
| contacts. Payload shapes follow Meta's group webhooks reference; all
| phone numbers and group ids are synthetic.
*/

const GROUP_ID = 'HBgLMTY1MDM4Nzk0MzkVAgASGBQzQTRBNjU5OUFFRTAzODEwMTQ0RgA';
const GROUP_PARTICIPANT = '5215598765432';

beforeEach(function () {
    Http::preventStrayRequests();
    $this->connection = Connection::factory()->verified()->create(['business_account_id' => '102290129340398']);
});

/**
 * @return array<string, mixed>
 */
function groupTextMessage(string $id = 'wamid.group.1'): array
{
    return whatsappTextMessage(GROUP_PARTICIPANT, 'Mensaje secreto del grupo', id: $id, extra: ['group_id' => GROUP_ID]);
}

/**
 * A group_* change as Meta documents it: value.groups[] with group_id and type.
 *
 * @param  array<string, mixed>  $group
 * @return array<string, mixed>
 */
function groupChange(Connection $connection, string $field, array $group): array
{
    return WhatsAppFixtures::envelope($connection, $field, [
        'messaging_product' => 'whatsapp',
        'metadata' => ['display_phone_number' => '15550783881', 'phone_number_id' => $connection->phone_number_id],
        'groups' => [['timestamp' => (string) now()->getTimestamp(), 'group_id' => GROUP_ID, ...$group]],
    ]);
}

test('a group message is acknowledged but never becomes a conversation, contact or job', function () {
    Queue::fake();
    Log::spy();

    WhatsAppFixtures::post($this, $this->connection, whatsappWebhook($this->connection, [groupTextMessage()], [GROUP_PARTICIPANT => 'Participante']))
        ->assertOk()
        ->assertContent('');

    expect(WebhookEvent::query()->count())->toBe(0)
        ->and(Conversation::query()->count())->toBe(0)
        ->and(Contact::query()->count())->toBe(0)
        ->and(Message::query()->count())->toBe(0);
    Queue::assertNothingPushed();

    Log::shouldHaveReceived('notice')->once()->withArgs(function (string $message, array $context): bool {
        $logged = json_encode($context);

        return str_contains($message, 'group')
            && $context['messages'] === 1
            && $context['group_refs'] === [substr(hash_hmac('sha256', GROUP_ID, (string) config('app.key')), 0, 12)]
            && ! str_contains($logged, GROUP_PARTICIPANT)
            && ! str_contains($logged, GROUP_ID)
            && ! str_contains($logged, 'secreto');
    });
});

test('group lifecycle, participants, settings and status webhooks are acknowledged and ignored', function (string $field, array $group) {
    Queue::fake();

    WhatsAppFixtures::post($this, $this->connection, groupChange($this->connection, $field, $group))->assertOk();

    expect(WebhookEvent::query()->count())->toBe(0)
        ->and(Contact::query()->count())->toBe(0)
        ->and($this->connection->refresh()->provider_signals)->toBeNull();
    Queue::assertNothingPushed();
})->with([
    'lifecycle' => ['group_lifecycle_update', ['type' => 'group_create', 'subject' => 'Clientes VIP', 'invite_link' => 'https://chat.whatsapp.com/EXAMPLE']],
    'participants' => ['group_participants_update', ['type' => 'group_participants_add', 'added_participants' => [['wa_id' => GROUP_PARTICIPANT]]]],
    'settings' => ['group_settings_update', ['type' => 'group_settings_update', 'group_subject' => ['text' => 'Nuevo asunto']]],
    'status' => ['group_status_update', ['type' => 'group_suspend']],
]);

test('in a mixed delivery only the 1:1 content is stored and processed', function () {
    $direct = whatsappTextMessage('5215512345678', 'Hola, quiero información', id: 'wamid.direct.1');
    $payload = whatsappWebhook($this->connection, [groupTextMessage(), $direct], [GROUP_PARTICIPANT => 'Participante', '5215512345678' => 'Laura']);
    $payload['entry'][0]['changes'][0]['value']['statuses'] = [
        WhatsAppFixtures::status('wamid.group.sent', 'sent', now()->toImmutable(), ['recipient_id' => GROUP_ID, 'recipient_type' => 'group']),
    ];
    $payload['entry'][0]['changes'][] = groupChange($this->connection, 'group_participants_update', ['type' => 'group_participants_add'])['entry'][0]['changes'][0];

    WhatsAppFixtures::post($this, $this->connection, $payload)->assertOk();

    $event = WebhookEvent::query()->sole();
    expect($event->fields)->toBe('messages')
        ->and($event->summary)->toMatchArray(['messages' => 1, 'statuses' => 0, 'signals' => 0])
        ->and($event->payload)->not->toContain(GROUP_PARTICIPANT)
        ->and($event->payload)->not->toContain(GROUP_ID)
        ->and($event->payload)->not->toContain('secreto')
        ->and(Message::query()->sole()->whatsapp_message_id)->toBe('wamid.direct.1')
        ->and(Conversation::query()->count())->toBe(1)
        ->and(Contact::query()->sole()->profile_name)->toBe('Laura');
});

test('a group message already stored is skipped when its delivery is processed again', function () {
    $payload = whatsappWebhook($this->connection, [groupTextMessage('wamid.group.replay')], [GROUP_PARTICIPANT => 'Participante']);
    $payload['entry'][0]['changes'][] = groupChange($this->connection, 'group_lifecycle_update', ['type' => 'group_create'])['entry'][0]['changes'][0];

    $summary = app(ProcessWebhookPayload::class)->handle($this->connection, $payload);

    expect($summary)->toMatchArray(['messages' => 0, 'signals' => 0])
        ->and(Conversation::query()->count())->toBe(0)
        ->and(Contact::query()->count())->toBe(0)
        ->and($this->connection->refresh()->provider_signals)->toBeNull();
});

test('a 1:1 delivery without group markers is stored exactly as received', function () {
    Queue::fake();
    $payload = whatsappWebhook($this->connection, [whatsappTextMessage('5215512345678', 'Hola', id: 'wamid.direct.only')]);

    WhatsAppFixtures::post($this, $this->connection, $payload)->assertOk();

    expect(WebhookEvent::query()->sole()->payload)->toBe(json_encode($payload));
    Queue::assertPushed(ProcessWhatsAppWebhook::class, 1);
});

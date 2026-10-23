<?php

use App\Enums\ConversationStatus;
use App\Enums\MessageStatus;
use App\Jobs\DeliverOutboundMessage;
use App\Models\Connection;
use App\Models\Contact;
use App\Models\ContactIdentity;
use App\Models\Conversation;
use App\Models\Department;
use App\Models\Message;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Support\WhatsAppFixtures;

/*
| The "to" of every Cloud API send. Meta reports Mexican mobile numbers with
| the legacy "1" (521 + 10 digits) as WhatsApp ID, while the allowed list of a
| test number only accepts the current form (52 + 10 digits) and answers
| 131030 otherwise; Meta delivers either form to the same account in
| production. Every Graph call is faked; the exact body and endpoint are
| asserted.
*/

const RECIPIENT_TOKEN = 'EAAGrecipientTestToken00000000000000000000000000000000';

beforeEach(function () {
    Http::preventStrayRequests();
    config(['whatsapp.transport' => 'meta', 'services.meta.graph_version' => 'v26.0', 'whatsapp.delivery.max_attempts' => 2]);
    $this->travelTo(CarbonImmutable::parse('2026-10-22 18:00:00', 'UTC'));

    $this->department = Department::factory()->create();
    $this->agent = User::factory()->agent()->inDepartments($this->department)->create();
    $this->connection = Connection::factory()->verified()->create(['phone_number_id' => '140000000000033', 'access_token' => RECIPIENT_TOKEN]);
    $this->messagesUrl = 'https://graph.facebook.com/v26.0/140000000000033/messages';
    $this->deliver = fn (Message $message) => app()->call([new DeliverOutboundMessage($message->id), 'handle']);
    $this->attend = function (Conversation $conversation): Conversation {
        $conversation->forceFill([
            'status' => ConversationStatus::Open,
            'department_id' => $this->department->id,
            'assigned_user_id' => $this->agent->id,
            'last_inbound_at' => now()->subMinutes(5),
        ])->save();

        return $conversation;
    };
    $this->conversationWith = fn (array $identities, ?string $phone = '+527771234567', ?Connection $connection = null): Conversation => tap(
        ($this->attend)(Conversation::factory()->create([
            'connection_id' => ($connection ?? $this->connection)->id,
            'contact_id' => Contact::factory()->create(['phone_e164' => $phone])->id,
        ])),
        fn (Conversation $conversation) => collect($identities)->each(fn (array $identity) => ContactIdentity::factory()->create([
            'contact_id' => $conversation->contact_id,
            ...$identity,
        ])),
    );
});

function sentByMeta(string $wamid, string $input, string $waId): array
{
    return ['messaging_product' => 'whatsapp', 'contacts' => [['input' => $input, 'wa_id' => $waId]], 'messages' => [['id' => $wamid]]];
}

test('a reply to a Mexican customer who wrote in goes to 52 + 10 digits on the connection that received it', function () {
    Http::fake([$this->messagesUrl => Http::response(sentByMeta('wamid.mx.1', '527771234567', '5217771234567'))]);
    $receipt = receiveWhatsapp($this->connection, '5217771234567', 'Hola, quiero información');
    $conversation = ($this->attend)($receipt->conversation);
    $message = WhatsAppFixtures::outbound($conversation, ['body' => 'Con gusto', 'sender_user_id' => $this->agent->id]);

    ($this->deliver)($message);

    Http::assertSentCount(1);
    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
        && $request->url() === $this->messagesUrl
        && $request->hasHeader('Authorization', 'Bearer '.RECIPIENT_TOKEN)
        && $request['to'] === '527771234567'
        && $request['recipient_type'] === 'individual'
        && $request['text'] === ['body' => 'Con gusto', 'preview_url' => false]);

    // The WhatsApp ID reported by Meta is preserved; only the "to" differs.
    expect($receipt->registration->identity->refresh()->external_id)->toBe('5217771234567')
        ->and($receipt->registration->contact->refresh()->phone_e164)->toBe('+527771234567')
        ->and($message->refresh())
        ->status->toBe(MessageStatus::Accepted)
        ->whatsapp_message_id->toBe('wamid.mx.1')
        ->delivery_attempts->toBe(1)
        ->error_code->toBeNull();
});

test('the recipient is derived from the WhatsApp identity, not from the editable phone', function (string $whatsAppId, ?string $phone, string $to) {
    Http::fake([$this->messagesUrl => Http::response(sentByMeta('wamid.form', $to, $whatsAppId))]);
    $conversation = ($this->conversationWith)([['external_id' => $whatsAppId, 'last_seen_at' => now()]], $phone);

    ($this->deliver)(WhatsAppFixtures::outbound($conversation));

    Http::assertSent(fn (Request $request): bool => $request['to'] === $to);
})->with([
    // Edited by hand to another customer's number: the conversation's identity wins.
    'Mexican mobile with an edited phone' => ['5217771234567', '+525598765432', '527771234567'],
    'identity already without the legacy 1' => ['527771234567', '+527771234567', '527771234567'],
    'identity and no phone' => ['5217771234567', null, '527771234567'],
]);

test('WhatsApp IDs of other countries are sent exactly as Meta reported them', function (string $whatsAppId) {
    Http::fake([$this->messagesUrl => Http::response(sentByMeta('wamid.intl', $whatsAppId, $whatsAppId))]);
    $conversation = ($this->conversationWith)([['external_id' => $whatsAppId, 'last_seen_at' => now()]], null);

    ($this->deliver)(WhatsAppFixtures::outbound($conversation));

    Http::assertSent(fn (Request $request): bool => $request['to'] === $whatsAppId);
})->with([
    'United States' => ['14155552671'],
    'Argentina, mobile 9 kept' => ['5491123456789'],
    'Brazil' => ['5511987654321'],
    'Spain' => ['34612345678'],
    'Colombia' => ['573001234567'],
    'not a valid Mexican number after 521' => ['5210123456789'],
    'not numeric' => ['wa.user.7f3a9c'],
]);

test('with several WhatsApp identities the most recently seen one is used, newest first on a tie', function () {
    Http::fake([$this->messagesUrl => Http::sequence()
        ->push(sentByMeta('wamid.multi', '527779990000', '5217779990000'))
        ->push(sentByMeta('wamid.tie', '527775550002', '5217775550002'))]);
    $conversation = ($this->conversationWith)([
        ['external_id' => '5217771112222', 'last_seen_at' => now()->subDays(3)],
        ['external_id' => '5217779990000', 'last_seen_at' => now()->subMinute()],
        ['external_id' => '5217773334444', 'last_seen_at' => null],
    ]);
    $tied = ($this->conversationWith)([
        ['external_id' => '5217775550001', 'last_seen_at' => now()->subHour()],
        ['external_id' => '5217775550002', 'last_seen_at' => now()->subHour()],
    ]);

    ($this->deliver)(WhatsAppFixtures::outbound($conversation));
    ($this->deliver)(WhatsAppFixtures::outbound($tied));

    Http::assertSent(fn (Request $request): bool => $request['to'] === '527779990000');
    Http::assertSent(fn (Request $request): bool => $request['to'] === '527775550002');
    Http::assertNotSent(fn (Request $request): bool => in_array($request['to'], ['527771112222', '527773334444', '527775550001'], true));
});

test('each conversation is answered to its own contact through its own connection', function () {
    $other = Connection::factory()->verified()->create(['phone_number_id' => '140000000000044', 'access_token' => 'EAAGotherConnectionToken000000000000000000000000000000']);
    $otherUrl = 'https://graph.facebook.com/v26.0/140000000000044/messages';
    Http::fake([
        $this->messagesUrl => Http::response(sentByMeta('wamid.first', '527771234567', '5217771234567')),
        $otherUrl => Http::response(sentByMeta('wamid.second', '528181234567', '5218181234567')),
    ]);
    $first = ($this->conversationWith)([['external_id' => '5217771234567', 'last_seen_at' => now()]]);
    $second = ($this->conversationWith)([['external_id' => '5218181234567', 'last_seen_at' => now()]], '+528181234567', $other);

    ($this->deliver)(WhatsAppFixtures::outbound($first));
    ($this->deliver)(WhatsAppFixtures::outbound($second));

    Http::assertSentCount(2);
    Http::assertSent(fn (Request $request): bool => $request->url() === $this->messagesUrl
        && $request->hasHeader('Authorization', 'Bearer '.RECIPIENT_TOKEN)
        && $request['to'] === '527771234567');
    Http::assertSent(fn (Request $request): bool => $request->url() === $otherUrl
        && ! $request->hasHeader('Authorization', 'Bearer '.RECIPIENT_TOKEN)
        && $request['to'] === '528181234567');
});

test('a contact without a WhatsApp identity is reached at the digits of its phone', function () {
    Http::fake([$this->messagesUrl => Http::response(sentByMeta('wamid.phone', '527771234567', '5217771234567'))]);
    $conversation = ($this->conversationWith)([], '+527771234567');

    ($this->deliver)(WhatsAppFixtures::outbound($conversation));

    Http::assertSent(fn (Request $request): bool => $request['to'] === '527771234567');
});

test('a contact without identity nor phone fails explicitly without calling Meta', function () {
    Http::fake();
    $conversation = ($this->conversationWith)([], null);
    $message = WhatsAppFixtures::outbound($conversation);

    ($this->deliver)($message);

    expect($message->refresh())
        ->status->toBe(MessageStatus::Failed)
        ->error_message->toContain('no tiene un número de WhatsApp');
    Http::assertNothingSent();
});

test('a 131030 refusal is final, keeps the identity and is not retried automatically', function () {
    Http::fake([$this->messagesUrl => Http::response(['error' => ['message' => '(#131030) Recipient phone number not in allowed list', 'code' => 131030]], 400)]);
    $conversation = ($this->conversationWith)([['external_id' => '5217771234567', 'last_seen_at' => now()]]);
    $message = WhatsAppFixtures::outbound($conversation);

    ($this->deliver)($message);
    ($this->deliver)($message);

    expect($message->refresh())
        ->status->toBe(MessageStatus::Failed)
        ->error_code->toBe('meta_131030')
        ->error_message->toContain('lista de números permitidos')
        ->delivery_attempts->toBe(1)
        ->and(ContactIdentity::query()->where('contact_id', $conversation->contact_id)->sole()->external_id)->toBe('5217771234567');
    Http::assertSentCount(1);
    Http::assertSent(fn (Request $request): bool => $request['to'] === '527771234567');
});

test('a manual retry of a message refused with 131030 is sent once to the corrected number', function () {
    Http::fake([$this->messagesUrl => Http::response(sentByMeta('wamid.retried', '527771234567', '5217771234567'))]);
    $conversation = ($this->conversationWith)([['external_id' => '5217771234567', 'last_seen_at' => now()]]);
    $failed = WhatsAppFixtures::outbound($conversation, [
        'status' => 'failed',
        'error_code' => 'meta_131030',
        'error_message' => 'El destinatario no está en la lista de números permitidos del número de prueba de Meta. (código 131030)',
        'failed_at' => now()->subMinute(),
        'sender_user_id' => $this->agent->id,
        'delivery_attempts' => 1,
    ]);

    $this->actingAs($this->agent)
        ->postJson(route('conversations.messages.retry', ['conversation' => $conversation, 'message' => $failed]))
        ->assertAccepted();
    ($this->deliver)($failed);

    expect($failed->refresh())
        ->status->toBe(MessageStatus::Accepted)
        ->whatsapp_message_id->toBe('wamid.retried')
        ->error_code->toBeNull()
        ->and(Message::query()->where('conversation_id', $conversation->id)->count())->toBe(1);
    Http::assertSentCount(1);
    Http::assertSent(fn (Request $request): bool => $request['to'] === '527771234567');
});

test('a controlled pilot accepts a Mexican recipient listed in either form', function (string $listed, array $identities) {
    config(['whatsapp.pilot.recipients' => [$listed]]);
    Http::fake([$this->messagesUrl => Http::response(sentByMeta('wamid.pilot', '527771234567', '5217771234567'))]);
    $message = WhatsAppFixtures::outbound(($this->conversationWith)($identities, '+527771234567'));

    ($this->deliver)($message);

    expect($message->refresh()->status)->toBe(MessageStatus::Accepted);
    Http::assertSent(fn (Request $request): bool => $request['to'] === '527771234567');
})->with([
    'as Meta reports it' => ['5217771234567', [['external_id' => '5217771234567', 'last_seen_at' => now()]]],
    'as written in Meta for Developers' => ['527771234567', [['external_id' => '5217771234567', 'last_seen_at' => now()]]],
    'as Meta reports it, contact reached by phone' => ['5217771234567', []],
]);

test('a controlled pilot still refuses a recipient outside the list', function () {
    config(['whatsapp.pilot.recipients' => ['5217770000000']]);
    Http::fake();
    $message = WhatsAppFixtures::outbound(($this->conversationWith)([['external_id' => '5217771234567', 'last_seen_at' => now()]]));

    ($this->deliver)($message);

    expect($message->refresh())->status->toBe(MessageStatus::Failed)->error_code->toBe('transport_unavailable');
    Http::assertNothingSent();
});

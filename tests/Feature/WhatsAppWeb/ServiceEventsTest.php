<?php

use App\Enums\AttachmentStatus;
use App\Enums\AuditAction;
use App\Enums\ConnectionHealth;
use App\Enums\ConnectionProvider;
use App\Enums\MessageDirection;
use App\Enums\MessageStatus;
use App\Enums\MessageType;
use App\Enums\WhatsAppWebState;
use App\Jobs\DownloadInboundMedia;
use App\Jobs\DownloadWhatsAppWebMedia;
use App\Models\AuditLog;
use App\Models\Connection;
use App\Models\Contact;
use App\Models\ContactIdentity;
use App\Models\Conversation;
use App\Models\Department;
use App\Models\Message;
use App\Models\MessageAttachment;
use App\Services\WhatsAppWeb\WhatsAppWebTransport;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\Support\WhatsAppFixtures;
use Tests\Support\WhatsAppWebFixtures;

/*
| Events of the WhatsApp Web service (contract §5): authentication of the
| internal endpoint and what each event does in ChatManager. The service is
| a double: the payloads follow the contract, not a real WhatsApp session.
*/

beforeEach(function () {
    Http::preventStrayRequests();
    WhatsAppWebFixtures::configure();
    $this->department = Department::factory()->create();
    $this->connection = Connection::factory()->whatsAppWeb()->create(['name' => 'Cobranza QR', 'default_department_id' => $this->department->id]);
    $this->connection->departments()->sync([$this->department->id]);
    $this->path = '/internal/whatsapp-web/events';
    $this->body = fn (array $data = [], ?string $type = null) => (string) json_encode([
        'event_id' => (string) Str::uuid(),
        'connection_id' => $this->connection->id,
        'type' => $type ?? 'message.inbound',
        'occurred_at' => now()->getTimestamp(),
        'data' => $data === [] ? WhatsAppWebFixtures::inbound() : $data,
    ]);
    $this->send = function (string $body, array $headers) {
        $server = ['CONTENT_TYPE' => 'application/json'];

        foreach ($headers as $name => $value) {
            $server['HTTP_'.strtoupper(str_replace('-', '_', $name))] = $value;
        }

        return $this->call('POST', $this->path, [], [], [], $server, $body);
    };
});

test('an event without a valid signature is refused and changes nothing', function (Closure $headers) {
    $body = ($this->body)();

    ($this->send)($body, $headers($body, $this->path))->assertUnauthorized();

    expect(Message::query()->count())->toBe(0)
        ->and(Contact::query()->count())->toBe(0);
})->with([
    'no signature at all' => [fn (string $body, string $path) => []],
    'signed with another secret' => [fn (string $body, string $path) => WhatsAppWebFixtures::headers('POST', $path, $body, 'another-secret-another-secret-another-secret')],
    'signature of another body' => [fn (string $body, string $path) => WhatsAppWebFixtures::headers('POST', $path, $body.' ')],
    'signature of another path' => [fn (string $body, string $path) => WhatsAppWebFixtures::headers('POST', $path.'/other', $body)],
    'timestamp too old' => [fn (string $body, string $path) => WhatsAppWebFixtures::headers('POST', $path, $body, timestamp: now()->getTimestamp() - 120)],
    'timestamp in the future' => [fn (string $body, string $path) => WhatsAppWebFixtures::headers('POST', $path, $body, timestamp: now()->getTimestamp() + 120)],
]);

test('a signed request cannot be replayed', function () {
    $body = ($this->body)();
    $headers = WhatsAppWebFixtures::headers('POST', $this->path, $body);

    ($this->send)($body, $headers)->assertOk();
    ($this->send)($body, $headers)->assertUnauthorized();

    expect(Message::query()->count())->toBe(1);
});

test('nothing is accepted while the shared secret is not configured', function () {
    $body = ($this->body)();
    $headers = WhatsAppWebFixtures::headers('POST', $this->path, $body);
    config(['whatsapp_web.secret' => '']);

    ($this->send)($body, $headers)->assertUnauthorized();

    expect(Message::query()->count())->toBe(0);
});

test('events are only accepted from the addresses of the service when they are restricted', function () {
    config(['whatsapp_web.allowed_ips' => ['10.9.8.7']]);

    WhatsAppWebFixtures::event($this, $this->connection, 'message.inbound', WhatsAppWebFixtures::inbound())->assertUnauthorized();

    config(['whatsapp_web.allowed_ips' => ['127.0.0.1']]);

    WhatsAppWebFixtures::event($this, $this->connection, 'message.inbound', WhatsAppWebFixtures::inbound())->assertOk();
});

test('an event of a connection that does not exist, was deleted or is not WhatsApp Web is answered 404', function () {
    $cloud = Connection::factory()->verified()->create();
    $deleted = Connection::factory()->whatsAppWeb()->create(['deleted_at' => now(), 'is_active' => false]);

    WhatsAppWebFixtures::event($this, 999999, 'message.inbound', WhatsAppWebFixtures::inbound())->assertNotFound();
    WhatsAppWebFixtures::event($this, $cloud, 'message.inbound', WhatsAppWebFixtures::inbound())->assertNotFound();
    WhatsAppWebFixtures::event($this, $cloud, 'session.state', WhatsAppWebFixtures::session($cloud, 'connected'))->assertNotFound();
    WhatsAppWebFixtures::event($this, $deleted, 'message.inbound', WhatsAppWebFixtures::inbound())->assertNotFound();

    expect(Message::query()->count())->toBe(0)
        ->and($cloud->refresh()->web_session_state)->toBeNull();
});

test('an inbound message creates the contact and a conversation on that connection, like any other channel', function () {
    WhatsAppWebFixtures::event($this, $this->connection, 'message.inbound', WhatsAppWebFixtures::inbound([
        'provider_message_id' => '3EB0AAAA1111',
        'text' => 'Reporto pago del cliente',
    ]))->assertOk()->assertJsonPath('result', 'applied');

    $message = Message::query()->sole();
    $conversation = Conversation::query()->sole();
    $contact = Contact::query()->sole();
    $identity = ContactIdentity::query()->sole();

    expect($message->whatsapp_message_id)->toBe(WhatsAppWebTransport::storedId($this->connection->id, '3EB0AAAA1111'))
        ->and($message->direction)->toBe(MessageDirection::Inbound)
        ->and($message->type)->toBe(MessageType::Text)
        ->and($message->status)->toBe(MessageStatus::Received)
        ->and($message->body)->toBe('Reporto pago del cliente')
        ->and($conversation->connection_id)->toBe($this->connection->id)
        ->and($conversation->department_id)->toBe($this->department->id)
        ->and($conversation->contact_id)->toBe($contact->id)
        ->and($contact->phone_e164)->toBe('+525511112222')
        ->and($identity->external_id)->toBe('5215511112222')
        // The origin of the contact names the transport that brought it.
        ->and($identity->provider)->toBe(ConnectionProvider::WhatsappWeb)
        ->and($this->connection->refresh()->last_inbound_at)->not->toBeNull();
});

test('the same message delivered again never creates a second message', function () {
    $data = WhatsAppWebFixtures::inbound(['provider_message_id' => '3EB0REPEATED']);
    $eventId = (string) Str::uuid();

    WhatsAppWebFixtures::event($this, $this->connection, 'message.inbound', $data, $eventId)->assertOk()->assertJsonPath('result', 'applied');
    // The service retried the very same event.
    WhatsAppWebFixtures::event($this, $this->connection, 'message.inbound', $data, $eventId)->assertOk()->assertJsonPath('result', 'duplicate');
    // The same WhatsApp message under a new event id (e.g. after a restart of the service).
    WhatsAppWebFixtures::event($this, $this->connection, 'message.inbound', $data)->assertOk()->assertJsonPath('result', 'duplicate');

    expect(Message::query()->count())->toBe(1)
        ->and(Conversation::query()->count())->toBe(1)
        ->and(Contact::query()->count())->toBe(1);
});

test('two linked numbers never share conversations, even for the same WhatsApp message id', function () {
    $other = Connection::factory()->whatsAppWeb()->create(['name' => 'Otra línea']);
    $data = WhatsAppWebFixtures::inbound(['provider_message_id' => '3EB0SAMEID']);

    WhatsAppWebFixtures::event($this, $this->connection, 'message.inbound', $data)->assertOk()->assertJsonPath('result', 'applied');
    WhatsAppWebFixtures::event($this, $other, 'message.inbound', $data)->assertOk()->assertJsonPath('result', 'applied');

    expect(Message::query()->count())->toBe(2)
        ->and(Conversation::query()->where('connection_id', $this->connection->id)->count())->toBe(1)
        ->and(Conversation::query()->where('connection_id', $other->id)->count())->toBe(1)
        // One person, one contact, seen through both numbers.
        ->and(Contact::query()->count())->toBe(1);
});

test('groups, broadcasts and statuses are never handled', function (string $jid) {
    WhatsAppWebFixtures::event($this, $this->connection, 'message.inbound', WhatsAppWebFixtures::inbound(['remote_jid' => $jid]))
        ->assertOk()
        ->assertJsonPath('result', 'ignored');

    expect(Message::query()->count())->toBe(0);
})->with(['120363012345678901@g.us', 'status@broadcast', '120363099999999999@newsletter', '5215511112222@broadcast']);

test('a sender WhatsApp only identifies with a privacy id becomes a contact without a phone', function () {
    WhatsAppWebFixtures::event($this, $this->connection, 'message.inbound', WhatsAppWebFixtures::inbound([
        'remote_jid' => '178234567890123@lid',
        'phone' => null,
    ]))->assertOk()->assertJsonPath('result', 'applied');

    expect(ContactIdentity::query()->sole()->external_id)->toBe('lid:178234567890123')
        ->and(Contact::query()->sole()->phone_e164)->toBeNull();
});

test('a file is registered as a pending attachment whose download descriptor is encrypted and never stored in the message', function () {
    Queue::fake([DownloadWhatsAppWebMedia::class, DownloadInboundMedia::class]);
    $descriptor = ['mediaKey' => 'c2VjcmV0LW1lZGlhLWtleQ==', 'directPath' => '/v/t62.7118-24/1234_n.enc', 'type' => 'image'];

    WhatsAppWebFixtures::event($this, $this->connection, 'message.inbound', WhatsAppWebFixtures::inbound([
        'type' => 'image',
        'text' => 'Comprobante',
        'media' => ['mime' => 'image/jpeg', 'size' => 48213, 'file_name' => null, 'seconds' => null, 'voice' => false, 'descriptor' => $descriptor],
    ]))->assertOk();

    $message = Message::query()->sole();
    $attachment = MessageAttachment::query()->sole();
    $raw = DB::table('message_attachments')->where('id', $attachment->id)->value('provider_reference');

    expect($message->type)->toBe(MessageType::Image)
        ->and($message->body)->toBe('Comprobante')
        ->and(json_encode($message->metadata))->not->toContain('mediaKey')->not->toContain('c2VjcmV0')
        ->and($attachment->status)->toBe(AttachmentStatus::Pending)
        ->and($attachment->provider_reference)->toBe($descriptor)
        ->and($raw)->not->toContain('c2VjcmV0')
        ->and(json_encode($attachment->toArray()))->not->toContain('mediaKey');

    Queue::assertPushed(DownloadWhatsAppWebMedia::class, fn (DownloadWhatsAppWebMedia $job): bool => $job->attachmentId === $attachment->id);
    Queue::assertNotPushed(DownloadInboundMedia::class);
});

test('locations and shared contacts are stored in the shape the interface already renders', function () {
    WhatsAppWebFixtures::event($this, $this->connection, 'message.inbound', WhatsAppWebFixtures::inbound([
        'type' => 'location', 'text' => null,
        'location' => ['latitude' => 19.4326, 'longitude' => -99.1332, 'name' => 'Sucursal Centro', 'address' => 'Av. Juárez 1'],
    ]))->assertOk();
    WhatsAppWebFixtures::event($this, $this->connection, 'message.inbound', WhatsAppWebFixtures::inbound([
        'type' => 'contacts', 'text' => null,
        'contacts' => [['name' => 'Cliente Moroso', 'phones' => ['+52 55 9999 0000']]],
    ]))->assertOk();

    [$location, $contacts] = Message::query()->orderBy('id')->get()->all();

    expect($location->type)->toBe(MessageType::Location)
        ->and($location->body)->toBe('Sucursal Centro, Av. Juárez 1')
        ->and($location->metadata['content']['latitude'])->toBe(19.4326)
        ->and($contacts->type)->toBe(MessageType::Contacts)
        ->and($contacts->body)->toBe('Cliente Moroso')
        ->and($contacts->metadata['content']['contacts'][0]['name']['formatted_name'])->toBe('Cliente Moroso')
        ->and($contacts->metadata['content']['contacts'][0]['phones'][0]['phone'])->toBe('+52 55 9999 0000');
});

test('a reply quotes the stored message of the same connection', function () {
    WhatsAppWebFixtures::event($this, $this->connection, 'message.inbound', WhatsAppWebFixtures::inbound(['provider_message_id' => '3EB0FIRST']))->assertOk();
    WhatsAppWebFixtures::event($this, $this->connection, 'message.inbound', WhatsAppWebFixtures::inbound([
        'provider_message_id' => '3EB0SECOND',
        'quoted' => ['provider_message_id' => '3EB0FIRST'],
    ]))->assertOk();

    [$first, $second] = Message::query()->orderBy('id')->get()->all();

    expect($second->reply_to_message_id)->toBe($first->id);
});

test('a reaction points at the stored message and a removed reaction is ignored', function () {
    WhatsAppWebFixtures::event($this, $this->connection, 'message.inbound', WhatsAppWebFixtures::inbound(['provider_message_id' => '3EB0TARGET']))->assertOk();
    WhatsAppWebFixtures::event($this, $this->connection, 'message.inbound', WhatsAppWebFixtures::inbound([
        'type' => 'reaction', 'text' => null,
        'reaction' => ['provider_message_id' => '3EB0TARGET', 'emoji' => '👍'],
    ]))->assertOk()->assertJsonPath('result', 'applied');
    WhatsAppWebFixtures::event($this, $this->connection, 'message.inbound', WhatsAppWebFixtures::inbound([
        'type' => 'reaction', 'text' => null,
        'reaction' => ['provider_message_id' => '3EB0TARGET', 'emoji' => ''],
    ]))->assertOk()->assertJsonPath('result', 'ignored');

    $reaction = Message::query()->where('type', MessageType::Reaction)->sole();

    expect($reaction->body)->toBe('👍')
        ->and($reaction->metadata['content'])->toBe([
            'message_id' => WhatsAppWebTransport::storedId($this->connection->id, '3EB0TARGET'),
            'emoji' => '👍',
        ]);
});

test('a message written on the linked phone joins the conversation as outbound and is never duplicated', function () {
    WhatsAppWebFixtures::event($this, $this->connection, 'message.inbound', WhatsAppWebFixtures::inbound())->assertOk();
    $conversation = Conversation::query()->sole();
    $echo = WhatsAppWebFixtures::inbound(['provider_message_id' => '3EB0FROMPHONE', 'from_me' => true, 'text' => 'Te marco en cinco minutos', 'push_name' => null]);

    WhatsAppWebFixtures::event($this, $this->connection, 'message.outbound_echo', $echo)->assertOk()->assertJsonPath('result', 'applied');
    WhatsAppWebFixtures::event($this, $this->connection, 'message.outbound_echo', $echo)->assertOk()->assertJsonPath('result', 'duplicate');

    $message = Message::query()->where('direction', MessageDirection::Outbound)->sole();

    expect($message->conversation_id)->toBe($conversation->id)
        ->and($message->status)->toBe(MessageStatus::Sent)
        ->and($message->sender_user_id)->toBeNull()
        ->and($message->metadata['sent_from_phone'])->toBeTrue()
        ->and($message->body)->toBe('Te marco en cinco minutos')
        ->and(Conversation::query()->count())->toBe(1);
});

test('a message ChatManager already sent is not stored again when the phone echoes it', function () {
    $conversation = Conversation::factory()->create(['connection_id' => $this->connection->id]);
    ContactIdentity::factory()->create(['contact_id' => $conversation->contact_id, 'external_id' => '5215511112222']);
    WhatsAppFixtures::outbound($conversation, ['status' => 'accepted', 'whatsapp_message_id' => WhatsAppWebTransport::storedId($this->connection->id, '3EB0SENTBYUS')]);

    WhatsAppWebFixtures::event($this, $this->connection, 'message.outbound_echo', WhatsAppWebFixtures::inbound(['provider_message_id' => '3EB0SENTBYUS', 'from_me' => true]))
        ->assertOk()
        ->assertJsonPath('result', 'duplicate');

    expect(Message::query()->count())->toBe(1);
});

test('a message written on the phone to someone ChatManager never saw creates nothing', function () {
    WhatsAppWebFixtures::event($this, $this->connection, 'message.outbound_echo', WhatsAppWebFixtures::inbound(['from_me' => true]))
        ->assertOk()
        ->assertJsonPath('result', 'ignored');

    expect(Message::query()->count())->toBe(0)
        ->and(Contact::query()->count())->toBe(0)
        ->and(Conversation::query()->count())->toBe(0);
});

test('delivery statuses move the outbound message of that connection forward and no other', function () {
    $other = Connection::factory()->whatsAppWeb()->create();
    $mine = WhatsAppFixtures::outbound(Conversation::factory()->create(['connection_id' => $this->connection->id]), [
        'status' => 'accepted', 'whatsapp_message_id' => WhatsAppWebTransport::storedId($this->connection->id, '3EB0OUT'),
    ]);
    $theirs = WhatsAppFixtures::outbound(Conversation::factory()->create(['connection_id' => $other->id]), [
        'status' => 'accepted', 'whatsapp_message_id' => WhatsAppWebTransport::storedId($other->id, '3EB0OUT'),
    ]);

    WhatsAppWebFixtures::event($this, $this->connection, 'message.status', ['provider_message_id' => '3EB0OUT', 'status' => 'delivered', 'timestamp' => now()->getTimestamp(), 'error' => null])
        ->assertOk()->assertJsonPath('result', 'applied');

    expect($mine->refresh()->status)->toBe(MessageStatus::Delivered)
        ->and($mine->delivered_at)->not->toBeNull()
        ->and($theirs->refresh()->status)->toBe(MessageStatus::Accepted);

    WhatsAppWebFixtures::event($this, $this->connection, 'message.status', ['provider_message_id' => '3EB0OUT', 'status' => 'read', 'timestamp' => now()->getTimestamp(), 'error' => null])->assertOk();
    // A late "delivered" never moves it back.
    WhatsAppWebFixtures::event($this, $this->connection, 'message.status', ['provider_message_id' => '3EB0OUT', 'status' => 'delivered', 'timestamp' => now()->getTimestamp(), 'error' => null])->assertOk();

    expect($mine->refresh()->status)->toBe(MessageStatus::Read);

    WhatsAppWebFixtures::event($this, $this->connection, 'message.status', ['provider_message_id' => '3EB0UNKNOWN', 'status' => 'delivered', 'timestamp' => now()->getTimestamp(), 'error' => null])
        ->assertOk()->assertJsonPath('result', 'ignored');
});

test('a session revoked from the phone requires a new link and is never restored automatically', function () {
    WhatsAppWebFixtures::event($this, $this->connection, 'session.state', WhatsAppWebFixtures::session($this->connection, 'logged_out', ['reason' => 'logged_out_from_phone']))
        ->assertOk();

    $connection = $this->connection->refresh();

    expect($connection->webSessionState())->toBe(WhatsAppWebState::LoggedOut)
        ->and($connection->isConfigured())->toBeFalse()
        ->and($connection->web_session_phone)->toBeNull()
        ->and($connection->web_session_error)->toContain('desde el teléfono')
        ->and($connection->configurationHealth())->toBe(ConnectionHealth::Error)
        ->and($connection->sendingBlockReason())->toContain('requiere una nueva vinculación')
        ->and(AuditLog::query()->where('action', AuditAction::ConnectionWebSessionLost)->sole()->properties['reason'])->toBe('logged_out_from_phone');

    // Laravel never asks the service to start a session by itself.
    Http::assertNothingSent();
});

test('a lost socket is shown as reconnecting until the service reports it connected again', function () {
    WhatsAppWebFixtures::event($this, $this->connection, 'session.state', WhatsAppWebFixtures::session($this->connection, 'reconnecting', ['reason' => 'network', 'reconnects' => 2]))->assertOk();

    $connection = $this->connection->refresh();

    expect($connection->webSessionState())->toBe(WhatsAppWebState::Reconnecting)
        ->and($connection->isConfigured())->toBeTrue()
        ->and($connection->web_session_reconnects)->toBe(2)
        ->and($connection->sendingBlockReason())->toContain('reconectando');

    $this->travel(5)->seconds();

    WhatsAppWebFixtures::event($this, $this->connection, 'session.state', WhatsAppWebFixtures::session($this->connection, 'connected', ['phone' => '5215512345678', 'reconnects' => 2, 'connected_since' => now()->getTimestamp()]))->assertOk();

    expect($this->connection->refresh()->webSessionState())->toBe(WhatsAppWebState::Connected)
        ->and($this->connection->web_session_error)->toBeNull()
        ->and($this->connection->sendingBlockReason())->toBeNull();
});

test('a state older than the one stored never moves the session back', function () {
    $this->connection->forceFill(['web_session_event_at' => now()])->save();

    WhatsAppWebFixtures::event($this, $this->connection, 'session.state', WhatsAppWebFixtures::session($this->connection, 'reconnecting', ['last_event_at' => now()->subMinutes(5)->getTimestamp()]))->assertOk();

    expect($this->connection->refresh()->webSessionState())->toBe(WhatsAppWebState::Connected);
});

test('a deactivated connection still stores what its customers write', function () {
    $this->connection->forceFill(['is_active' => false])->save();

    WhatsAppWebFixtures::event($this, $this->connection, 'message.inbound', WhatsAppWebFixtures::inbound())->assertOk()->assertJsonPath('result', 'applied');

    expect(Message::query()->count())->toBe(1);
});

test('a malformed event is acknowledged so the service stops retrying it', function () {
    $body = (string) json_encode(['event_id' => 'x', 'type' => 'message.inbound']);

    ($this->send)($body, WhatsAppWebFixtures::headers('POST', $this->path, $body))->assertOk()->assertJsonPath('result', 'ignored');

    WhatsAppWebFixtures::event($this, $this->connection, 'message.inbound', ['provider_message_id' => '', 'remote_jid' => '5215511112222@s.whatsapp.net'])
        ->assertOk()->assertJsonPath('result', 'ignored');

    expect(Message::query()->count())->toBe(0);
});

<?php

use App\Actions\Conversations\ProcessInboundMessages;
use App\Enums\MessageType;
use App\Events\Conversations\ConversationChanged;
use App\Events\Messaging\MessageRecorded;
use App\Models\Connection;
use App\Models\Contact;
use App\Models\Message;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;

/*
| Every relevant Cloud API message type is stored with a readable text and
| its provider content; unsupported ones are kept with Meta's explanation.
*/

beforeEach(function () {
    Queue::fake();
    $this->connection = Connection::factory()->create();
    $this->receive = fn (array $message): Message => app(ProcessInboundMessages::class)->handle(whatsappWebhook($this->connection, [[
        'from' => '5215512345678',
        'id' => 'wamid.'.fake()->unique()->numerify('##########'),
        'timestamp' => (string) now()->getTimestamp(),
        ...$message,
    ]]))[0]->message;
});

test('message types are stored with their readable text', function (array $message, MessageType $type, ?string $body) {
    $stored = ($this->receive)($message);

    expect($stored->type)->toBe($type)->and($stored->body)->toBe($body);
})->with([
    'location with name' => [['type' => 'location', 'location' => ['latitude' => 19.43, 'longitude' => -99.13, 'name' => 'Sucursal Centro']], MessageType::Location, 'Sucursal Centro'],
    'location without name' => [['type' => 'location', 'location' => ['latitude' => 19.43, 'longitude' => -99.13]], MessageType::Location, 'Ubicación: 19.43, -99.13'],
    'shared contact' => [['type' => 'contacts', 'contacts' => [['name' => ['formatted_name' => 'Ana López'], 'phones' => [['phone' => '+52 55 1234 5678']]]]], MessageType::Contacts, 'Contacto compartido: Ana López (+52 55 1234 5678)'],
    'list reply' => [['type' => 'interactive', 'interactive' => ['type' => 'list_reply', 'list_reply' => ['id' => 'opt-2', 'title' => 'Facturación']]], MessageType::Interactive, 'Facturación'],
    'template quick reply button' => [['type' => 'button', 'button' => ['payload' => 'yes', 'text' => 'Sí, confirmo']], MessageType::Interactive, 'Sí, confirmo'],
    'sticker' => [['type' => 'sticker', 'sticker' => ['id' => '2001', 'mime_type' => 'image/webp']], MessageType::Sticker, null],
    'document' => [['type' => 'document', 'document' => ['id' => '2002', 'mime_type' => 'application/pdf', 'filename' => '../../etc/factura.pdf', 'caption' => 'Factura']], MessageType::Document, 'Factura'],
]);

test('an unsupported message keeps Meta explanation instead of being lost', function () {
    $stored = ($this->receive)(['type' => 'unsupported', 'errors' => [['code' => 131051, 'title' => 'Message type unknown', 'message' => 'Message type unknown']]]);

    expect($stored->type)->toBe(MessageType::Unsupported)
        ->and($stored->metadata['content']['errors'][0])->toMatchArray(['code' => 131051, 'title' => 'Message type unknown']);
});

test('document names are sanitized and kept only for display', function () {
    $stored = ($this->receive)(['type' => 'document', 'document' => ['id' => '2002', 'mime_type' => 'application/pdf', 'filename' => '../../etc/fac<tura>.pdf']]);

    expect($stored->attachments()->sole()->file_name)->toBe('factura.pdf');
});

test('a customer reply is linked to the quoted message of the same conversation', function () {
    $first = ($this->receive)(['type' => 'text', 'text' => ['body' => 'Hola']]);

    $reply = ($this->receive)(['type' => 'text', 'text' => ['body' => 'Sobre esto'], 'context' => ['from' => '15550783881', 'id' => $first->whatsapp_message_id]]);
    $unknown = ($this->receive)(['type' => 'text', 'text' => ['body' => 'Otra'], 'context' => ['id' => 'wamid.not-stored']]);

    expect($reply->reply_to_message_id)->toBe($first->id)
        ->and($reply->metadata['reply_to'])->toBe(['message_id' => $first->whatsapp_message_id])
        ->and($unknown->reply_to_message_id)->toBeNull();
});

test('a new message announces itself; a repeated delivery announces nothing and leaves the contact untouched', function () {
    Event::fake([MessageRecorded::class, ConversationChanged::class]);
    $payload = whatsappWebhook($this->connection, [whatsappTextMessage('5215512345678', 'Hola', now()->subMinute()->toImmutable(), 'wamid.once')], ['5215512345678' => 'Laura']);

    app(ProcessInboundMessages::class)->handle($payload);
    $contact = Contact::query()->sole();
    $interaction = $contact->last_interaction_at;

    $this->travel(5)->minutes();
    $payload['entry'][0]['changes'][0]['value']['contacts'][0]['profile']['name'] = 'Nombre cambiado';
    $receipts = app(ProcessInboundMessages::class)->handle($payload);

    expect($receipts[0]->outcome->value)->toBe('duplicate')
        ->and($contact->refresh()->last_interaction_at?->toDateTimeString())->toBe($interaction?->toDateTimeString())
        ->and($contact->profile_name)->toBe('Laura');
    Event::assertDispatchedTimes(MessageRecorded::class, 1);
    Event::assertDispatched(MessageRecorded::class, fn (MessageRecorded $event): bool => $event->direction === 'inbound');
    Event::assertDispatched(ConversationChanged::class, fn (ConversationChanged $event): bool => $event->reason === 'message');
});

test('the connection records inbound traffic as health evidence', function () {
    ($this->receive)(['type' => 'text', 'text' => ['body' => 'Hola']]);

    expect($this->connection->refresh()->last_inbound_at)->not->toBeNull()
        ->and($this->connection->health()->value)->toBe('receiving');
});

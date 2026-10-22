<?php

use App\Actions\Conversations\ProcessInboundMessages;
use App\Enums\AttributionReliability;
use App\Enums\ContactOriginType;
use App\Enums\ConversationEventType;
use App\Enums\ConversationStatus;
use App\Enums\DistributionMode;
use App\Enums\InboundMessageOutcome;
use App\Enums\MessageDirection;
use App\Enums\MessageStatus;
use App\Enums\MessageType;
use App\Models\Connection;
use App\Models\Contact;
use App\Models\ContactIdentity;
use App\Models\Conversation;
use App\Models\Department;
use App\Models\Message;
use App\Models\Tag;
use App\Models\User;
use App\Services\PlatformSettings;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/*
| Inbound messages entering the conversation engine. Every event is built in
| the documented WhatsApp Cloud API webhook format and processed by the real
| pipeline; no Meta traffic is involved.
*/

test('the first message registers the contact, opens a conversation and stores the message', function () {
    $connection = Connection::factory()->create();
    $at = CarbonImmutable::parse('2026-10-19 15:30:00', 'UTC');

    $receipt = receiveWhatsapp($connection, '5215512345678', 'Hola, quiero información', $at, 'wamid.first');

    $conversation = $receipt->conversation;

    expect($receipt->outcome)->toBe(InboundMessageOutcome::Created)
        ->and($conversation->connection_id)->toBe($connection->id)
        ->and($conversation->contact_id)->toBe($receipt->registration->contact->id)
        ->and($conversation->status)->toBe(ConversationStatus::Pending)
        ->and($conversation->last_inbound_at->toDateTimeString())->toBe('2026-10-19 15:30:00')
        ->and($conversation->last_message_at->toDateTimeString())->toBe('2026-10-19 15:30:00')
        ->and($receipt->message)
        ->direction->toBe(MessageDirection::Inbound)
        ->type->toBe(MessageType::Text)
        ->status->toBe(MessageStatus::Received)
        ->body->toBe('Hola, quiero información')
        ->whatsapp_message_id->toBe('wamid.first')
        ->and($receipt->message->sent_at->toDateTimeString())->toBe('2026-10-19 15:30:00')
        ->and($conversation->events()->sole())
        ->type->toBe(ConversationEventType::Created)
        ->actor_user_id->toBeNull();
});

test('following messages of the same contact join the unclosed conversation', function () {
    $connection = Connection::factory()->create();

    $first = receiveWhatsapp($connection, '5215512345678', 'Hola');
    $second = receiveWhatsapp($connection, '5215512345678', '¿Siguen ahí?');

    expect($second->outcome)->toBe(InboundMessageOutcome::Appended)
        ->and($second->conversation->id)->toBe($first->conversation->id)
        ->and(Conversation::query()->count())->toBe(1)
        ->and(Message::query()->count())->toBe(2)
        ->and(Contact::query()->count())->toBe(1)
        ->and(ContactIdentity::query()->count())->toBe(1);
});

test('a conversation on hold or in attention also keeps receiving the messages', function (string $state) {
    $connection = Connection::factory()->create();
    $agent = User::factory()->agent()->create();
    $first = receiveWhatsapp($connection);
    $first->conversation->forceFill(['status' => $state, 'assigned_user_id' => $agent->id])->save();

    $second = receiveWhatsapp($connection);

    expect($second->conversation->id)->toBe($first->conversation->id)
        ->and($second->conversation->status->value)->toBe($state)
        ->and($second->conversation->assigned_user_id)->toBe($agent->id);
})->with(['open', 'on_hold']);

test('a repeated delivery of the same message changes nothing', function () {
    $connection = Connection::factory()->create();
    $first = receiveWhatsapp($connection, id: 'wamid.repeated');
    $versionBefore = $first->conversation->refresh()->version;

    $again = receiveWhatsapp($connection, body: 'otro texto', id: 'wamid.repeated');

    expect($again->outcome)->toBe(InboundMessageOutcome::Duplicate)
        ->and($again->message->id)->toBe($first->message->id)
        ->and($again->message->body)->toBe('Hola')
        ->and(Message::query()->count())->toBe(1)
        ->and(Conversation::query()->count())->toBe(1)
        ->and($again->conversation->version)->toBe($versionBefore)
        ->and($again->conversation->events()->count())->toBe(1);
});

test('conversations of different business numbers are never mixed', function () {
    [$sales, $support] = Connection::factory()->count(2)->create();

    $first = receiveWhatsapp($sales, '5215512345678');
    $second = receiveWhatsapp($support, '5215512345678');

    expect($second->outcome)->toBe(InboundMessageOutcome::Created)
        ->and($second->conversation->id)->not->toBe($first->conversation->id)
        ->and($second->conversation->connection_id)->toBe($support->id)
        ->and($second->registration->contact->id)->toBe($first->registration->contact->id)
        ->and(Contact::query()->count())->toBe(1);
});

test('a late event never moves the activity dates backwards', function () {
    $connection = Connection::factory()->create();
    $recent = CarbonImmutable::parse('2026-10-19 12:00:00', 'UTC');

    receiveWhatsapp($connection, at: $recent);
    $late = receiveWhatsapp($connection, at: $recent->subHours(3));

    expect($late->outcome)->toBe(InboundMessageOutcome::Appended)
        ->and($late->conversation->last_inbound_at->toDateTimeString())->toBe('2026-10-19 12:00:00')
        ->and($late->conversation->last_message_at->toDateTimeString())->toBe('2026-10-19 12:00:00');
});

test('the customer name edited by employees, its notes and tags are kept', function () {
    $connection = Connection::factory()->create();
    $first = receiveWhatsapp($connection, profileName: 'Laura');
    $contact = $first->registration->contact;
    $contact->forceFill(['name' => 'Laura Pérez (mayoreo)'])->save();
    $tag = Tag::factory()->create();
    $contact->tags()->attach($tag->id, ['created_at' => now()]);

    receiveWhatsapp($connection, profileName: 'Lau ✨');

    expect($contact->refresh())
        ->name->toBe('Laura Pérez (mayoreo)')
        ->profile_name->toBe('Lau ✨')
        ->and($contact->tags()->pluck('tags.id')->all())->toBe([$tag->id]);
});

test('closed conversations reopen when the customer writes within the reopening window', function () {
    $connection = Connection::factory()->create(['reopen_window_minutes' => 120]);
    $department = Department::factory()->create();
    $agent = User::factory()->agent()->inDepartments($department)->create();
    $first = receiveWhatsapp($connection);
    $first->conversation->forceFill([
        'department_id' => $department->id,
        'assigned_user_id' => $agent->id,
        'status' => ConversationStatus::Closed,
        'closed_at' => now()->subMinutes(30),
    ])->save();

    $receipt = receiveWhatsapp($connection, body: 'Una pregunta más');

    expect($receipt->outcome)->toBe(InboundMessageOutcome::Reopened)
        ->and($receipt->conversation->id)->toBe($first->conversation->id)
        ->and($receipt->conversation)
        ->status->toBe(ConversationStatus::Open)
        ->assigned_user_id->toBe($agent->id)
        ->reopen_count->toBe(1)
        ->closed_at->toBeNull()
        ->and($receipt->conversation->events()->latest('id')->first())
        ->type->toBe(ConversationEventType::Reopened)
        ->metadata->toBe(['trigger' => 'inbound']);
});

test('a reopened conversation waits in the queue when its agent can no longer attend it', function () {
    $connection = Connection::factory()->create(['reopen_window_minutes' => 120]);
    $department = Department::factory()->create();
    $agent = User::factory()->agent()->inactive()->inDepartments($department)->create();
    $first = receiveWhatsapp($connection);
    $first->conversation->forceFill([
        'department_id' => $department->id,
        'assigned_user_id' => $agent->id,
        'status' => ConversationStatus::Closed,
        'closed_at' => now()->subMinutes(10),
    ])->save();

    $receipt = receiveWhatsapp($connection);

    expect($receipt->outcome)->toBe(InboundMessageOutcome::Reopened)
        ->and($receipt->conversation->status)->toBe(ConversationStatus::Pending)
        ->and($receipt->conversation->assigned_user_id)->toBeNull();
});

test('a new conversation starts after the reopening window or when the connection never reopens', function (?int $window, int $closedMinutesAgo) {
    $connection = Connection::factory()->create(['reopen_window_minutes' => $window]);
    $first = receiveWhatsapp($connection);
    $first->conversation->forceFill(['status' => ConversationStatus::Closed, 'closed_at' => now()->subMinutes($closedMinutesAgo)])->save();

    $receipt = receiveWhatsapp($connection);

    expect($receipt->outcome)->toBe(InboundMessageOutcome::Created)
        ->and($receipt->conversation->id)->not->toBe($first->conversation->id)
        ->and($first->conversation->refresh()->status)->toBe(ConversationStatus::Closed)
        ->and(Conversation::query()->count())->toBe(2);
})->with([
    'outside a two hour window' => [120, 121],
    'connection without reopening' => [null, 1],
]);

test('a message sent before the conversation closed joins its history without reopening it', function () {
    $connection = Connection::factory()->create(['reopen_window_minutes' => 120]);
    $first = receiveWhatsapp($connection);
    $first->conversation->forceFill(['status' => ConversationStatus::Closed, 'closed_at' => now()])->save();

    $late = receiveWhatsapp($connection, at: CarbonImmutable::now()->subMinutes(5));

    expect($late->outcome)->toBe(InboundMessageOutcome::LateArrival)
        ->and($late->conversation->id)->toBe($first->conversation->id)
        ->and($late->conversation->status)->toBe(ConversationStatus::Closed)
        ->and($late->conversation->reopen_count)->toBe(0);
});

test('new conversations follow the configured department only', function () {
    $connectionDepartment = Department::factory()->create();
    $platformDepartment = Department::factory()->create();
    app(PlatformSettings::class)->update(['operations' => ['default_department_id' => $platformDepartment->id]]);

    $withDefault = Connection::factory()->create(['default_department_id' => $connectionDepartment->id]);
    $withoutDefault = Connection::factory()->create();

    $routed = receiveWhatsapp($withDefault, '5215511111111');
    $platform = receiveWhatsapp($withoutDefault, '5215522222222');

    expect($routed->conversation->department_id)->toBe($connectionDepartment->id)
        ->and($routed->conversation->events()->sole()->metadata)->toBe(['source' => 'inbound', 'routed_by' => 'connection_default'])
        ->and($platform->conversation->department_id)->toBe($platformDepartment->id)
        ->and($platform->conversation->events()->sole()->metadata['routed_by'])->toBe('platform_default');
});

test('without an active authorized default the conversation stays without department', function () {
    $inactive = Department::factory()->inactive()->create();
    $notAuthorized = Department::factory()->create();
    $authorized = Department::factory()->create();
    app(PlatformSettings::class)->update(['operations' => ['default_department_id' => $notAuthorized->id]]);
    $connection = Connection::factory()->create(['default_department_id' => $inactive->id]);
    $connection->departments()->attach([$inactive->id, $authorized->id]);

    $receipt = receiveWhatsapp($connection);

    expect($receipt->conversation->department_id)->toBeNull()
        ->and($receipt->conversation->events()->sole()->metadata['routed_by'])->toBe('unrouted');
});

test('the Meta ad that started the conversation is linked to it as provider evidence', function () {
    $connection = Connection::factory()->create();

    $receipt = receiveWhatsapp($connection, extra: ['referral' => [
        'source_url' => 'https://fb.me/abc',
        'source_id' => '120226305854810726',
        'source_type' => 'ad',
        'headline' => 'Promoción de octubre',
        'ctwa_clid' => 'ARAkLkA8rmlFeiCktEJQ',
    ]]);
    $followUp = receiveWhatsapp($connection, body: 'Sigo interesada');

    $attribution = $receipt->conversation->attributions()->sole();

    expect($attribution)
        ->origin_type->toBe(ContactOriginType::MetaAd)
        ->reliability->toBe(AttributionReliability::ProviderConfirmed)
        ->headline->toBe('Promoción de octubre')
        ->ad_platform->toBeNull()
        ->campaign_name->toBeNull()
        ->page_name->toBeNull()
        ->and($followUp->conversation->attributions()->count())->toBe(1);
});

test('automatic departments assign new conversations on arrival', function () {
    $department = Department::factory()->distribution(DistributionMode::Automatic)->create();
    $agent = User::factory()->agent()->available()->inDepartments($department)->create();
    $connection = Connection::factory()->create(['default_department_id' => $department->id]);

    $receipt = receiveWhatsapp($connection);

    expect($receipt->conversation)
        ->status->toBe(ConversationStatus::Open)
        ->assigned_user_id->toBe($agent->id)
        ->and($receipt->conversation->events()->pluck('type')->all())
        ->toBe([ConversationEventType::Created, ConversationEventType::AutoAssigned]);
});

test('every message type is stored with its readable text and provider content', function (array $message, MessageType $type, ?string $body) {
    // Media downloads are queued; Meta is never reached from this test.
    Http::preventStrayRequests();
    Http::fake(['graph.facebook.com/*' => Http::response(['error' => ['message' => 'Unsupported get request.', 'code' => 100]], 400)]);
    $connection = Connection::factory()->create();
    $payload = whatsappWebhook($connection, [[
        'from' => '5215512345678',
        'id' => 'wamid.'.fake()->unique()->numerify('########'),
        'timestamp' => (string) now()->getTimestamp(),
        ...$message,
    ]]);

    $stored = app(ProcessInboundMessages::class)->handle($payload)[0]->message;

    expect($stored->type)->toBe($type)
        ->and($stored->body)->toBe($body)
        ->and($stored->metadata['content'] ?? null)->toBe($message[$message['type']] ?? null);
})->with([
    'image with caption' => [['type' => 'image', 'image' => ['id' => '1479537139650973', 'mime_type' => 'image/jpeg', 'caption' => 'Mi recibo']], MessageType::Image, 'Mi recibo'],
    'audio' => [['type' => 'audio', 'audio' => ['id' => '1003383421387256', 'mime_type' => 'audio/ogg; codecs=opus']], MessageType::Audio, null],
    'button reply' => [['type' => 'interactive', 'interactive' => ['type' => 'button_reply', 'button_reply' => ['id' => 'yes', 'title' => 'Sí, confirmo']]], MessageType::Interactive, 'Sí, confirmo'],
    'reaction' => [['type' => 'reaction', 'reaction' => ['message_id' => 'wamid.x', 'emoji' => '👍']], MessageType::Reaction, '👍'],
    'unknown type' => [['type' => 'order', 'order' => ['catalog_id' => '1']], MessageType::Unsupported, null],
]);

test('messages of an unknown business number are skipped and logged without personal data', function () {
    Log::spy();
    $unknown = Connection::factory()->make(['phone_number_id' => '999000999000999']);

    $receipts = app(ProcessInboundMessages::class)->handle(whatsappWebhook($unknown, [whatsappTextMessage('5215512345678', 'Hola', id: 'wamid.unknown')]));

    expect($receipts)->toBe([])
        ->and(Conversation::query()->count())->toBe(0)
        ->and(Contact::query()->count())->toBe(0);

    Log::shouldHaveReceived('warning')->withArgs(fn (string $message, array $context): bool => str_contains($message, 'no connection')
        && $context === ['phone_number_id' => '999000999000999', 'message_id' => 'wamid.unknown']);
});

test('a deleted connection keeps its history but no new traffic is attached to it', function () {
    $connection = Connection::factory()->create();
    $existing = receiveWhatsapp($connection, '5215512345678', 'Hola', null, 'wamid.before')->conversation;
    $connection->forceFill(['deleted_at' => now(), 'is_active' => false])->save();
    Log::spy();

    $receipts = app(ProcessInboundMessages::class)->handle(whatsappWebhook($connection, [whatsappTextMessage('5215512345678', 'Sigo aquí', id: 'wamid.after')]));

    expect($receipts)->toBe([])
        ->and(Message::query()->where('whatsapp_message_id', 'wamid.after')->exists())->toBeFalse()
        ->and($existing->refresh()->messages()->count())->toBe(1);
    Log::shouldHaveReceived('warning')->withArgs(fn (string $message): bool => str_contains($message, 'was deleted'))->once();
});

test('an inactive (not deleted) connection still receives customer messages, so none are lost', function () {
    $connection = Connection::factory()->inactive()->create();

    expect(receiveWhatsapp($connection)->outcome)->toBe(InboundMessageOutcome::Created);
});

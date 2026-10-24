<?php

use App\Enums\MessageStatus;
use App\Events\Messaging\MessageStatusChanged;
use App\Jobs\DeliverOutboundMessage;
use App\Models\Connection;
use App\Models\Conversation;
use App\Services\Messaging\ChannelTransports;
use App\Services\Meta\WhatsAppTransport;
use App\Services\WebChat\WebChatTransport;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Support\WebChatFixtures;
use Tests\Support\WhatsAppFixtures;

/*
| The outbound transport is resolved per connection channel: WhatsApp keeps
| the configured (or test double) transport, the web chat accepts the stored
| message without any network call.
*/

beforeEach(function () {
    Http::preventStrayRequests();
});

test('a WhatsApp connection always gets the transport bound at that moment', function () {
    $connection = Connection::factory()->create();
    $transports = app(ChannelTransports::class);

    $first = WhatsAppFixtures::transport();
    expect($transports->for($connection))->toBe($first);

    $second = WhatsAppFixtures::transport();
    expect($transports->for($connection))->toBe($second);
});

test('a web chat connection gets the web chat transport, which never calls a provider', function () {
    $transport = app(ChannelTransports::class)->for(WebChatFixtures::connection());
    $result = $transport->send(WhatsAppFixtures::outbound(Conversation::factory()->create()));

    expect($transport)->toBeInstanceOf(WebChatTransport::class)
        ->and($transport->name())->toBe('web_chat')
        ->and($result->providerMessageId)->toMatch('/^web\.out\.[0-9a-z]{26}$/')
        ->and($result->simulated)->toBeFalse();
    Http::assertNothingSent();
});

test('a web chat reply is accepted by the delivery job without reaching Meta, announced after commit', function () {
    $whatsapp = WhatsAppFixtures::transport();
    $webChat = WebChatFixtures::connection();
    $conversation = Conversation::factory()->create(['connection_id' => $webChat->id, 'last_inbound_at' => now()->subDays(3)]);
    $message = WhatsAppFixtures::outbound($conversation);
    $announced = [];
    Event::listen(MessageStatusChanged::class, function (MessageStatusChanged $event) use (&$announced): void {
        $announced[] = [$event->status, DB::transactionLevel()];
    });
    $outsideJob = DB::transactionLevel();

    app()->call([new DeliverOutboundMessage($message->id), 'handle']);

    expect($message->refresh())
        ->status->toBe(MessageStatus::Accepted)
        ->whatsapp_message_id->toStartWith('web.out.')
        ->accepted_at->not->toBeNull()
        ->and($whatsapp->sent)->toBe([])
        ->and($announced)->toBe([['sending', $outsideJob], ['accepted', $outsideJob]])
        ->and($webChat->refresh()->last_outbound_at)->not->toBeNull();
    Http::assertNothingSent();
});

test('a WhatsApp reply still goes through the WhatsApp transport', function () {
    $whatsapp = WhatsAppFixtures::transport();
    $conversation = Conversation::factory()->create(['last_inbound_at' => now()->subHour()]);
    $message = WhatsAppFixtures::outbound($conversation);

    app()->call([new DeliverOutboundMessage($message->id), 'handle']);

    expect($message->refresh())
        ->status->toBe(MessageStatus::Accepted)
        ->whatsapp_message_id->toBe('wamid.out.'.$message->id)
        ->and($whatsapp->sent)->toBe([$message->id])
        ->and(app(WhatsAppTransport::class))->toBe($whatsapp);
});

test('a web chat reply stuck sending is queued again instead of being marked as possibly duplicated', function () {
    Queue::fake();
    $conversation = Conversation::factory()->create(['connection_id' => WebChatFixtures::connection()->id]);
    $stuck = WhatsAppFixtures::outbound($conversation, ['status' => 'sending', 'sending_started_at' => now()->subMinutes(20), 'delivery_attempts' => 1]);

    $this->artisan('whatsapp:maintenance', ['task' => 'reconcile-deliveries'])->assertSuccessful();

    expect($stuck->refresh())
        ->status->toBe(MessageStatus::Queued)
        ->sending_started_at->toBeNull()
        ->error_code->toBeNull();
    Queue::assertPushed(DeliverOutboundMessage::class, fn (DeliverOutboundMessage $job): bool => $job->messageId === $stuck->id);
});

test('a web chat reply that keeps stalling fails without the WhatsApp duplicate warning', function () {
    Queue::fake();
    $conversation = Conversation::factory()->create(['connection_id' => WebChatFixtures::connection()->id]);
    $stuck = WhatsAppFixtures::outbound($conversation, [
        'status' => 'sending',
        'sending_started_at' => now()->subMinutes(20),
        'delivery_attempts' => DeliverOutboundMessage::maxAttempts(),
    ]);

    $this->artisan('whatsapp:maintenance', ['task' => 'reconcile-deliveries'])->assertSuccessful();

    expect($stuck->refresh())
        ->status->toBe(MessageStatus::Failed)
        ->error_code->toBe('delivery_error')
        ->error_message->toContain('chat web')
        ->error_message->not->toContain('WhatsApp')
        ->error_message->not->toContain('dos veces');
    Queue::assertNotPushed(DeliverOutboundMessage::class);
});

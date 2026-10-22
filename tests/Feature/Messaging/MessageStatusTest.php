<?php

use App\Actions\Messaging\ProcessWebhookPayload;
use App\Enums\MessageStatus;
use App\Events\Messaging\MessageStatusChanged;
use App\Models\Connection;
use App\Models\Conversation;
use App\Models\Message;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Event;
use Tests\Support\WhatsAppFixtures;

/*
| Meta's status webhooks are the only evidence of sent/delivered/read. They
| move a message forward only, tolerate any order and repetition, and only
| touch outbound messages of the connection they arrived through.
*/

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-20 15:00:00', 'UTC'));
    $this->connection = Connection::factory()->verified()->create();
    $this->conversation = Conversation::factory()->create(['connection_id' => $this->connection->id]);
    $this->message = WhatsAppFixtures::outbound($this->conversation, ['status' => 'accepted', 'whatsapp_message_id' => 'wamid.out.1', 'accepted_at' => now()]);
    $this->report = fn (array $statuses, ?Connection $through = null) => app(ProcessWebhookPayload::class)
        ->handle($through ?? $this->connection, WhatsAppFixtures::statusWebhook($through ?? $this->connection, $statuses));
});

test('statuses move the message forward with Meta timestamps', function () {
    Event::fake([MessageStatusChanged::class]);
    $sent = now()->subMinutes(3)->toImmutable();

    ($this->report)([WhatsAppFixtures::status('wamid.out.1', 'sent', $sent)]);
    ($this->report)([WhatsAppFixtures::status('wamid.out.1', 'delivered', $sent->addMinute())]);
    ($this->report)([WhatsAppFixtures::status('wamid.out.1', 'read', $sent->addMinutes(2))]);

    expect($this->message->refresh())
        ->status->toBe(MessageStatus::Read)
        ->sent_at->toDateTimeString()->toBe($sent->toDateTimeString())
        ->delivered_at->toDateTimeString()->toBe($sent->addMinute()->toDateTimeString())
        ->read_at->toDateTimeString()->toBe($sent->addMinutes(2)->toDateTimeString());

    Event::assertDispatched(MessageStatusChanged::class, fn (MessageStatusChanged $event): bool => $event->status === 'sent' && $event->previousStatus === 'accepted');
    Event::assertDispatched(MessageStatusChanged::class, fn (MessageStatusChanged $event): bool => $event->status === 'read' && $event->previousStatus === 'delivered');
    Event::assertDispatchedTimes(MessageStatusChanged::class, 3);
});

test('a read reported before its delivery sets every moment and is never moved back', function () {
    $sent = now()->subMinutes(10)->toImmutable();

    ($this->report)([WhatsAppFixtures::status('wamid.out.1', 'read', $sent->addMinutes(5))]);

    expect($this->message->refresh())
        ->status->toBe(MessageStatus::Read)
        ->delivered_at->toDateTimeString()->toBe($sent->addMinutes(5)->toDateTimeString())
        ->sent_at->toDateTimeString()->toBe($sent->addMinutes(5)->toDateTimeString());

    ($this->report)([
        WhatsAppFixtures::status('wamid.out.1', 'delivered', $sent->addMinutes(2)),
        WhatsAppFixtures::status('wamid.out.1', 'sent', $sent),
    ]);

    expect($this->message->refresh())
        ->status->toBe(MessageStatus::Read)
        ->sent_at->toDateTimeString()->toBe($sent->toDateTimeString())
        ->delivered_at->toDateTimeString()->toBe($sent->addMinutes(2)->toDateTimeString())
        ->read_at->toDateTimeString()->toBe($sent->addMinutes(5)->toDateTimeString());
});

test('a repeated status changes nothing and announces nothing', function () {
    $status = WhatsAppFixtures::status('wamid.out.1', 'delivered', now()->subMinute()->toImmutable());
    ($this->report)([$status]);
    $updatedAt = $this->message->refresh()->updated_at;

    Event::fake([MessageStatusChanged::class]);
    $this->travel(5)->seconds();
    ($this->report)([$status]);

    expect($this->message->refresh()->updated_at->equalTo($updatedAt))->toBeTrue();
    Event::assertNotDispatched(MessageStatusChanged::class);
});

test('a failure explains the Meta error and is terminal', function () {
    ($this->report)([WhatsAppFixtures::status('wamid.out.1', 'failed', now()->toImmutable(), [
        'errors' => [['code' => 131026, 'title' => 'Message undeliverable', 'error_data' => ['details' => 'Message Undeliverable.']]],
    ])]);

    expect($this->message->refresh())
        ->status->toBe(MessageStatus::Failed)
        ->error_code->toBe('meta_131026')
        ->error_message->toContain('WhatsApp no pudo entregar el mensaje')
        ->failed_at->not->toBeNull()
        ->and($this->message->metadata['provider']['errors'][0]['code'])->toBe(131026);

    ($this->report)([WhatsAppFixtures::status('wamid.out.1', 'delivered', now()->toImmutable())]);

    expect($this->message->refresh()->status)->toBe(MessageStatus::Failed);
});

test('a failure reported after delivery is kept as evidence without undoing the delivery', function () {
    ($this->report)([WhatsAppFixtures::status('wamid.out.1', 'delivered', now()->subMinute()->toImmutable())]);
    ($this->report)([WhatsAppFixtures::status('wamid.out.1', 'failed', now()->toImmutable(), ['errors' => [['code' => 131000, 'title' => 'Something went wrong']]])]);

    expect($this->message->refresh())
        ->status->toBe(MessageStatus::Delivered)
        ->error_code->toBeNull()
        ->and($this->message->metadata['late_failure']['code'])->toBe(131000);
});

test('pricing and conversation data are kept as reported, never as an amount', function () {
    ($this->report)([WhatsAppFixtures::status('wamid.out.1', 'sent', now()->toImmutable(), [
        'conversation' => ['id' => 'CONVERSATION_ID', 'origin' => ['type' => 'service'], 'expiration_timestamp' => '1761100000'],
        'pricing' => ['billable' => true, 'pricing_model' => 'PMP', 'category' => 'service', 'type' => 'regular'],
    ])]);

    expect($this->message->refresh()->metadata['provider'])->toBe([
        'conversation' => ['id' => 'CONVERSATION_ID', 'origin_type' => 'service', 'expiration_timestamp' => '1761100000'],
        'pricing' => ['billable' => true, 'pricing_model' => 'PMP', 'category' => 'service', 'type' => 'regular'],
    ]);
});

test('statuses never touch messages of another connection nor inbound messages', function () {
    $other = Connection::factory()->create();
    $inbound = Message::factory()->create(['conversation_id' => $this->conversation->id, 'whatsapp_message_id' => 'wamid.in.1']);

    ($this->report)([WhatsAppFixtures::status('wamid.out.1', 'read', now()->toImmutable())], $other);
    ($this->report)([WhatsAppFixtures::status('wamid.in.1', 'read', now()->toImmutable())]);

    expect($this->message->refresh()->status)->toBe(MessageStatus::Accepted)
        ->and($inbound->refresh()->status)->toBe(MessageStatus::Received)
        ->and($inbound->read_at)->toBeNull();
});

test('the callback data reconciles a message whose send answer was lost', function () {
    $stuck = WhatsAppFixtures::outbound($this->conversation, ['status' => 'sending', 'sending_started_at' => now()->subMinute()]);

    ($this->report)([WhatsAppFixtures::status('wamid.recovered', 'delivered', now()->toImmutable(), ['biz_opaque_callback_data' => $stuck->client_reference])]);

    expect($stuck->refresh())
        ->status->toBe(MessageStatus::Delivered)
        ->whatsapp_message_id->toBe('wamid.recovered')
        ->accepted_at->not->toBeNull();
});

test('Meta evidence overrides an unconfirmed delivery, but not a real failure', function () {
    $unknown = WhatsAppFixtures::outbound($this->conversation, ['status' => 'failed', 'error_code' => 'delivery_unknown', 'failed_at' => now()]);
    $rejected = WhatsAppFixtures::outbound($this->conversation, ['status' => 'failed', 'error_code' => 'meta_131047', 'failed_at' => now()]);

    ($this->report)([
        WhatsAppFixtures::status('wamid.late.1', 'sent', now()->toImmutable(), ['biz_opaque_callback_data' => $unknown->client_reference]),
        WhatsAppFixtures::status('wamid.late.2', 'sent', now()->toImmutable(), ['biz_opaque_callback_data' => $rejected->client_reference]),
    ]);

    expect($unknown->refresh())
        ->status->toBe(MessageStatus::Sent)
        ->error_code->toBeNull()
        ->failed_at->toBeNull()
        ->and($rejected->refresh())
        ->status->toBe(MessageStatus::Failed)
        ->whatsapp_message_id->toBeNull();
});

test('a callback data of another connection reconciles nothing', function () {
    $other = Connection::factory()->create();
    $stuck = WhatsAppFixtures::outbound($this->conversation, ['status' => 'sending', 'sending_started_at' => now()]);

    ($this->report)([WhatsAppFixtures::status('wamid.spoofed', 'delivered', now()->toImmutable(), ['biz_opaque_callback_data' => $stuck->client_reference])], $other);

    expect($stuck->refresh())->status->toBe(MessageStatus::Sending)->whatsapp_message_id->toBeNull();
});

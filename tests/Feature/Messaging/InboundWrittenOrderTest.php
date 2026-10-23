<?php

use App\Models\Connection;
use App\Models\Message;
use App\Models\User;
use Carbon\CarbonImmutable;
use Tests\Support\WhatsAppFixtures;

/*
| Meta does not guarantee the order of its webhooks and they are processed in
| parallel and retried, so a customer's messages may be stored out of order.
| The chat shows them by the time the customer sent them; replies by the time
| they were written. Pages stay delimited by id: nothing repeats or goes
| missing.
*/

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-22 18:00:00', 'UTC'));
    $this->connection = Connection::factory()->operational()->create();
    $this->admin = User::factory()->administrator()->create();
});

test('customer messages stored out of order are shown in the order the customer sent them, with replies in between', function () {
    $sentAt = CarbonImmutable::parse('2026-10-22 17:58:00', 'UTC');
    // The second message is processed first (the first one's webhook was retried).
    $second = receiveWhatsapp($this->connection, '5215512345678', 'Es sobre mi pedido', $sentAt->addSeconds(20), 'wamid.order.2');
    $conversation = $second->conversation;
    $reply = WhatsAppFixtures::outbound($conversation, ['body' => 'Con gusto lo reviso', 'status' => 'accepted']);
    $reply->forceFill(['created_at' => $sentAt->addSeconds(40)])->save();
    $first = receiveWhatsapp($this->connection, '5215512345678', 'Hola', $sentAt, 'wamid.order.1');
    $same = receiveWhatsapp($this->connection, '5215512345678', 'Mismo segundo', $sentAt->addSeconds(20), 'wamid.order.3');

    expect($first->conversation->id)->toBe($conversation->id)
        ->and($first->message->id)->toBeGreaterThan($second->message->id);

    $written = [$first->message->id, $second->message->id, $same->message->id, $reply->id];

    $page = $this->actingAs($this->admin)->getJson(route('conversations.timeline', $conversation))->assertOk()->json();
    $caughtUp = $this->actingAs($this->admin)->getJson(route('conversations.timeline', ['conversation' => $conversation, 'after_message' => 0, 'after_note' => 0, 'after_event' => 0]))->assertOk()->json();
    $refreshed = $this->actingAs($this->admin)->getJson(route('conversations.timeline', ['conversation' => $conversation, 'messages' => array_reverse($written)]))->assertOk()->json();

    expect(array_column($page['messages'], 'id'))->toBe($written)
        ->and(array_column($caughtUp['messages'], 'id'))->toBe($written)
        ->and(array_column($refreshed['messages'], 'id'))->toBe($written)
        ->and(array_column($page['messages'], 'sent_at', 'id'))->toMatchArray([
            $first->message->id => $sentAt->toIso8601String(),
            $second->message->id => $sentAt->addSeconds(20)->toIso8601String(),
        ]);
});

test('older pages keep their id boundaries: a late customer message appears once, in time order within its page', function () {
    $start = CarbonImmutable::parse('2026-10-22 16:00:00', 'UTC');
    $conversation = receiveWhatsapp($this->connection, '5215512345678', 'Mensaje 0', $start, 'wamid.page.0')->conversation;

    foreach (range(1, 44) as $offset) {
        receiveWhatsapp($this->connection, '5215512345678', "Mensaje {$offset}", $start->addMinutes($offset), "wamid.page.{$offset}");
    }

    // Written before everything else, stored last.
    $late = receiveWhatsapp($this->connection, '5215512345678', 'Llegó tarde', $start->subMinute(), 'wamid.page.late')->message;

    $newest = $this->actingAs($this->admin)->getJson(route('conversations.timeline', $conversation))->assertOk()->json();
    $older = $this->actingAs($this->admin)->getJson(route('conversations.timeline', ['conversation' => $conversation, 'before' => min(array_column($newest['messages'], 'id'))]))->assertOk()->json();
    $ids = [...array_column($older['messages'], 'id'), ...array_column($newest['messages'], 'id')];

    expect($ids)->toHaveCount(46)
        ->and(array_unique($ids))->toHaveCount(46)
        ->and($newest['messages'][0]['id'])->toBe($late->id)
        ->and(Message::query()->where('conversation_id', $conversation->id)->count())->toBe(46);
});

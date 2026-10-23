<?php

use App\Enums\ConversationEventType;
use App\Enums\DataScope;
use App\Enums\MessageDirection;
use App\Enums\MessageStatus;
use App\Enums\MessageType;
use App\Enums\Permission;
use App\Models\Conversation;
use App\Models\ConversationEvent;
use App\Models\ConversationNote;
use App\Models\Department;
use App\Models\Message;
use App\Models\User;
use App\Services\Conversations\ConversationTimeline;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia as Assert;

/*
| The chat of the inbox: messages paged by id with a stable cursor, notes and
| operational events travelling with the page whose time span contains them,
| catching up after real-time signals and refreshing items already shown.
| Every request authorizes the conversation again.
*/

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-20 10:00:00', 'UTC'));
    $this->department = Department::factory()->create();
    $this->agent = User::factory()->agent()->available()->inDepartments($this->department)->create();
    $this->colleague = User::factory()->agent()->available()->inDepartments($this->department)->create();
    $this->conversation = Conversation::factory()->inDepartment($this->department)->assignedTo($this->agent)->create();
});

/**
 * Stores messages one minute apart, oldest first.
 *
 * @return list<Message>
 */
function storeMessages(Conversation $conversation, int $count, CarbonImmutable $from): array
{
    $messages = [];

    foreach (range(0, $count - 1) as $offset) {
        $at = $from->addMinutes($offset);
        $message = Message::factory()->create([
            'conversation_id' => $conversation->id,
            'body' => "Mensaje {$offset}",
        ]);
        $message->forceFill(['created_at' => $at, 'sent_at' => $at])->save();
        $messages[] = $message;
    }

    return $messages;
}

function noteAt(Conversation $conversation, User $author, CarbonImmutable $at, string $body = 'Nota'): ConversationNote
{
    $note = ConversationNote::factory()->create(['conversation_id' => $conversation->id, 'user_id' => $author->id, 'body' => $body]);
    $note->forceFill(['created_at' => $at])->save();

    return $note;
}

test('the chat opens on the newest page and older pages follow without gaps or repetitions', function () {
    $start = CarbonImmutable::parse('2026-10-20 08:00:00', 'UTC');
    $messages = storeMessages($this->conversation, ConversationTimeline::PAGE_SIZE + 5, $start);
    $oldNote = noteAt($this->conversation, $this->agent, $start->addMinutes(2)->addSeconds(30), 'Nota antigua');
    $recentNote = noteAt($this->conversation, $this->agent, $start->addMinutes(40)->addSeconds(30), 'Nota reciente');

    $first = $this->actingAs($this->agent)
        ->getJson(route('conversations.timeline', $this->conversation))
        ->assertOk()
        ->assertJsonPath('has_more', true)
        ->json();

    $firstIds = array_column($first['messages'], 'id');
    expect($firstIds)->toHaveCount(ConversationTimeline::PAGE_SIZE)
        ->and($firstIds)->toBe(array_map(fn (Message $message): int => $message->id, array_slice($messages, 5)))
        ->and(array_column($first['notes'], 'id'))->toBe([$recentNote->id]);

    $second = $this->actingAs($this->agent)
        ->getJson(route('conversations.timeline', [$this->conversation, 'before' => $firstIds[0]]))
        ->assertOk()
        ->assertJsonPath('has_more', false)
        ->json();

    expect(array_column($second['messages'], 'id'))->toBe(array_map(fn (Message $message): int => $message->id, array_slice($messages, 0, 5)))
        ->and(array_column($second['notes'], 'id'))->toBe([$oldNote->id]);
});

test('the inbox page carries the newest page of the selected conversation', function () {
    storeMessages($this->conversation, 3, CarbonImmutable::parse('2026-10-20 09:00:00', 'UTC'));

    $this->actingAs($this->agent)
        ->get(route('conversations.index', ['c' => $this->conversation->id]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('conversations/Index')
            ->where('selectedId', $this->conversation->id)
            ->has('timeline.messages', 3)
            ->where('timeline.has_more', false)
            ->where('conversation.id', $this->conversation->id)
            ->has('details.contact')
            ->where('window.allows_free_form', true));
});

test('operational events appear inline only for people with the history permission', function () {
    $this->conversation->events()->create(['type' => ConversationEventType::Accepted, 'actor_user_id' => $this->agent->id, 'created_at' => now()]);
    $this->conversation->events()->create(['type' => ConversationEventType::NoteAdded, 'actor_user_id' => $this->agent->id, 'created_at' => now()]);

    $this->actingAs($this->agent)
        ->getJson(route('conversations.timeline', $this->conversation))
        ->assertOk()
        ->assertJsonCount(1, 'events')
        ->assertJsonPath('events.0.type', 'accepted');

    $withoutHistory = conversationUser([Permission::ViewConversations, Permission::ReplyConversations], departments: [$this->department]);
    $own = Conversation::factory()->inDepartment($this->department)->assignedTo($withoutHistory)->create();
    $own->events()->create(['type' => ConversationEventType::Accepted, 'actor_user_id' => $withoutHistory->id, 'created_at' => now()]);

    $this->actingAs($withoutHistory)
        ->getJson(route('conversations.timeline', $own))
        ->assertOk()
        ->assertJsonCount(0, 'events');
});

test('after a real-time signal only the newer items are returned', function () {
    $messages = storeMessages($this->conversation, 3, CarbonImmutable::parse('2026-10-20 09:00:00', 'UTC'));
    $note = noteAt($this->conversation, $this->agent, CarbonImmutable::parse('2026-10-20 09:30:00', 'UTC'));

    $this->actingAs($this->agent)
        ->getJson(route('conversations.timeline', [$this->conversation, 'after_message' => $messages[1]->id, 'after_note' => 0, 'after_event' => 0]))
        ->assertOk()
        ->assertJsonPath('messages.*.id', [$messages[2]->id])
        ->assertJsonPath('notes.*.id', [$note->id]);

    $this->actingAs($this->agent)
        ->getJson(route('conversations.timeline', [$this->conversation, 'after_message' => $messages[2]->id, 'after_note' => $note->id, 'after_event' => 0]))
        ->assertOk()
        ->assertJsonCount(0, 'messages')
        ->assertJsonCount(0, 'notes');
});

test('catching up never brings notes of pages the chat has not loaded yet', function () {
    $old = noteAt($this->conversation, $this->agent, CarbonImmutable::parse('2026-10-20 08:00:00', 'UTC'), 'Nota antigua');
    $messages = storeMessages($this->conversation, 2, CarbonImmutable::parse('2026-10-20 09:00:00', 'UTC'));
    $new = noteAt($this->conversation, $this->agent, CarbonImmutable::parse('2026-10-20 09:45:00', 'UTC'), 'Nota nueva');

    $this->actingAs($this->agent)
        ->getJson(route('conversations.timeline', [$this->conversation, 'after_message' => $messages[1]->id, 'after_note' => 0, 'after_event' => 0]))
        ->assertOk()
        ->assertJsonPath('notes.*.id', [$new->id])
        ->assertDontSee('Nota antigua');

    $this->actingAs($this->agent)
        ->getJson(route('conversations.timeline', [$this->conversation, 'before' => $messages[0]->id]))
        ->assertOk()
        ->assertJsonPath('notes.*.id', [$old->id]);
});

test('refreshing shown items returns their current state and ignores ids of other conversations', function () {
    $reply = Message::factory()->create([
        'conversation_id' => $this->conversation->id,
        'direction' => MessageDirection::Outbound,
        'status' => MessageStatus::Queued,
        'whatsapp_message_id' => null,
        'sender_user_id' => $this->agent->id,
    ]);
    $foreign = Message::factory()->create(['body' => 'Mensaje de otra persona']);
    $reply->forceFill(['status' => MessageStatus::Delivered, 'delivered_at' => now()])->save();

    $this->actingAs($this->agent)
        ->getJson(route('conversations.timeline', [$this->conversation, 'messages' => [$reply->id, $foreign->id]]))
        ->assertOk()
        ->assertJsonPath('messages.*.id', [$reply->id])
        ->assertJsonPath('messages.0.status', 'delivered')
        ->assertDontSee('Mensaje de otra persona');
});

test('a cursor that belongs to another conversation returns nothing', function () {
    storeMessages($this->conversation, 2, CarbonImmutable::parse('2026-10-20 09:00:00', 'UTC'));
    $foreign = Message::factory()->create();

    $this->actingAs($this->agent)
        ->getJson(route('conversations.timeline', [$this->conversation, 'before' => $foreign->id]))
        ->assertOk()
        ->assertJsonCount(0, 'messages')
        ->assertJsonPath('has_more', false);
});

test('structured messages expose only display fields, never raw provider payloads', function () {
    $location = Message::factory()->create([
        'conversation_id' => $this->conversation->id,
        'type' => MessageType::Location,
        'body' => 'Oficina',
        'metadata' => ['content' => ['latitude' => 19.4326, 'longitude' => -99.1332, 'name' => 'Oficina', 'address' => 'Centro', 'secret' => 'no']],
    ]);
    $target = Message::factory()->create(['conversation_id' => $this->conversation->id, 'whatsapp_message_id' => 'wamid.target']);
    Message::factory()->create([
        'conversation_id' => $this->conversation->id,
        'type' => MessageType::Reaction,
        'body' => '👍',
        'metadata' => ['content' => ['message_id' => 'wamid.target', 'emoji' => '👍']],
    ]);

    $response = $this->actingAs($this->agent)
        ->getJson(route('conversations.timeline', $this->conversation))
        ->assertOk();

    $byId = collect($response->json('messages'))->keyBy('id');

    expect($byId[$location->id]['content'])->toBe(['latitude' => 19.4326, 'longitude' => -99.1332, 'name' => 'Oficina', 'address' => 'Centro'])
        ->and($byId->firstWhere('type', 'reaction')['content'])->toBe(['emoji' => '👍', 'reacted_message_id' => $target->id]);
    $response->assertDontSee('secret');
});

test('nobody outside the conversation\'s reach reads its chat, whatever they ask for', function (array $query) {
    storeMessages($this->conversation, 2, CarbonImmutable::parse('2026-10-20 09:00:00', 'UTC'));

    $this->actingAs($this->colleague)
        ->getJson(route('conversations.timeline', [$this->conversation, ...$query]))
        ->assertNotFound();

    $supervisorOfOtherDepartment = User::factory()->supervisor()->inDepartments(Department::factory()->create())->create();

    $this->actingAs($supervisorOfOtherDepartment)
        ->getJson(route('conversations.timeline', [$this->conversation, ...$query]))
        ->assertNotFound();
})->with([
    'newest page' => [[]],
    'after' => [['after_message' => 0]],
    'refresh' => [['messages' => [1, 2]]],
]);

test('a colleague\'s conversation selected by id is not revealed in the inbox', function () {
    storeMessages($this->conversation, 2, CarbonImmutable::parse('2026-10-20 09:00:00', 'UTC'));

    $this->actingAs($this->colleague)
        ->get(route('conversations.index', ['c' => $this->conversation->id]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('selectedId', null)
            ->where('selectionMissing', true)
            ->where('conversation', null)
            ->where('timeline', null)
            ->where('details', null));
});

test('the details panel only summarizes previous conversations the viewer may see', function () {
    $previousOwn = Conversation::factory()->inDepartment($this->department)->assignedTo($this->agent)->closed()->create(['contact_id' => $this->conversation->contact_id, 'connection_id' => $this->conversation->connection_id]);
    Conversation::factory()->inDepartment($this->department)->assignedTo($this->colleague)->closed()->create(['contact_id' => $this->conversation->contact_id, 'connection_id' => $this->conversation->connection_id]);

    $this->actingAs($this->agent)
        ->get(route('conversations.index', ['c' => $this->conversation->id]))
        ->assertInertia(fn (Assert $page) => $page
            ->where('details.history', fn ($history) => collect($history)->pluck('id')->all() === [$previousOwn->id]));

    $admin = User::factory()->administrator()->create();

    $this->actingAs($admin)
        ->get(route('conversations.index', ['c' => $this->conversation->id]))
        ->assertInertia(fn (Assert $page) => $page->has('details.history', 2));
});

test('a team scope user only opens conversations of their departments', function () {
    $supervisor = conversationUser([Permission::ViewConversations, Permission::ViewConversationHistory], DataScope::Departments, [$this->department]);
    $elsewhere = Conversation::factory()->inDepartment(Department::factory()->create())->create();

    $this->actingAs($supervisor)->getJson(route('conversations.timeline', $this->conversation))->assertOk();
    $this->actingAs($supervisor)->getJson(route('conversations.timeline', $elsewhere))->assertNotFound();
});

test('notes and events beyond the per-page limit are never lost: every one arrives once across the pages', function () {
    $messages = storeMessages($this->conversation, 3, CarbonImmutable::parse('2026-10-20 08:00:00', 'UTC'));
    $start = CarbonImmutable::parse('2026-10-20 07:50:00', 'UTC');
    $total = ConversationTimeline::SIDE_LIMIT + 50;

    ConversationNote::query()->insert(array_map(fn (int $second): array => [
        'conversation_id' => $this->conversation->id,
        'user_id' => $this->agent->id,
        'body' => "Nota {$second}",
        'created_at' => $start->addSeconds($second),
        'updated_at' => $start->addSeconds($second),
    ], range(0, $total - 1)));
    foreach (range(1, 5) as $minute) {
        $this->conversation->events()->create(['type' => ConversationEventType::Accepted, 'actor_user_id' => $this->agent->id, 'created_at' => $start->addMinutes($minute)]);
    }

    $notes = [];
    $events = [];
    $seenMessages = [];
    $until = null;
    $pages = 0;

    do {
        $before = $seenMessages === [] ? null : min($seenMessages);
        $page = $this->actingAs($this->agent)
            ->getJson(route('conversations.timeline', [$this->conversation, ...array_filter(['before' => $before, 'until' => $until])]))
            ->assertOk()
            ->json();

        expect(count($page['notes']))->toBeLessThanOrEqual(ConversationTimeline::SIDE_LIMIT);
        $notes = [...$notes, ...array_column($page['notes'], 'id')];
        $events = [...$events, ...array_column($page['events'], 'id')];
        $seenMessages = [...$seenMessages, ...array_column($page['messages'], 'id')];
        $until = $page['until'] ?? null;
        $pages++;
    } while ($page['has_more'] && $pages < 10);

    expect($pages)->toBe(2)
        ->and($notes)->toHaveCount($total)
        ->and(array_unique($notes))->toHaveCount($total)
        ->and($events)->toHaveCount(5)
        ->and(array_unique($seenMessages))->toEqualCanonicalizing(array_map(fn (Message $message): int => $message->id, $messages));
});

test('notes and events stored in the same second beyond the per-page limit still arrive once each, page after page', function () {
    $messages = storeMessages($this->conversation, 2, CarbonImmutable::parse('2026-10-20 08:00:00', 'UTC'));
    $second = CarbonImmutable::parse('2026-10-20 07:55:00', 'UTC');
    $noteCount = ConversationTimeline::SIDE_LIMIT * 2 + 10;
    $eventCount = ConversationTimeline::SIDE_LIMIT + 5;

    ConversationNote::query()->insert(array_map(fn (int $index): array => [
        'conversation_id' => $this->conversation->id,
        'user_id' => $this->agent->id,
        'body' => "Nota {$index}",
        'created_at' => $second,
        'updated_at' => $second,
    ], range(1, $noteCount)));
    ConversationEvent::query()->insert(array_map(fn (int $index): array => [
        'conversation_id' => $this->conversation->id,
        'type' => ConversationEventType::Accepted->value,
        'actor_user_id' => $this->agent->id,
        'created_at' => $second,
    ], range(1, $eventCount)));

    $notes = [];
    $events = [];
    $seenMessages = [];
    $until = null;
    $pages = 0;

    do {
        $before = $seenMessages === [] ? null : min($seenMessages);
        $page = $this->actingAs($this->agent)
            ->getJson(route('conversations.timeline', [$this->conversation, ...array_filter(['before' => $before, 'until' => $until])]))
            ->assertOk()
            ->json();

        $notes = [...$notes, ...array_column($page['notes'], 'id')];
        $events = [...$events, ...array_column($page['events'], 'id')];
        $seenMessages = [...$seenMessages, ...array_column($page['messages'], 'id')];
        $until = $page['until'] ?? null;
        $pages++;
    } while ($page['has_more'] && $pages < 10);

    expect($pages)->toBe(3)
        ->and($notes)->toHaveCount($noteCount)
        ->and(array_unique($notes))->toHaveCount($noteCount)
        ->and($events)->toHaveCount($eventCount)
        ->and(array_unique($events))->toHaveCount($eventCount)
        ->and(array_unique($seenMessages))->toEqualCanonicalizing(array_map(fn (Message $message): int => $message->id, $messages));
});

test('a malformed until is rejected', function (string $until) {
    $this->actingAs($this->agent)
        ->getJson(route('conversations.timeline', [$this->conversation, 'until' => $until]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('until');
})->with(['2026-13-40 10:00:00', '2026-10-20 10:00:00,x,1', 'ayer']);

test('catching up says there is more when a stream reached its limit, and the rest follows', function () {
    $now = now();
    $rows = Message::factory()->count(ConversationTimeline::AFTER_LIMIT + 1)->make(['conversation_id' => $this->conversation->id]);
    Message::query()->insert($rows->map(fn (Message $message): array => [...$message->getAttributes(), 'created_at' => $now, 'updated_at' => $now])->all());

    $first = $this->actingAs($this->agent)
        ->getJson(route('conversations.timeline', [$this->conversation, 'after_message' => 0, 'after_note' => 0, 'after_event' => 0]))
        ->assertOk()
        ->assertJsonCount(ConversationTimeline::AFTER_LIMIT, 'messages')
        ->assertJsonPath('has_more', true)
        ->json();

    $this->actingAs($this->agent)
        ->getJson(route('conversations.timeline', [$this->conversation, 'after_message' => max(array_column($first['messages'], 'id')), 'after_note' => 0, 'after_event' => 0]))
        ->assertOk()
        ->assertJsonCount(1, 'messages')
        ->assertJsonPath('has_more', false);
});

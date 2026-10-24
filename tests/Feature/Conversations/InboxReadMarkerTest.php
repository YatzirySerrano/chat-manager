<?php

use App\Enums\MessageDirection;
use App\Enums\MessageStatus;
use App\Events\Conversations\ConversationChanged;
use App\Models\Conversation;
use App\Models\ConversationRead;
use App\Models\Department;
use App\Models\Message;
use App\Models\Tag;
use App\Models\User;
use Illuminate\Support\Facades\Event;
use Inertia\Testing\AssertableInertia as Assert;

/*
| Personal read markers: each person has their own unread counter per
| conversation, moved forward while the chat is on screen and never back.
| The list shows the latest message and the counter only for rows the
| viewer is already allowed to see.
*/

beforeEach(function () {
    $this->department = Department::factory()->create();
    $this->agent = User::factory()->agent()->available()->inDepartments($this->department)->create();
    $this->colleague = User::factory()->agent()->available()->inDepartments($this->department)->create();
    $this->conversation = Conversation::factory()->inDepartment($this->department)->assignedTo($this->agent)->create();
});

/**
 * The list row of the conversation as the viewer gets it.
 *
 * @return array<string, mixed>|null
 */
function listedRow(object $test, User $viewer, Conversation $conversation, array $query = []): ?array
{
    $rows = $test->actingAs($viewer)->get(route('conversations.index', $query))->viewData('page')['props']['conversations']['data'];

    return collect($rows)->firstWhere('id', $conversation->id);
}

test('the list counts the customer messages after the viewer\'s marker and previews the latest one', function () {
    $first = Message::factory()->create(['conversation_id' => $this->conversation->id, 'body' => 'Hola']);
    Message::factory()->create(['conversation_id' => $this->conversation->id, 'body' => '¿Siguen ahí?']);

    expect(listedRow($this, $this->agent, $this->conversation)['unread_count'])->toBe(2);

    Message::factory()->create([
        'conversation_id' => $this->conversation->id,
        'direction' => MessageDirection::Outbound,
        'status' => MessageStatus::Sent,
        'body' => 'Sí, aquí estamos',
        'sender_user_id' => $this->agent->id,
    ]);

    $row = listedRow($this, $this->agent, $this->conversation);

    // Without a marker, what a person already answered is not unread.
    expect($row['unread_count'])->toBe(0)
        ->and($row['last_message']['body'])->toBe('Sí, aquí estamos')
        ->and($row['last_message']['direction'])->toBe('outbound');

    ConversationRead::query()->forceCreate(['user_id' => $this->agent->id, 'conversation_id' => $this->conversation->id, 'last_read_message_id' => $first->id, 'read_at' => now()]);

    // The personal marker decides once it exists.
    expect(listedRow($this, $this->agent, $this->conversation)['unread_count'])->toBe(1);
});

test('opening the chat moves the marker forward, never back, and signals the change once', function () {
    Event::fake([ConversationChanged::class]);
    $first = Message::factory()->create(['conversation_id' => $this->conversation->id]);
    $second = Message::factory()->create(['conversation_id' => $this->conversation->id]);

    $this->actingAs($this->agent)->postJson(route('conversations.read', $this->conversation), ['message_id' => $second->id])
        ->assertOk()->assertJsonPath('moved', true);
    $this->actingAs($this->agent)->postJson(route('conversations.read', $this->conversation), ['message_id' => $first->id])
        ->assertOk()->assertJsonPath('moved', false);

    expect(ConversationRead::query()->sole()->last_read_message_id)->toBe($second->id);
    Event::assertDispatchedTimes(ConversationChanged::class, 1);
    Event::assertDispatched(ConversationChanged::class, fn (ConversationChanged $event) => $event->reason === 'read' && $event->conversationId === $this->conversation->id);
});

test('a marker cannot point past the conversation\'s own messages', function () {
    $own = Message::factory()->create(['conversation_id' => $this->conversation->id]);
    $foreign = Message::factory()->create();

    $this->actingAs($this->agent)->postJson(route('conversations.read', $this->conversation), ['message_id' => $foreign->id])->assertOk();

    expect(ConversationRead::query()->sole()->last_read_message_id)->toBe($own->id);
});

test('markers are personal: one person reading does not clear the counter of another', function () {
    $admin = User::factory()->administrator()->create();
    $message = Message::factory()->create(['conversation_id' => $this->conversation->id]);

    $this->actingAs($admin)->postJson(route('conversations.read', $this->conversation), ['message_id' => $message->id])->assertOk();

    expect(listedRow($this, $this->agent, $this->conversation)['unread_count'])->toBe(1)
        // The administrator does not attend it: it is in their "all" view.
        ->and(listedRow($this, $admin, $this->conversation, ['view' => 'all'])['unread_count'])->toBe(0);
});

test('nobody can create a marker on a conversation outside their reach', function () {
    Message::factory()->create(['conversation_id' => $this->conversation->id]);

    $this->actingAs($this->colleague)->postJson(route('conversations.read', $this->conversation))->assertNotFound();

    expect(ConversationRead::query()->count())->toBe(0);
});

test('a colleague\'s attended conversation never reaches the list, its previews or its counters', function () {
    Message::factory()->create(['conversation_id' => $this->conversation->id, 'body' => 'Dato privado del cliente']);

    $this->actingAs($this->colleague)
        ->get(route('conversations.index', ['status' => 'all']))
        ->assertOk()
        ->assertDontSee('Dato privado del cliente')
        ->assertInertia(fn (Assert $page) => $page
            ->where('conversations.data', [])
            ->where('counts', fn ($counts) => collect($counts)->sum() === 0));

    $this->actingAs($this->colleague)
        ->get(route('conversations.index', ['search' => $this->conversation->contact->name, 'view' => 'queue']))
        ->assertInertia(fn (Assert $page) => $page->where('conversations.data', []));
});

test('the list loads incrementally from where the first page ended', function () {
    Conversation::factory()->count(30)->inDepartment($this->department)->assignedTo($this->agent)->create();

    $first = $this->actingAs($this->agent)
        ->get(route('conversations.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->has('conversations.data', 25)
            ->where('conversations.has_more', true))
        ->inertiaProps('conversations.next_cursor');

    expect($first)->toBeString();

    $this->actingAs($this->agent)
        ->getJson(route('conversations.page', ['after' => $first]))
        ->assertOk()
        ->assertJsonCount(6, 'data')
        ->assertJsonPath('has_more', false);
});

test('the list can be sorted by longest waiting and filtered by tag', function () {
    $older = Conversation::factory()->inDepartment($this->department)->assignedTo($this->agent)->create(['last_message_at' => now()->subDay()]);
    $tag = Tag::factory()->create();
    $older->tags()->attach($tag->id, ['tagged_by_user_id' => $this->agent->id, 'created_at' => now()]);

    $this->actingAs($this->agent)
        ->get(route('conversations.index', ['sort' => 'oldest']))
        ->assertInertia(fn (Assert $page) => $page
            ->where('conversations.data.0.id', $older->id)
            ->where('filters.sort', 'oldest'));

    $this->actingAs($this->agent)
        ->get(route('conversations.index', ['tag' => $tag->id]))
        ->assertInertia(fn (Assert $page) => $page
            ->where('conversations.data', fn ($rows) => collect($rows)->pluck('id')->all() === [$older->id])
            ->where('conversations.data.0.tags.0.id', $tag->id));
});

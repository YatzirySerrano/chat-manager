<?php

use App\Enums\ChatAppearancePreset;
use App\Enums\MessageDirection;
use App\Enums\MessageStatus;
use App\Events\Broadcasts\RealtimeSignal;
use App\Events\Conversations\ConversationChanged;
use App\Models\ChatAppearance;
use App\Models\Conversation;
use App\Models\ConversationRead;
use App\Models\Department;
use App\Models\Message;
use App\Models\User;
use App\Notifications\ConversationMessageNotification;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;

/*
| The inbox preview (long press / right click on a row) shows the latest
| messages and changes nothing: no read marker, counter, notification,
| conversation state, real-time signal or provider request. Only people
| who can open the conversation can preview it.
*/

beforeEach(function () {
    $this->department = Department::factory()->create();
    $this->agent = User::factory()->agent()->available()->inDepartments($this->department)->create();
    $this->conversation = Conversation::factory()->inDepartment($this->department)->assignedTo($this->agent)->create();
});

/**
 * The unread counter of the conversation's list row for the viewer.
 */
function previewedUnread(object $test, User $viewer, Conversation $conversation): ?int
{
    $rows = $test->actingAs($viewer)->get(route('conversations.index'))->viewData('page')['props']['conversations']['data'];

    return collect($rows)->firstWhere('id', $conversation->id)['unread_count'] ?? null;
}

test('the preview lists the latest messages, oldest first, with who wrote each one', function () {
    Message::factory()->create(['conversation_id' => $this->conversation->id, 'body' => 'Hola, ¿tienen envíos?']);
    Message::factory()->create([
        'conversation_id' => $this->conversation->id,
        'direction' => MessageDirection::Outbound,
        'status' => MessageStatus::Sent,
        'body' => 'Sí, a todo el país.',
        'sender_user_id' => $this->agent->id,
    ]);

    $this->actingAs($this->agent)->getJson(route('conversations.preview', $this->conversation))
        ->assertOk()
        ->assertJsonPath('has_more', false)
        ->assertJsonPath('messages.0.body', 'Hola, ¿tienen envíos?')
        ->assertJsonPath('messages.0.direction', 'inbound')
        ->assertJsonPath('messages.1.body', 'Sí, a todo el país.')
        ->assertJsonPath('messages.1.direction', 'outbound');
});

test('the preview is bounded to the latest messages and says there are more', function () {
    Message::factory()->count(25)->create(['conversation_id' => $this->conversation->id]);
    $latest = Message::factory()->create(['conversation_id' => $this->conversation->id, 'body' => 'El último']);

    $this->actingAs($this->agent)->getJson(route('conversations.preview', $this->conversation))
        ->assertOk()
        ->assertJsonCount(20, 'messages')
        ->assertJsonPath('has_more', true)
        ->assertJsonPath('messages.19.id', $latest->id);
});

test('previewing writes nothing, signals nothing and calls no provider', function () {
    Http::preventStrayRequests();
    Message::factory()->count(3)->create(['conversation_id' => $this->conversation->id]);
    $before = $this->conversation->fresh()->only(['status', 'assigned_user_id', 'version', 'last_message_at', 'updated_at', 'first_response_at']);
    Event::fake([ConversationChanged::class, RealtimeSignal::class]);

    $writes = databaseWritesDuring(fn () => $this->actingAs($this->agent)
        ->getJson(route('conversations.preview', $this->conversation))
        ->assertOk());

    expect($writes)->toBe([])
        ->and($this->conversation->fresh()->only(array_keys($before)))->toEqual($before)
        ->and(ConversationRead::query()->count())->toBe(0);
    Event::assertNotDispatched(ConversationChanged::class);
    Event::assertNotDispatched(RealtimeSignal::class);
});

test('the unread counter stays the same after previewing, follows new messages and is cleared only by opening the chat', function () {
    Message::factory()->count(2)->create(['conversation_id' => $this->conversation->id]);

    $this->actingAs($this->agent)->getJson(route('conversations.preview', $this->conversation))->assertOk();
    expect(previewedUnread($this, $this->agent, $this->conversation))->toBe(2);

    // A message arriving while the preview is open keeps counting.
    $newest = Message::factory()->create(['conversation_id' => $this->conversation->id]);
    $this->actingAs($this->agent)->getJson(route('conversations.preview', $this->conversation))
        ->assertJsonPath('messages.2.id', $newest->id);
    expect(previewedUnread($this, $this->agent, $this->conversation))->toBe(3);

    // Opening the chat keeps the existing behavior: the marker moves.
    $this->actingAs($this->agent)->postJson(route('conversations.read', $this->conversation), ['message_id' => $newest->id])
        ->assertJsonPath('moved', true);
    expect(previewedUnread($this, $this->agent, $this->conversation))->toBe(0)
        ->and(ConversationRead::query()->sole()->last_read_message_id)->toBe($newest->id);
});

test('previewing does not read the conversation\'s notifications', function () {
    $message = Message::factory()->create(['conversation_id' => $this->conversation->id]);
    $this->agent->notify(new ConversationMessageNotification($this->conversation->id, $message->id));
    $unread = $this->agent->unreadNotifications()->count();

    $this->actingAs($this->agent)->getJson(route('conversations.preview', $this->conversation))->assertOk();

    expect($unread)->toBe(1)
        ->and($this->agent->unreadNotifications()->count())->toBe(1);
});

test('a conversation outside the viewer\'s reach cannot be previewed', function () {
    Message::factory()->create(['conversation_id' => $this->conversation->id, 'body' => 'Dato privado del cliente']);
    $colleague = User::factory()->agent()->inDepartments($this->department)->create();

    $this->actingAs($colleague)->getJson(route('conversations.preview', $this->conversation))
        ->assertNotFound()
        ->assertDontSee('Dato privado del cliente');
});

test('a guest is sent to sign in', function () {
    $this->get(route('conversations.preview', $this->conversation))->assertRedirect(route('login'));
});

test('the preview shows the viewer\'s own colors for the conversation, else their default, else the theme', function () {
    $this->actingAs($this->agent)->getJson(route('conversations.preview', $this->conversation))
        ->assertJsonPath('appearance', ['source' => 'system', 'colors' => null]);

    ChatAppearance::factory()->for($this->agent)->create(['colors' => ChatAppearancePreset::Ocean->colors()]);
    $this->actingAs($this->agent)->getJson(route('conversations.preview', $this->conversation))
        ->assertJsonPath('appearance', ['source' => 'default', 'colors' => ChatAppearancePreset::Ocean->colors()]);

    ChatAppearance::factory()->for($this->agent)->forConversation()->create(['colors' => ChatAppearancePreset::Forest->colors()]);
    ChatAppearance::factory()->for($this->agent)->forConversation($this->conversation)->create(['colors' => ChatAppearancePreset::Sand->colors()]);
    $this->actingAs($this->agent)->getJson(route('conversations.preview', $this->conversation))
        ->assertJsonPath('appearance', ['source' => 'conversation', 'colors' => ChatAppearancePreset::Sand->colors()]);
});

test('a supervisor previewing an agent\'s conversation sees their own colors, never the agent\'s', function () {
    $supervisor = User::factory()->supervisor()->inDepartments($this->department)->create();
    ChatAppearance::factory()->for($this->agent)->create(['colors' => ChatAppearancePreset::Ocean->colors()]);
    ChatAppearance::factory()->for($this->agent)->forConversation($this->conversation)->create(['colors' => ChatAppearancePreset::Forest->colors()]);

    $this->actingAs($supervisor)->getJson(route('conversations.preview', $this->conversation))
        ->assertOk()
        ->assertJsonPath('appearance', ['source' => 'system', 'colors' => null]);

    ChatAppearance::factory()->for($supervisor)->create(['colors' => ChatAppearancePreset::Sand->colors()]);
    $this->actingAs($supervisor)->getJson(route('conversations.preview', $this->conversation))
        ->assertJsonPath('appearance', ['source' => 'default', 'colors' => ChatAppearancePreset::Sand->colors()]);
});

test('previewing with personal colors creates no appearance and moves no read marker', function () {
    Message::factory()->count(2)->create(['conversation_id' => $this->conversation->id]);
    $default = ChatAppearance::factory()->for($this->agent)->create(['colors' => ChatAppearancePreset::Ocean->colors()]);

    $writes = databaseWritesDuring(fn () => $this->actingAs($this->agent)
        ->getJson(route('conversations.preview', $this->conversation))
        ->assertJsonPath('appearance.source', 'default'));

    expect($writes)->toBe([])
        ->and(ChatAppearance::query()->sole()->is($default))->toBeTrue()
        ->and(ConversationRead::query()->count())->toBe(0)
        ->and(previewedUnread($this, $this->agent, $this->conversation))->toBe(2);
});

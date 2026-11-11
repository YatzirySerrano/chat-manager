<?php

use App\Enums\ChatAppearancePreset;
use App\Enums\MessageDirection;
use App\Enums\MessageStatus;
use App\Enums\MessageType;
use App\Events\Broadcasts\RealtimeSignal;
use App\Events\Conversations\ConversationChanged;
use App\Models\ChatAppearance;
use App\Models\Conversation;
use App\Models\ConversationCategory;
use App\Models\ConversationEvent;
use App\Models\ConversationRead;
use App\Models\Department;
use App\Models\Message;
use App\Models\MessageAttachment;
use App\Models\User;
use App\Notifications\ConversationMessageNotification;
use Carbon\CarbonImmutable;
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

test('the preview tells what the request is: stage, received time, area, category, files and availability', function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-10 16:00:00', 'UTC'));
    $category = ConversationCategory::factory()->create(['name' => 'Facturación']);
    $this->department->forceFill(['name' => 'Soporte', 'service_rules' => ['queue.min_wait_minutes' => 5]])->save();
    $pending = Conversation::factory()->inDepartment($this->department)->create([
        'category_id' => $category->id,
        'queued_at' => CarbonImmutable::parse('2026-11-10 15:58:00', 'UTC'),
    ]);
    $first = Message::factory()->create([
        'conversation_id' => $pending->id,
        'body' => 'Necesito mi factura',
        'sent_at' => CarbonImmutable::parse('2026-11-10 15:57:30', 'UTC'),
    ]);
    $photo = Message::factory()->create(['conversation_id' => $pending->id, 'type' => MessageType::Image, 'body' => null]);
    (new MessageAttachment(['disk' => 'local', 'path' => 'media/2026/11/foto', 'mime_type' => 'image/jpeg', 'file_name' => 'foto.jpg', 'size_bytes' => 10]))
        ->forceFill(['message_id' => $photo->id])->save();

    $this->actingAs($this->agent)->getJson(route('conversations.preview', $pending))
        ->assertOk()
        ->assertJsonPath('first_message', null)
        ->assertJsonPath('messages.0.id', $first->id)
        ->assertJsonPath('conversation.stage', ['value' => 'new', 'label' => 'Nueva'])
        ->assertJsonPath('conversation.status', 'pending')
        ->assertJsonPath('conversation.received_at', CarbonImmutable::parse('2026-11-10 15:57:30', 'UTC')->toIso8601String())
        ->assertJsonPath('conversation.department.name', 'Soporte')
        ->assertJsonPath('conversation.category.name', 'Facturación')
        ->assertJsonPath('conversation.readiness.value', 'ready')
        ->assertJsonPath('conversation.attachments', ['total' => 1, 'by_type' => ['image' => 1]])
        ->assertJsonPath('conversation.acceptance.allowed', false)
        ->assertJsonPath('conversation.acceptance.code', 'min_wait')
        ->assertJsonPath('conversation.acceptance.available_at', CarbonImmutable::parse('2026-11-10 16:03:00', 'UTC')->toIso8601String());
});

test('the preview names the stage of the conversation', function (string $state, string $stage) {
    $conversation = match ($state) {
        'attended' => $this->conversation,
        'returned to the queue' => Conversation::factory()->inDepartment($this->department)->create(['first_response_at' => now()]),
        'closed' => Conversation::factory()->inDepartment($this->department)->assignedTo($this->agent)->closed()->create(),
    };

    $this->actingAs($this->agent)->getJson(route('conversations.preview', $conversation))
        ->assertOk()
        ->assertJsonPath('conversation.stage.value', $stage)
        ->assertJsonPath('conversation.acceptance', $state === 'returned to the queue'
            ? ['allowed' => true, 'available' => true, 'code' => null, 'message' => null, 'available_at' => null, 'blocking_conversation_id' => null, 'overridable' => false]
            : null);
})->with([
    'attended' => ['attended', 'in_progress'],
    'returned to the queue' => ['returned to the queue', 'pending'],
    'closed' => ['closed', 'finished'],
]);

test('a long conversation shows the message it started with apart from the latest ones', function () {
    $first = Message::factory()->create(['conversation_id' => $this->conversation->id, 'body' => 'Mi pedido llegó incompleto']);
    Message::factory()->count(22)->create(['conversation_id' => $this->conversation->id]);

    $this->actingAs($this->agent)->getJson(route('conversations.preview', $this->conversation))
        ->assertOk()
        ->assertJsonCount(20, 'messages')
        ->assertJsonPath('first_message.id', $first->id)
        ->assertJsonPath('first_message.body', 'Mi pedido llegó incompleto');
});

test('previewing a request restrained by every queue rule still writes nothing and keeps its place in the queue', function () {
    $this->department->forceFill(['service_rules' => ['intake.confirmation_required' => true, 'queue.min_wait_minutes' => 5, 'queue.chronological_order' => true]])->save();
    $head = Conversation::factory()->inDepartment($this->department)->create(['queued_at' => now()->subHour()]);
    $pending = Conversation::factory()->inDepartment($this->department)->create(['queued_at' => now()->subMinutes(30)]);
    Message::factory()->count(25)->create(['conversation_id' => $pending->id]);
    $before = $pending->fresh()->getAttributes();
    Event::fake([ConversationChanged::class, RealtimeSignal::class]);

    $writes = databaseWritesDuring(fn () => $this->actingAs($this->agent)
        ->getJson(route('conversations.preview', $pending))
        ->assertOk()
        ->assertJsonPath('conversation.acceptance.code', 'queue_order')
        ->assertJsonPath('conversation.acceptance.blocking_conversation_id', $head->id));

    expect($writes)->toBe([])
        ->and($pending->fresh()->getAttributes())->toEqual($before)
        ->and(ConversationRead::query()->count())->toBe(0)
        ->and(ConversationEvent::query()->count())->toBe(0);
    Event::assertNotDispatched(ConversationChanged::class);
    Event::assertNotDispatched(RealtimeSignal::class);
});

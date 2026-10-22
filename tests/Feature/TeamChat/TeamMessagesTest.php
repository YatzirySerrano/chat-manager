<?php

use App\Events\TeamChat\Broadcasts\TeamChatUserSignal;
use App\Events\TeamChat\Broadcasts\TeamThreadSignal;
use App\Events\TeamChat\TeamMessagePosted;
use App\Models\Department;
use App\Models\Message;
use App\Models\TeamChat\TeamMessage;
use App\Models\TeamChat\TeamThread;
use App\Models\User;
use App\Services\TeamChat\TeamThreadActivity;
use Illuminate\Support\Facades\Event;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $department = Department::factory()->create();
    $this->ana = User::factory()->agent()->inDepartments($department)->create(['name' => 'Ana']);
    $this->beto = User::factory()->agent()->inDepartments($department)->create(['name' => 'Beto']);
    $this->thread = TeamThread::factory()->direct($this->ana, $this->beto)->create();
});

test('a multiline message is stored as text, counts as read for its author and never becomes a customer message', function () {
    Event::fake([TeamMessagePosted::class]);

    $this->actingAs($this->ana)
        ->postJson(route('team-chat.threads.messages.store', $this->thread), ['body' => "  Hola Beto\r\n<b>¿revisas</b> el caso?  "])
        ->assertCreated()
        ->assertJsonPath('data.body', "Hola Beto\n<b>¿revisas</b> el caso?")
        ->assertJsonPath('data.is_mine', true)
        ->assertJsonPath('data.can.edit', true);

    $message = TeamMessage::query()->sole();

    expect($this->thread->fresh()->last_message_id)->toBe($message->id)
        ->and($this->thread->participationOf($this->ana)->last_read_message_id)->toBe($message->id)
        ->and(Message::query()->count())->toBe(0);

    Event::assertDispatched(TeamMessagePosted::class, fn (TeamMessagePosted $event) => $event->messageId === $message->id && $event->threadId === $this->thread->id);
});

test('an empty message or one over the limit is rejected', function () {
    $this->actingAs($this->ana)
        ->postJson(route('team-chat.threads.messages.store', $this->thread), ['body' => "   \n "])
        ->assertJsonValidationErrors(['body' => 'Escribe un mensaje o adjunta un archivo.']);

    config(['team-chat.message_max_length' => 10]);

    $this->actingAs($this->ana)
        ->postJson(route('team-chat.threads.messages.store', $this->thread), ['body' => str_repeat('a', 11)])
        ->assertJsonValidationErrors(['body' => 'El mensaje no debe tener más de 10 caracteres.']);

    expect(TeamMessage::query()->count())->toBe(0);
});

test('a reply must point to a message of the same thread', function () {
    $other = TeamThread::factory()->withParticipants([$this->ana])->create();
    $foreign = TeamMessage::factory()->in($other, $this->ana)->create();
    $local = TeamMessage::factory()->in($this->thread, $this->beto)->create(['body' => 'Pregunta original']);

    $this->actingAs($this->ana)
        ->postJson(route('team-chat.threads.messages.store', $this->thread), ['body' => 'Sí', 'reply_to_message_id' => $foreign->id])
        ->assertJsonValidationErrors(['reply_to_message_id' => 'El mensaje al que respondes ya no está disponible.']);

    $this->actingAs($this->ana)
        ->postJson(route('team-chat.threads.messages.store', $this->thread), ['body' => 'Sí', 'reply_to_message_id' => $local->id])
        ->assertCreated()
        ->assertJsonPath('data.reply_to.id', $local->id)
        ->assertJsonPath('data.reply_to.excerpt', 'Pregunta original');
});

test('only the author edits a message and it is marked as edited', function () {
    $message = TeamMessage::factory()->in($this->thread, $this->beto)->create(['body' => 'Original']);

    $this->actingAs($this->ana)
        ->putJson(route('team-chat.threads.messages.update', [$this->thread, $message]), ['body' => 'Cambiado'])
        ->assertForbidden();

    $this->actingAs($this->beto)
        ->putJson(route('team-chat.threads.messages.update', [$this->thread, $message]), ['body' => 'Corregido'])
        ->assertOk()
        ->assertJsonPath('data.body', 'Corregido')
        ->assertJsonPath('data.edited', true)
        ->assertJsonPath('changed', true);

    $this->actingAs($this->beto)
        ->putJson(route('team-chat.threads.messages.update', [$this->thread, $message]), ['body' => ''])
        ->assertJsonValidationErrors(['body' => 'El mensaje no puede quedar vacío. Si ya no lo necesitas, elimínalo.']);
});

test('deleting a message erases its content for everyone and keeps a marker', function () {
    $message = TeamMessage::factory()->in($this->thread, $this->beto)->create(['body' => 'Dato sensible']);

    $this->actingAs($this->ana)
        ->deleteJson(route('team-chat.threads.messages.destroy', [$this->thread, $message]))
        ->assertForbidden();

    $this->actingAs($this->beto)
        ->deleteJson(route('team-chat.threads.messages.destroy', [$this->thread, $message]))
        ->assertOk()
        ->assertJsonPath('data.deleted', true)
        ->assertJsonPath('data.body', null);

    $message->refresh();
    expect($message->body)->toBeNull()
        ->and($message->deleted_at)->not->toBeNull();

    $this->actingAs($this->ana)
        ->get(route('team-chat.threads.show', $this->thread))
        ->assertInertia(fn (Assert $page) => $page
            ->where('messages.data.0.deleted', true)
            ->where('threads.threads.0.last_message.text', 'Mensaje eliminado'));

    $this->actingAs($this->beto)
        ->putJson(route('team-chat.threads.messages.update', [$this->thread, $message]), ['body' => 'Revivir'])
        ->assertForbidden();
});

test('history is paginated by cursor in both directions and around a message', function () {
    config(['team-chat.page_size' => 10]);
    $messages = collect(range(1, 25))->map(fn (int $number) => TeamMessage::factory()->in($this->thread, $this->beto)->create(['body' => "Mensaje {$number}"]));

    $this->actingAs($this->ana)
        ->get(route('team-chat.threads.show', $this->thread))
        ->assertInertia(fn (Assert $page) => $page
            ->has('messages.data', 10)
            ->where('messages.data.0.body', 'Mensaje 16')
            ->where('messages.data.9.body', 'Mensaje 25')
            ->where('messages.has_more_before', true)
            ->where('messages.has_more_after', false));

    $this->actingAs($this->ana)
        ->getJson(route('team-chat.threads.messages.index', ['thread' => $this->thread, 'before' => $messages[15]->id]))
        ->assertJsonCount(10, 'data')
        ->assertJsonPath('data.0.body', 'Mensaje 6')
        ->assertJsonPath('has_more_before', true);

    $this->actingAs($this->ana)
        ->getJson(route('team-chat.threads.messages.index', ['thread' => $this->thread, 'before' => $messages[5]->id]))
        ->assertJsonCount(5, 'data')
        ->assertJsonPath('has_more_before', false);

    $this->actingAs($this->ana)
        ->getJson(route('team-chat.threads.messages.index', ['thread' => $this->thread, 'after' => $messages[22]->id]))
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('has_more_after', false);

    $this->actingAs($this->ana)
        ->get(route('team-chat.threads.show', ['thread' => $this->thread, 'around' => $messages[4]->id]))
        ->assertInertia(fn (Assert $page) => $page
            ->where('messages.data.0.body', 'Mensaje 1')
            ->where('messages.data.4.body', 'Mensaje 5')
            ->where('messages.has_more_before', false)
            ->where('messages.has_more_after', true));
});

test('unread counters follow the read marker and muted chats leave the global badge', function () {
    $group = TeamThread::factory()->withParticipants([$this->ana], [$this->beto])->create();
    TeamMessage::factory()->count(2)->in($this->thread, $this->beto)->create();
    $last = TeamMessage::factory()->in($this->thread, $this->beto)->create();
    TeamMessage::factory()->in($group, $this->beto)->create();
    TeamMessage::factory()->in($group, $this->ana)->create();
    TeamMessage::factory()->deleted()->in($group, $this->beto)->create();

    $this->actingAs($this->ana)
        ->get(route('team-chat.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('unreadTotal', 4)
            ->where('teamChatUnread', 4)
            ->where('threads.threads.0.id', $group->id)
            ->where('threads.threads.0.unread', 1)
            ->where('threads.threads.1.unread', 3));

    $this->actingAs($this->ana)
        ->postJson(route('team-chat.threads.read', $this->thread), ['message_id' => $last->id + 1000])
        ->assertOk()
        ->assertJsonPath('last_read_message_id', $last->id)
        ->assertJsonPath('unread_total', 1);

    $this->actingAs($this->ana)->put(route('team-chat.threads.mute', $group), ['muted' => true])->assertRedirect();

    $this->actingAs($this->ana)
        ->get(route('team-chat.index', ['filter' => 'unread']))
        ->assertInertia(fn (Assert $page) => $page
            ->where('unreadTotal', 0)
            ->has('threads.threads', 1)
            ->where('threads.threads.0.muted', true)
            ->where('threads.threads.0.unread', 1));
});

test('the latest message pointer never moves back when messages are recorded out of order', function () {
    $older = TeamMessage::factory()->in($this->thread, $this->beto)->create();
    $newer = TeamMessage::factory()->in($this->thread, $this->ana)->create();
    $activity = app(TeamThreadActivity::class);

    $activity->messageAdded($this->thread->fresh(), $newer);
    $activity->messageAdded($this->thread->fresh(), $older);

    expect($this->thread->fresh()->last_message_id)->toBe($newer->id);

    $this->actingAs($this->beto)
        ->postJson(route('team-chat.threads.read', $this->thread), ['message_id' => $newer->id])
        ->assertJsonPath('last_read_message_id', $newer->id);
});

test('the read marker never moves backwards', function () {
    $first = TeamMessage::factory()->in($this->thread, $this->beto)->create();
    $second = TeamMessage::factory()->in($this->thread, $this->beto)->create();

    $this->actingAs($this->ana)->postJson(route('team-chat.threads.read', $this->thread), ['message_id' => $second->id]);
    $this->actingAs($this->ana)->postJson(route('team-chat.threads.read', $this->thread), ['message_id' => $first->id])
        ->assertJsonPath('last_read_message_id', $second->id);
});

test('search only matches messages of the viewer threads', function () {
    TeamMessage::factory()->in($this->thread, $this->beto)->create(['body' => 'La factura 100% pendiente']);
    $foreign = TeamThread::factory()->withParticipants([$this->beto])->create();
    TeamMessage::factory()->in($foreign, $this->beto)->create(['body' => 'La factura secreta']);
    TeamMessage::factory()->deleted()->in($this->thread, $this->beto)->create();

    $this->actingAs($this->ana)
        ->getJson(route('team-chat.search', ['q' => 'factura']))
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.thread_title', 'Beto')
        ->assertJsonPath('data.0.excerpt', 'La factura 100% pendiente');

    $this->actingAs($this->ana)->getJson(route('team-chat.search', ['q' => '%']))->assertJsonCount(0, 'data');
    $this->actingAs($this->ana)->getJson(route('team-chat.search', ['q' => '100%']))->assertJsonCount(1, 'data');
});

test('threads are found by group name or participant name', function () {
    TeamThread::factory()->withParticipants([$this->ana], [$this->beto])->create(['name' => 'Cobranza']);

    $this->actingAs($this->ana)
        ->get(route('team-chat.index', ['search' => 'cobr']))
        ->assertInertia(fn (Assert $page) => $page->has('threads.threads', 1)->where('threads.threads.0.title', 'Cobranza'));

    $this->actingAs($this->ana)
        ->get(route('team-chat.index', ['search' => 'Beto', 'filter' => 'direct']))
        ->assertInertia(fn (Assert $page) => $page->has('threads.threads', 1)->where('threads.threads.0.id', $this->thread->id));
});

test('real-time signals carry identifiers only and reach current participants', function () {
    Event::fake([TeamThreadSignal::class, TeamChatUserSignal::class]);

    $this->actingAs($this->ana)
        ->postJson(route('team-chat.threads.messages.store', $this->thread), ['body' => 'Texto privado'])
        ->assertCreated();

    $message = TeamMessage::query()->sole();

    Event::assertDispatched(TeamThreadSignal::class, fn (TeamThreadSignal $signal) => $signal->broadcastAs() === 'message.created'
        && $signal->broadcastWith() === ['message_id' => $message->id]
        && $signal->broadcastOn()[0]->name === "private-team-chat.threads.{$this->thread->id}");

    Event::assertDispatched(TeamChatUserSignal::class, function (TeamChatUserSignal $signal) {
        $channels = collect($signal->broadcastOn())->map->name->sort()->values()->all();

        return $signal->broadcastAs() === 'team-chat.changed'
            && $signal->broadcastWith() === ['thread_id' => $this->thread->id, 'reason' => 'message']
            && $channels === collect(["private-users.{$this->ana->id}", "private-users.{$this->beto->id}"])->sort()->values()->all();
    });
});

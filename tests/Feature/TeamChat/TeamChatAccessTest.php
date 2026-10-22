<?php

use App\Broadcasting\TeamChat\TeamPresenceChannel;
use App\Broadcasting\TeamChat\TeamThreadChannel;
use App\Enums\DataScope;
use App\Enums\Permission;
use App\Models\Department;
use App\Models\TeamChat\TeamAttachment;
use App\Models\TeamChat\TeamMessage;
use App\Models\TeamChat\TeamThread;
use App\Models\User;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    Storage::fake('local');

    $this->department = Department::factory()->create();
    $this->ana = User::factory()->agent()->inDepartments($this->department)->create(['name' => 'Ana']);
    $this->beto = User::factory()->agent()->inDepartments($this->department)->create(['name' => 'Beto']);
    $this->outsider = User::factory()->agent()->inDepartments($this->department)->create(['name' => 'Ciro']);
    $this->thread = TeamThread::factory()->withParticipants([$this->beto], [$this->ana])->create(['name' => 'Soporte nivel 2']);
    $this->message = TeamMessage::factory()->in($this->thread, $this->ana)->create(['body' => 'Plan confidencial']);
    $this->attachment = TeamAttachment::factory()->for($this->message, 'message')->create();
    Storage::disk('local')->put($this->attachment->path, '%PDF-1.4 contenido');
});

/**
 * Every endpoint that acts on a thread: [method, endpoint, payload].
 */
dataset('thread endpoints', [
    'show' => ['get', 'show', []],
    'history' => ['getJson', 'history', []],
    'send' => ['postJson', 'send', ['body' => 'Hola']],
    'edit' => ['putJson', 'edit', ['body' => 'Cambio']],
    'delete message' => ['deleteJson', 'delete', []],
    'read' => ['postJson', 'read', ['message_id' => 1]],
    'mute' => ['putJson', 'mute', ['muted' => true]],
    'rename' => ['putJson', 'rename', ['name' => 'Otro nombre']],
    'archive' => ['postJson', 'archive', []],
    'leave' => ['postJson', 'leave', []],
    'add participants' => ['postJson', 'add', ['user_ids' => [1]]],
    'remove participant' => ['deleteJson', 'remove', []],
    'change role' => ['putJson', 'role', ['role' => 'admin']],
    'download' => ['getJson', 'download', []],
]);

function teamChatEndpoint(string $endpoint, TeamThread $thread, TeamMessage $message, TeamAttachment $attachment, User $participant): string
{
    return match ($endpoint) {
        'show' => route('team-chat.threads.show', $thread),
        'history' => route('team-chat.threads.messages.index', $thread),
        'send' => route('team-chat.threads.messages.store', $thread),
        'edit' => route('team-chat.threads.messages.update', [$thread, $message]),
        'delete' => route('team-chat.threads.messages.destroy', [$thread, $message]),
        'read' => route('team-chat.threads.read', $thread),
        'mute' => route('team-chat.threads.mute', $thread),
        'rename' => route('team-chat.threads.update', $thread),
        'archive' => route('team-chat.threads.archive', $thread),
        'leave' => route('team-chat.threads.leave', $thread),
        'add' => route('team-chat.threads.participants.store', $thread),
        'remove' => route('team-chat.threads.participants.destroy', [$thread, $participant]),
        'role' => route('team-chat.threads.participants.update', [$thread, $participant]),
        'download' => route('team-chat.attachments.show', [$thread, $message, $attachment]),
    };
}

test('people who do not participate get a not found on every thread endpoint', function (string $method, string $endpoint, array $payload) {
    $url = teamChatEndpoint($endpoint, $this->thread, $this->message, $this->attachment, $this->beto);

    $this->actingAs($this->outsider)->{$method}($url, $payload)->assertNotFound();

    expect($this->message->fresh()->body)->toBe('Plan confidencial')
        ->and($this->thread->fresh()->name)->toBe('Soporte nivel 2')
        ->and($this->thread->activeParticipations()->count())->toBe(2);
})->with('thread endpoints');

test('the superadministrator has no automatic access to private chats', function (string $method, string $endpoint, array $payload) {
    $root = User::factory()->superAdministrator()->create();

    $this->actingAs($root)->{$method}(teamChatEndpoint($endpoint, $this->thread, $this->message, $this->attachment, $this->beto), $payload)->assertNotFound();
})->with('thread endpoints');

test('the superadministrator does not see other people threads in the list or the search', function () {
    $root = User::factory()->superAdministrator()->create();

    $this->actingAs($root)
        ->get(route('team-chat.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('team-chat/Index')->has('threads.threads', 0));

    $this->actingAs($root)
        ->getJson(route('team-chat.search', ['q' => 'confidencial']))
        ->assertOk()
        ->assertJsonCount(0, 'data');
});

test('a message of another thread cannot be reached through a thread the user participates in', function () {
    $own = TeamThread::factory()->direct($this->outsider, $this->beto)->create();

    $this->actingAs($this->outsider)
        ->putJson(route('team-chat.threads.messages.update', [$own, $this->message]), ['body' => 'Hackeado'])
        ->assertNotFound();

    $this->actingAs($this->outsider)
        ->getJson(route('team-chat.attachments.show', [$own, $this->message, $this->attachment]))
        ->assertNotFound();

    $this->actingAs($this->outsider)
        ->getJson(route('team-chat.threads.messages.index', ['thread' => $own, 'ids' => [$this->message->id]]))
        ->assertOk()
        ->assertJsonCount(0, 'data');

    expect($this->message->fresh()->body)->toBe('Plan confidencial');
});

test('the internal chat requires the use permission', function () {
    $user = conversationUser([Permission::ViewConversations], DataScope::Global);

    $this->actingAs($user)->get(route('team-chat.index'))->assertForbidden();
    $this->actingAs($user)->getJson(route('team-chat.people'))->assertForbidden();
    $this->actingAs($user)->getJson(route('team-chat.search', ['q' => 'hola']))->assertForbidden();
});

test('a participant who loses the use permission loses access to their threads', function () {
    $user = conversationUser([Permission::ViewDashboard], DataScope::Assigned, [$this->department]);
    $thread = TeamThread::factory()->direct($user, $this->ana)->create();

    $this->actingAs($user)->get(route('team-chat.threads.show', $thread))->assertNotFound();
});

test('guests are sent to the login page', function () {
    $this->get(route('team-chat.index'))->assertRedirect(route('login'));
    $this->getJson(route('team-chat.threads.messages.index', $this->thread))->assertUnauthorized();
});

test('a removed participant immediately loses access to the thread, its history and its files', function () {
    $this->actingAs($this->ana)
        ->delete(route('team-chat.threads.participants.destroy', [$this->thread, $this->beto]))
        ->assertRedirect();

    $this->actingAs($this->beto)->get(route('team-chat.threads.show', $this->thread))->assertNotFound();
    $this->actingAs($this->beto)->getJson(route('team-chat.threads.messages.index', $this->thread))->assertNotFound();
    $this->actingAs($this->beto)->getJson(route('team-chat.attachments.show', [$this->thread, $this->message, $this->attachment]))->assertNotFound();
    $this->actingAs($this->beto)->getJson(route('team-chat.search', ['q' => 'confidencial']))->assertJsonCount(0, 'data');
});

test('only current participants may subscribe to a thread channel', function () {
    $channel = app(TeamThreadChannel::class);
    $root = User::factory()->superAdministrator()->create();

    expect($channel->join($this->ana, (string) $this->thread->id))->toBeTrue()
        ->and($channel->join($this->outsider, (string) $this->thread->id))->toBeFalse()
        ->and($channel->join($root, (string) $this->thread->id))->toBeFalse()
        ->and($channel->join($this->ana, '0'.$this->thread->id))->toBeFalse()
        ->and($channel->join($this->ana, '999999'))->toBeFalse();

    $this->thread->participations()->where('user_id', $this->beto->id)->update(['left_at' => now()]);

    expect($channel->join($this->beto, (string) $this->thread->id))->toBeFalse();
});

test('the presence channel only exposes id, name and availability of chat users', function () {
    $channel = app(TeamPresenceChannel::class);
    $withoutChat = conversationUser([Permission::ViewDashboard]);

    expect($channel->join($this->ana))->toBe(['id' => $this->ana->id, 'name' => 'Ana', 'availability' => 'unavailable'])
        ->and($channel->join($withoutChat))->toBeFalse();
});

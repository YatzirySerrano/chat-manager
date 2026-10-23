<?php

use App\Actions\TeamChat\ToggleTeamMessageReaction;
use App\Enums\TeamChat\TeamChatSystemEvent;
use App\Events\TeamChat\TeamThreadChanged;
use App\Models\Department;
use App\Models\TeamChat\TeamMessage;
use App\Models\TeamChat\TeamMessageReaction;
use App\Models\TeamChat\TeamThread;
use App\Models\User;
use App\Services\TeamChat\TeamThreadActivity;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\Event;

/*
| Reactions: allow-listed emoji, idempotent, private to the participants
| and silent (no notifications).
*/

beforeEach(function () {
    $department = Department::factory()->create();
    $this->ana = User::factory()->agent()->inDepartments($department)->create(['name' => 'Ana']);
    $this->beto = User::factory()->agent()->inDepartments($department)->create(['name' => 'Beto']);
    $this->group = TeamThread::factory()->withParticipants([$this->beto], [$this->ana])->create(['name' => 'Soporte']);
    $this->message = TeamMessage::factory()->in($this->group, $this->beto)->create(['body' => 'Listo el reporte']);
    $this->react = fn (User $user, string $method = 'putJson', string $emoji = '👍', ?TeamThread $thread = null, ?TeamMessage $message = null) => $this->actingAs($user)->{$method}(
        route($method === 'putJson' ? 'team-chat.threads.messages.reactions.store' : 'team-chat.threads.messages.reactions.destroy', [$thread ?? $this->group, $message ?? $this->message]),
        ['emoji' => $emoji],
    );
});

test('a participant reacts once however many times the reaction is sent, and can withdraw it', function () {
    Event::fake([TeamThreadChanged::class]);

    ($this->react)($this->ana)->assertOk()
        ->assertJsonPath('data.reactions', [['emoji' => '👍', 'count' => 1, 'mine' => true, 'names' => ['Tú']]]);
    ($this->react)($this->ana)->assertOk()->assertJsonPath('data.reactions.0.count', 1);
    ($this->react)($this->beto, 'putJson', '😂')->assertOk()
        ->assertJsonPath('data.reactions', [
            ['emoji' => '👍', 'count' => 1, 'mine' => false, 'names' => ['Ana']],
            ['emoji' => '😂', 'count' => 1, 'mine' => true, 'names' => ['Tú']],
        ]);

    expect(TeamMessageReaction::query()->count())->toBe(2);
    Event::assertDispatchedTimes(TeamThreadChanged::class, 2);
    Event::assertDispatched(TeamThreadChanged::class, fn (TeamThreadChanged $event) => $event->reason === TeamThreadChanged::MESSAGE_UPDATED && $event->messageId === $this->message->id);

    ($this->react)($this->ana, 'deleteJson')->assertOk()->assertJsonPath('data.reactions.0.emoji', '😂');
    ($this->react)($this->ana, 'deleteJson')->assertOk();

    expect(TeamMessageReaction::query()->pluck('emoji')->all())->toBe(['😂'])
        ->and(DatabaseNotification::query()->count())->toBe(0);
});

test('only allow-listed emoji are accepted', function () {
    ($this->react)($this->ana, 'putJson', '💩')->assertJsonValidationErrors(['emoji' => 'Esa reacción no está disponible.']);

    expect(TeamMessageReaction::query()->count())->toBe(0);
});

test('a message of another thread cannot be reacted to through this thread', function () {
    $other = TeamThread::factory()->direct($this->ana, $this->beto)->create();
    $foreign = TeamMessage::factory()->in($other, $this->beto)->create();

    ($this->react)($this->ana, 'putJson', '👍', $this->group, $foreign)->assertNotFound();
});

test('people who left the group, archived groups, deleted and system messages accept no reactions', function () {
    $system = app(TeamThreadActivity::class)->recordSystemEvent($this->group, $this->ana, TeamChatSystemEvent::GroupRenamed);
    $deleted = TeamMessage::factory()->in($this->group, $this->beto)->deleted()->create();

    ($this->react)($this->ana, 'putJson', '👍', null, $system)->assertForbidden();
    ($this->react)($this->ana, 'putJson', '👍', null, $deleted)->assertForbidden();

    $this->group->participations()->where('user_id', $this->beto->id)->update(['left_at' => now()]);
    ($this->react)($this->beto)->assertNotFound();

    $this->group->forceFill(['archived_at' => now()])->save();
    ($this->react)($this->ana)->assertForbidden();

    expect(TeamMessageReaction::query()->count())->toBe(0);
});

test('deleting a message removes its reactions', function () {
    TeamMessageReaction::factory()->create(['team_message_id' => $this->message->id, 'user_id' => $this->ana->id]);

    $this->actingAs($this->beto)
        ->deleteJson(route('team-chat.threads.messages.destroy', [$this->group, $this->message]))
        ->assertOk()
        ->assertJsonPath('data.reactions', []);

    expect(TeamMessageReaction::query()->count())->toBe(0);
});

test('a reaction is not stored when its author was removed after the request was authorized', function () {
    $this->group->participations()->where('user_id', $this->beto->id)->update(['left_at' => now()]);

    expect(fn () => app(ToggleTeamMessageReaction::class)->add($this->message, $this->beto, '👍'))
        ->toThrow(AuthorizationException::class);

    expect(TeamMessageReaction::query()->count())->toBe(0);
});

test('a reaction is not stored on a message deleted after the request loaded it', function () {
    $stale = TeamMessage::query()->findOrFail($this->message->id);
    $this->message->forceFill(['body' => null, 'deleted_at' => now()])->save();

    expect(fn () => app(ToggleTeamMessageReaction::class)->add($stale, $this->ana, '👍'))
        ->toThrow(AuthorizationException::class);

    expect(TeamMessageReaction::query()->count())->toBe(0);
});

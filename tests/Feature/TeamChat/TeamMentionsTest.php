<?php

use App\Models\Department;
use App\Models\TeamChat\TeamMessage;
use App\Models\TeamChat\TeamThread;
use App\Models\User;
use App\Notifications\NotificationKind;
use Illuminate\Notifications\DatabaseNotification;

/*
| Mentions (@) in groups: only current participants other than the author
| are kept, and each one receives a single mention notification.
*/

beforeEach(function () {
    $department = Department::factory()->create();
    $this->ana = User::factory()->agent()->inDepartments($department)->create(['name' => 'Ana']);
    $this->beto = User::factory()->agent()->inDepartments($department)->create(['name' => 'Beto']);
    $this->ciro = User::factory()->agent()->inDepartments($department)->create(['name' => 'Ciro']);
    $this->outsider = User::factory()->agent()->inDepartments($department)->create(['name' => 'Dana']);
    $this->group = TeamThread::factory()->withParticipants([$this->beto, $this->ciro], [$this->ana])->create(['name' => 'Soporte']);
    $this->group->participations()->where('user_id', $this->ciro->id)->update(['left_at' => now()]);
});

function mentionNotificationsOf(User $user)
{
    return DatabaseNotification::query()->where('notifiable_id', $user->id)->where('notifiable_type', $user->getMorphClass())->get();
}

test('only current participants other than the author are kept as mentions', function () {
    $this->actingAs($this->ana)
        ->postJson(route('team-chat.threads.messages.store', $this->group), [
            'body' => '@Beto @Ciro @Dana revisen esto',
            'mentions' => [$this->beto->id, $this->ciro->id, $this->outsider->id, $this->ana->id, 999999],
        ])
        ->assertCreated()
        ->assertJsonPath('data.mentions', [['id' => $this->beto->id, 'name' => 'Beto']]);

    expect(TeamMessage::query()->sole()->metadata)->toBe(['mentions' => [$this->beto->id]]);
});

test('a mention notifies the mentioned participant once and nobody outside the group', function () {
    $this->actingAs($this->ana)
        ->postJson(route('team-chat.threads.messages.store', $this->group), [
            'body' => '@Beto urgente',
            'mentions' => [$this->beto->id, $this->outsider->id],
        ])
        ->assertCreated();

    expect(mentionNotificationsOf($this->beto)->pluck('kind')->all())->toBe([NotificationKind::TeamChatMention->value])
        ->and(mentionNotificationsOf($this->outsider))->toBeEmpty()
        ->and(mentionNotificationsOf($this->ciro))->toBeEmpty()
        ->and(mentionNotificationsOf($this->ana))->toBeEmpty();
});

test('editing a message keeps its mentions and notifies nobody again', function () {
    $this->actingAs($this->ana)
        ->postJson(route('team-chat.threads.messages.store', $this->group), ['body' => '@Beto hola', 'mentions' => [$this->beto->id]])
        ->assertCreated();
    $message = TeamMessage::query()->sole();

    $this->actingAs($this->ana)
        ->putJson(route('team-chat.threads.messages.update', [$this->group, $message]), ['body' => '@Beto hola de nuevo'])
        ->assertOk()
        ->assertJsonPath('data.mentions.0.id', $this->beto->id);

    expect(mentionNotificationsOf($this->beto))->toHaveCount(1)
        ->and(mentionNotificationsOf($this->beto)->sole()->occurrences)->toBe(1);
});

test('mentions are refused in a direct chat and limited in number', function () {
    $direct = TeamThread::factory()->direct($this->ana, $this->beto)->create();

    $this->actingAs($this->ana)
        ->postJson(route('team-chat.threads.messages.store', $direct), ['body' => 'Hola', 'mentions' => [$this->beto->id]])
        ->assertJsonValidationErrors(['mentions' => 'Solo puedes mencionar a personas en los grupos.']);

    config(['team-chat.max_mentions' => 1]);

    $this->actingAs($this->ana)
        ->postJson(route('team-chat.threads.messages.store', $this->group), ['body' => 'Hola', 'mentions' => [$this->beto->id, $this->outsider->id]])
        ->assertJsonValidationErrors(['mentions' => 'Puedes mencionar como máximo a 1 personas por mensaje.']);

    expect(TeamMessage::query()->count())->toBe(0);
});

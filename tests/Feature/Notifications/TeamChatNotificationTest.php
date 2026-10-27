<?php

use App\Enums\Permission;
use App\Enums\TeamChat\TeamAttachmentKind;
use App\Events\TeamChat\TeamMessagePosted;
use App\Models\TeamChat\TeamAttachment;
use App\Models\TeamChat\TeamMessage;
use App\Models\TeamChat\TeamThread;
use App\Models\User;
use App\Notifications\NotificationKind;
use App\Notifications\NotificationSignal;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\Event;

/*
| Internal team chat messages notify the other current participants,
| aggregated per thread; mentions are urgent and reach muted participants.
*/

function teamChatNotifications(User $user)
{
    return DatabaseNotification::query()->where('notifiable_id', $user->id)->where('notifiable_type', $user->getMorphClass())->get();
}

beforeEach(function () {
    $this->author = conversationUser([Permission::UseTeamChat]);
    $this->reader = conversationUser([Permission::UseTeamChat]);
    $this->muted = conversationUser([Permission::UseTeamChat]);
    $this->thread = TeamThread::factory()->withParticipants([$this->author, $this->reader, $this->muted])->create();
    $this->thread->participations()->where('user_id', $this->muted->id)->update(['muted' => true]);
});

function postTeamMessage(TeamThread $thread, User $author, array $metadata = []): TeamMessage
{
    $message = TeamMessage::factory()->in($thread, $author)->create(['metadata' => $metadata === [] ? null : $metadata, 'body' => 'Texto interno reservado']);
    TeamMessagePosted::dispatch($thread->id, $message->id, $author->id, 'message');

    return $message;
}

test('messages notify other unmuted participants, folded per thread, never the author', function () {
    postTeamMessage($this->thread, $this->author);
    $last = postTeamMessage($this->thread, $this->author);
    TeamMessagePosted::dispatch($this->thread->id, $last->id, $this->author->id, 'message');

    $notification = teamChatNotifications($this->reader)->sole();

    expect($notification->kind)->toBe(NotificationKind::TeamChatMessage->value)
        ->and($notification->occurrences)->toBe(2)
        ->and(json_encode($notification->data))->not->toContain('reservado')
        ->and(teamChatNotifications($this->author))->toBeEmpty()
        ->and(teamChatNotifications($this->muted))->toBeEmpty();
});

test('a mention is urgent and reaches a muted participant', function () {
    postTeamMessage($this->thread, $this->author, ['mentions' => [$this->muted->id]]);

    expect(teamChatNotifications($this->muted)->sole())
        ->kind->toBe(NotificationKind::TeamChatMention->value)
        ->level->toBe('urgent');
});

test('someone who left the thread sees no details of an older notification', function () {
    postTeamMessage($this->thread, $this->author);
    $this->thread->participations()->where('user_id', $this->reader->id)->update(['left_at' => now()]);

    $this->actingAs($this->reader)->getJson(route('notifications.index'))
        ->assertOk()
        ->assertJsonPath('data.0.accessible', false)
        ->assertJsonPath('data.0.url', null);
});

test('a participant sees who wrote, where, and a short preview of the message', function () {
    $message = postTeamMessage($this->thread, $this->author);

    $response = $this->actingAs($this->reader)->getJson(route('notifications.index'))->assertOk();

    expect($response->json('data.0'))->toMatchArray([
        'kind' => NotificationKind::TeamChatMessage->value,
        'title' => "{$this->author->name} en {$this->thread->name}",
        'body' => 'Texto interno reservado',
        'url' => route('team-chat.threads.show', $this->thread),
        'team_thread_id' => $this->thread->id,
        'conversation_id' => null,
        'toast' => true,
    ])->and($response->json('server_time'))->toBeString()
        ->and($message->id)->toBe(teamChatNotifications($this->reader)->sole()->data['message_id']);
});

test('a direct message names its author and a long text is shortened', function () {
    $thread = TeamThread::factory()->direct($this->author, $this->reader)->create();
    $message = TeamMessage::factory()->in($thread, $this->author)->create(['body' => str_repeat('palabra ', 40)]);
    TeamMessagePosted::dispatch($thread->id, $message->id, $this->author->id, 'message');

    $item = $this->actingAs($this->reader)->getJson(route('notifications.index'))->json('data.0');

    expect($item['title'])->toBe("Mensaje nuevo de {$this->author->name}")
        ->and(mb_strlen($item['body']))->toBeLessThanOrEqual(93)
        ->and($item['body'])->toEndWith('...');
});

test('a message with only files says what kind of file was sent', function (array $kinds, string $preview) {
    $message = TeamMessage::factory()->in($this->thread, $this->author)->create(['body' => null]);

    foreach ($kinds as $kind) {
        TeamAttachment::factory()->create(['team_message_id' => $message->id, 'kind' => $kind]);
    }

    TeamMessagePosted::dispatch($this->thread->id, $message->id, $this->author->id, 'message');

    $this->actingAs($this->reader)->getJson(route('notifications.index'))
        ->assertJsonPath('data.0.body', $preview);
})->with([
    'one image' => [[TeamAttachmentKind::Image], 'Envió una imagen'],
    'two documents' => [[TeamAttachmentKind::Document, TeamAttachmentKind::Document], 'Envió 2 documentos'],
    'mixed files' => [[TeamAttachmentKind::Image, TeamAttachmentKind::Audio], 'Envió 2 archivos'],
]);

test('folded group messages say how many and who wrote the latest one', function () {
    postTeamMessage($this->thread, $this->author);
    $other = conversationUser([Permission::UseTeamChat]);
    $this->thread->participations()->create(['user_id' => $other->id, 'role' => 'member', 'joined_at' => now()]);
    $last = TeamMessage::factory()->in($this->thread, $other)->create(['body' => 'Ya lo reviso']);
    TeamMessagePosted::dispatch($this->thread->id, $last->id, $other->id, 'message');

    $this->actingAs($this->reader)->getJson(route('notifications.index'))
        ->assertJsonPath('data.0.title', "2 mensajes nuevos en {$this->thread->name}")
        ->assertJsonPath('data.0.body', "{$other->name}: Ya lo reviso");
});

test('without access to the thread the notification shows no author, preview nor thread', function (string $loss) {
    postTeamMessage($this->thread, $this->author);

    if ($loss === 'left') {
        $this->thread->participations()->where('user_id', $this->reader->id)->update(['left_at' => now()]);
    } else {
        $this->reader->roles()->detach();
        $this->reader->unsetRelation('roles');
    }

    $response = $this->actingAs($this->reader->fresh())->getJson(route('notifications.index'))->assertOk();

    expect($response->json('data.0'))->toMatchArray(['accessible' => false, 'url' => null, 'team_thread_id' => null, 'toast' => false])
        ->and(json_encode($response->json('data')))->not->toContain('reservado')
        ->and(json_encode($response->json('data')))->not->toContain($this->author->name);
})->with(['left', 'permission']);

test('people outside the thread receive nothing about its messages', function () {
    $outsider = conversationUser([Permission::UseTeamChat]);

    postTeamMessage($this->thread, $this->author);

    expect(teamChatNotifications($outsider))->toBeEmpty();
    $this->actingAs($outsider)->getJson(route('notifications.index'))->assertJsonCount(0, 'data');
});

test('a message the participant already read is not notified', function () {
    $message = TeamMessage::factory()->in($this->thread, $this->author)->create();
    $this->thread->participations()->where('user_id', $this->reader->id)->update(['last_read_message_id' => $message->id]);

    TeamMessagePosted::dispatch($this->thread->id, $message->id, $this->author->id, 'message');

    expect(teamChatNotifications($this->reader))->toBeEmpty();
});

test('reading the thread reads its notifications, but not one that already counts a newer message', function () {
    $first = postTeamMessage($this->thread, $this->author, ['mentions' => [$this->reader->id]]);
    $second = postTeamMessage($this->thread, $this->author);
    postTeamMessage($this->thread, $this->author);

    $this->actingAs($this->reader)
        ->postJson(route('team-chat.threads.read', $this->thread), ['message_id' => $second->id])
        ->assertOk();

    $notifications = teamChatNotifications($this->reader)->keyBy('kind');

    expect($notifications[NotificationKind::TeamChatMention->value]->read_at)->not->toBeNull()
        ->and($notifications[NotificationKind::TeamChatMessage->value]->read_at)->toBeNull()
        ->and($first->id)->toBeLessThan($second->id);

    $this->actingAs($this->reader)
        ->postJson(route('team-chat.threads.read', $this->thread), ['message_id' => $this->thread->fresh()->last_message_id])
        ->assertOk();

    expect(teamChatNotifications($this->reader)->whereNull('read_at'))->toBeEmpty()
        ->and(teamChatNotifications($this->author))->toBeEmpty();
});

test('internal chat messages sound by default and a saved preference without sound wins', function () {
    $kind = NotificationKind::TeamChatMessage->value;

    expect($this->actingAs($this->reader)->getJson(route('notifications.index'))->json('preferences')[$kind])
        ->toBe(['in_app' => true, 'browser' => false, 'sound' => true]);

    $this->actingAs($this->reader)->put(route('notifications.preferences.update'), ['preferences' => [
        ['kind' => $kind, 'in_app' => true, 'browser' => false, 'sound' => false],
    ]])->assertSessionHasNoErrors();

    expect($this->actingAs($this->reader)->getJson(route('notifications.index'))->json('preferences')[$kind]['sound'])->toBeFalse()
        ->and($this->actingAs($this->author)->getJson(route('notifications.index'))->json('preferences')[$kind]['sound'])->toBeTrue();
});

test('a read that lands while the notification is being stored still leaves it read', function () {
    $message = TeamMessage::factory()->in($this->thread, $this->author)->create();

    // The participant's read commits right after the notification is stored
    // (another worker ran the read listener in between).
    Event::listen(NotificationSignal::class, function (NotificationSignal $signal) use ($message) {
        if ($signal->userId === $this->reader->id) {
            $this->thread->participations()->where('user_id', $this->reader->id)->update(['last_read_message_id' => $message->id]);
        }
    });

    TeamMessagePosted::dispatch($this->thread->id, $message->id, $this->author->id, 'message');

    expect(teamChatNotifications($this->reader)->sole()->read_at)->not->toBeNull();
});

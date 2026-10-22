<?php

use App\Enums\Permission;
use App\Events\TeamChat\TeamMessagePosted;
use App\Models\TeamChat\TeamMessage;
use App\Models\TeamChat\TeamThread;
use App\Models\User;
use App\Notifications\NotificationKind;
use Illuminate\Notifications\DatabaseNotification;

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

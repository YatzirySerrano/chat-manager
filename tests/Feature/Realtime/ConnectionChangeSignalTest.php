<?php

use App\Actions\Connections\ChangeConnectionStatus;
use App\Events\Broadcasts\RealtimeSignal;
use App\Models\Connection;
use App\Models\Conversation;
use App\Models\User;
use Illuminate\Support\Facades\Event;

/*
| A connection that stops (or starts) being able to send tells the open
| chats of its conversations in attention to reload their state.
*/

beforeEach(function () {
    config(['broadcasting.default' => 'log']);
});

test('pausing a connection signals only its conversations in attention, with ids only', function () {
    $connection = Connection::factory()->create();
    $open = Conversation::factory()->create(['connection_id' => $connection->id, 'status' => 'open']);
    $pending = Conversation::factory()->create(['connection_id' => $connection->id, 'status' => 'pending']);
    Conversation::factory()->create(['connection_id' => $connection->id, 'status' => 'closed', 'closed_at' => now()]);
    Conversation::factory()->create(['status' => 'open']);
    Event::fake([RealtimeSignal::class]);

    app(ChangeConnectionStatus::class)->handle($connection, false, User::factory()->superAdministrator()->create());

    $signals = Event::dispatched(RealtimeSignal::class)->map(fn (array $arguments): RealtimeSignal => $arguments[0]);

    expect($signals)->toHaveCount(1)
        ->and($signals->first()->event)->toBe('conversation.changed')
        ->and($signals->first()->channels)->toEqualCanonicalizing(["conversations.{$open->id}", "conversations.{$pending->id}"])
        ->and($signals->first()->payload)->toBe(['reason' => 'connection_changed']);
});

<?php

use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\Support\WebChatFixtures;
use Tests\Support\WebChatVisitor;

/*
| The visitor's history (polled every few seconds by every open widget)
| must cost the same number of queries whatever the page size.
*/

function visitorHistoryQueries(object $test, string $token, int $rows): int
{
    DB::flushQueryLog();
    DB::enableQueryLog();

    WebChatVisitor::messages($test, $token)->assertOk()->assertJsonCount($rows, 'data');

    $count = count(DB::getQueryLog());
    DB::disableQueryLog();

    return $count;
}

test('the visitor history costs the same queries with 5 or 50 messages', function () {
    $connection = WebChatFixtures::connection();
    $test = $this;
    $token = WebChatVisitor::token($this, $connection);
    WebChatVisitor::send($this, $token)->assertCreated();
    $conversation = Conversation::query()->sole();
    $agent = User::factory()->agent()->create();
    $reply = fn (int $count) => Message::factory()->count($count)->create([
        'conversation_id' => $conversation->id,
        'direction' => 'outbound',
        'status' => 'delivered',
        'sender_user_id' => $agent->id,
    ]);

    $reply(4);
    visitorHistoryQueries($test, $token, 5); // warms per-process caches
    $few = visitorHistoryQueries($test, $token, 5);

    $reply(45);
    $many = visitorHistoryQueries($test, $token, 50);

    expect($many)->toBe($few);
});

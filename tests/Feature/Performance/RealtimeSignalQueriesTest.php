<?php

use App\Broadcasting\RealtimeSignals;
use App\Models\Conversation;
use App\Models\ConversationEvent;
use App\Models\Department;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/*
| Inbox signals run at webhook rate (every message and delivery status), so
| the supervision channels of the departments a conversation passed through
| (inbox.supervision.{id}) are only looked up for departments that have a
| departments-scope supervisor able to join them, and that list is read
| once and reused (docs/rendimiento-y-carga.md).
*/

beforeEach(function () {
    $this->sales = Department::factory()->create();
    $this->billing = Department::factory()->create();
    $this->support = Department::factory()->create();
    $this->conversation = Conversation::factory()->inDepartment($this->support)->create();

    foreach ([[$this->sales, $this->billing], [$this->billing, $this->support]] as [$from, $to]) {
        ConversationEvent::query()->create([
            'conversation_id' => $this->conversation->id,
            'type' => 'transferred',
            'from_department_id' => $from->id,
            'to_department_id' => $to->id,
        ]);
    }
});

/**
 * Inbox channels of the conversation and the statements that computed them.
 *
 * @return array{0: list<string>, 1: list<string>}
 */
function inboxChannelsWithQueries(Conversation $conversation): array
{
    DB::flushQueryLog();
    DB::enableQueryLog();
    $channels = app(RealtimeSignals::class)->inboxChannels($conversation, 'message', null);
    $queries = array_column(DB::getQueryLog(), 'query');
    DB::disableQueryLog();

    return [$channels, $queries];
}

test('without departments supervisors a transferred conversation signals no supervision channel and reads no events', function () {
    User::factory()->administrator()->create();
    User::factory()->agent()->inDepartments([$this->sales, $this->billing])->create();

    [$channels, $queries] = inboxChannelsWithQueries($this->conversation);

    expect($channels)->toContain('inbox.global', "inbox.departments.{$this->support->id}")
        ->and(collect($channels)->filter(fn (string $channel): bool => str_starts_with($channel, 'inbox.supervision.')))->toBeEmpty()
        ->and(collect($queries)->filter(fn (string $sql): bool => str_contains($sql, 'conversation_events')))->toBeEmpty();
});

test('only the passed departments with a departments supervisor get their supervision channel', function () {
    User::factory()->supervisor()->inDepartments($this->sales)->create();
    User::factory()->agent()->inDepartments($this->billing)->create();

    [$channels] = inboxChannelsWithQueries($this->conversation);

    expect($channels)->toContain("inbox.supervision.{$this->sales->id}")
        ->not->toContain("inbox.supervision.{$this->billing->id}", "inbox.supervision.{$this->support->id}");
});

test('the departments with a supervisor are read once and reused by the next signals', function () {
    User::factory()->supervisor()->inDepartments($this->sales)->create();

    [, $first] = inboxChannelsWithQueries($this->conversation);
    [$channels, $next] = inboxChannelsWithQueries($this->conversation);

    expect($channels)->toContain("inbox.supervision.{$this->sales->id}")
        ->and(count($next))->toBe(1)
        ->and($next[0])->toContain('conversation_events')
        ->and(count($first))->toBeGreaterThan(1);
});

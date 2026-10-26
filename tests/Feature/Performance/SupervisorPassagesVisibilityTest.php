<?php

use App\Models\Conversation;
use App\Models\ConversationEvent;
use App\Models\Department;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/*
| A departments supervisor reads the conversations that passed through their
| departments (any event from or to them). The inbox list, its counters and
| its unread counter share the conversations now outside those departments,
| read once (inlined) instead of scanning the events in each query
| (docs/rendimiento-y-carga.md); the rows must be exactly the same as the
| semi-join over every passage.
*/

beforeEach(function () {
    $this->sales = Department::factory()->create();
    $this->support = Department::factory()->create();
    $this->billing = Department::factory()->create();
    $this->supervisor = User::factory()->supervisor()->inDepartments($this->sales)->create();
});

function recordPassage(Conversation $conversation, ?Department $from, ?Department $to, string $type = 'transferred'): void
{
    ConversationEvent::query()->create([
        'conversation_id' => $conversation->id,
        'type' => $type,
        'from_department_id' => $from?->id,
        'to_department_id' => $to?->id,
    ]);
}

/**
 * Ids the actor reaches, as every list and counter of the inbox narrows them.
 *
 * @return list<int>
 */
function visibleConversationIds(User $actor): array
{
    return Conversation::query()->visibleTo($actor)->orderBy('id')->pluck('id')->map(fn (mixed $id): int => (int) $id)->all();
}

test('the supervisor reaches their departments and every conversation that passed through them, nothing else', function () {
    $inSales = Conversation::factory()->inDepartment($this->sales)->create();
    $transferredOut = Conversation::factory()->inDepartment($this->support)->create();
    recordPassage($transferredOut, $this->sales, $this->support);
    $passedThrough = Conversation::factory()->inDepartment($this->billing)->create();
    recordPassage($passedThrough, $this->support, $this->sales);
    recordPassage($passedThrough, $this->sales, $this->billing);
    // Only an event into the department, then moved without an event: the
    // semi-join keeps it, so the inlined ids must too.
    $enteredOnly = Conversation::factory()->inDepartment($this->billing)->create();
    recordPassage($enteredOnly, null, $this->sales, 'created');
    $withoutDepartment = Conversation::factory()->create(['department_id' => null]);
    recordPassage($withoutDepartment, $this->sales, null);
    $unrelated = Conversation::factory()->inDepartment($this->support)->create();
    recordPassage($unrelated, $this->billing, $this->support);

    expect(visibleConversationIds($this->supervisor))->toBe([$inSales->id, $transferredOut->id, $passedThrough->id, $enteredOnly->id, $withoutDepartment->id])
        ->and(visibleConversationIds($this->supervisor))->not->toContain($unrelated->id);
});

test('a conversation transferred out after a listing is still reached by the next query of the same request', function () {
    $conversation = Conversation::factory()->inDepartment($this->sales)->create();
    expect(visibleConversationIds($this->supervisor))->toBe([$conversation->id]);

    $conversation->forceFill(['department_id' => $this->support->id])->save();
    recordPassage($conversation, $this->sales, $this->support);

    expect(visibleConversationIds($this->supervisor))->toBe([$conversation->id]);
});

test('the passages outside the departments are read once for the list, the counters and the unread counter', function () {
    $conversation = Conversation::factory()->inDepartment($this->support)->create();
    recordPassage($conversation, $this->sales, $this->support);

    DB::flushQueryLog();
    DB::enableQueryLog();
    visibleConversationIds($this->supervisor);
    visibleConversationIds($this->supervisor);
    Conversation::query()->visibleTo($this->supervisor)->count();
    $passageScans = collect(DB::getQueryLog())->filter(fn (array $query): bool => str_contains($query['query'], 'union'));
    DB::disableQueryLog();

    expect($passageScans)->toHaveCount(1);
});

test('the supervisor\'s inbox lists the conversations transferred out of their departments', function () {
    $mine = Conversation::factory()->inDepartment($this->sales)->create();
    $transferredOut = Conversation::factory()->inDepartment($this->support)->create();
    recordPassage($transferredOut, $this->sales, $this->support);
    Conversation::factory()->inDepartment($this->support)->create();

    $listed = collect($this->actingAs($this->supervisor)->getJson(route('conversations.page'))->assertOk()->json('data'))->pluck('id');

    expect($listed->sort()->values()->all())->toBe([$mine->id, $transferredOut->id]);
});

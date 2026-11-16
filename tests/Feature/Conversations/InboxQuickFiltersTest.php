<?php

use App\Enums\Permission;
use App\Models\Conversation;
use App\Models\Department;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

/*
| Quick filters of the inbox: «Completadas» narrows the closed conversations
| to the ones closed as «Solicitud completada» (`completed_at`), with the
| same history permission as «Cerradas»; «Esperando respuesta» keeps the
| ones where the customer wrote last and nobody answered yet, exactly the
| rows that show the waiting dot, with its own counter.
*/

beforeEach(function () {
    $this->sales = Department::factory()->create();
    $this->agent = User::factory()->agent()->available()->inDepartments($this->sales)->create();

    $this->completed = Conversation::factory()->inDepartment($this->sales)->assignedTo($this->agent)->closed()->create(['completed_at' => now()->subHour()]);
    $this->plainClosed = Conversation::factory()->inDepartment($this->sales)->assignedTo($this->agent)->closed()->create();

    $this->waiting = Conversation::factory()->inDepartment($this->sales)->assignedTo($this->agent)->create([
        'last_inbound_at' => now()->subMinutes(5),
        'last_outbound_at' => now()->subMinutes(30),
    ]);
    $this->neverAnswered = Conversation::factory()->inDepartment($this->sales)->assignedTo($this->agent)->create([
        'last_inbound_at' => now()->subMinutes(8),
        'last_outbound_at' => null,
    ]);
    $this->answered = Conversation::factory()->inDepartment($this->sales)->assignedTo($this->agent)->create([
        'last_inbound_at' => now()->subMinutes(30),
        'last_outbound_at' => now()->subMinutes(5),
    ]);
});

/**
 * Ids the inbox page lists for the viewer with the query, sorted.
 *
 * @param  array<string, mixed>  $query
 * @return list<int>
 */
function quickFilterIds(User $viewer, array $query): array
{
    return collect(test()->actingAs($viewer)->getJson(route('conversations.page', [...$query, 'limit' => 500]))->assertOk()->json('data'))
        ->pluck('id')->sort()->values()->all();
}

test('«Completadas» lists only the conversations closed as a completed request', function () {
    expect(quickFilterIds($this->agent, ['status' => 'completed']))->toBe([$this->completed->id])
        ->and(quickFilterIds($this->agent, ['status' => 'closed']))
        ->toBe(collect([$this->completed->id, $this->plainClosed->id])->sort()->values()->all())
        ->and(quickFilterIds($this->agent, ['view' => 'mine', 'status' => 'completed']))->toBe([$this->completed->id]);

    $this->actingAs($this->agent)
        ->get(route('conversations.index', ['status' => 'completed']))
        ->assertInertia(fn (Assert $page) => $page
            ->where('filters.status', 'completed')
            ->has('conversations.data', 1)
            ->where('conversations.data.0.completed_at', fn (?string $value) => $value !== null));
});

test('«Completadas» needs the history permission like «Cerradas»', function () {
    $agent = conversationUser([Permission::ViewConversations, Permission::ReplyConversations, Permission::AcceptConversations], departments: [$this->sales]);
    $completed = Conversation::factory()->inDepartment($this->sales)->assignedTo($agent)->closed()->create(['completed_at' => now()]);

    $this->actingAs($agent)
        ->get(route('conversations.index', ['status' => 'completed']))
        ->assertInertia(fn (Assert $page) => $page
            ->where('filters.status', 'active')
            ->where('conversations.data', fn ($rows) => ! collect($rows)->pluck('id')->contains($completed->id)));
});

test('«Esperando respuesta» keeps the conversations where the customer wrote last', function () {
    $expected = collect([$this->waiting->id, $this->neverAnswered->id])->sort()->values()->all();

    expect(quickFilterIds($this->agent, ['view' => 'mine', 'awaiting' => 1]))->toBe($expected)
        // Closed conversations never wait for an answer.
        ->and(quickFilterIds($this->agent, ['view' => 'mine', 'status' => 'all', 'awaiting' => 1]))->toBe($expected);

    $rows = collect($this->actingAs($this->agent)->getJson(route('conversations.page', ['view' => 'mine', 'limit' => 500]))->json('data'));

    // The filter and the row's waiting dot always agree.
    expect($rows->where('awaiting_reply', true)->pluck('id')->sort()->values()->all())->toBe($expected);
});

test('the inbox counts what «Esperando respuesta» would list and keeps the toggle', function () {
    $this->actingAs($this->agent)
        ->get(route('conversations.index', ['view' => 'mine']))
        ->assertInertia(fn (Assert $page) => $page
            ->where('filters.awaiting', false)
            ->where('awaitingCount', 2));

    $this->actingAs($this->agent)
        ->get(route('conversations.index', ['view' => 'mine', 'awaiting' => 1]))
        ->assertInertia(fn (Assert $page) => $page
            ->where('filters.awaiting', true)
            ->where('counts.mine', 2)
            ->has('conversations.data', 2));
});

test('the new filters are validated', function (array $query, string $field) {
    $this->actingAs($this->agent)
        ->get(route('conversations.index', $query))
        ->assertSessionHasErrors($field);
})->with([
    'unknown status' => [['status' => 'finished'], 'status'],
    'awaiting not boolean' => [['awaiting' => 'tal vez'], 'awaiting'],
]);

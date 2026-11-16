<?php

use App\Models\Department;
use App\Models\TeamChat\TeamMessage;
use App\Models\TeamChat\TeamThread;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

/*
| Quick filters of the internal chat list: the scope (Todos, Privados,
| Grupos, Archivados) combines with «No leídos» and «Menciones»; the legacy
| `filter=unread` keeps working, and the counters follow the scope.
*/

beforeEach(function () {
    $department = Department::factory()->create();
    $this->ana = User::factory()->agent()->inDepartments($department)->create(['name' => 'Ana']);
    $this->beto = User::factory()->agent()->inDepartments($department)->create(['name' => 'Beto']);
    $this->ciro = User::factory()->agent()->inDepartments($department)->create(['name' => 'Ciro']);

    $this->direct = TeamThread::factory()->direct($this->ana, $this->beto)->create();
    $this->quietDirect = TeamThread::factory()->direct($this->ana, $this->ciro)->create();
    $this->group = TeamThread::factory()->withParticipants([$this->ana, $this->beto, $this->ciro])->create(['name' => 'Créditos']);
    $this->quietGroup = TeamThread::factory()->withParticipants([$this->ana, $this->ciro])->create(['name' => 'Cobranza']);

    TeamMessage::factory()->in($this->direct, $this->beto)->create();
    TeamMessage::factory()->in($this->group, $this->beto)->create(['metadata' => ['mentions' => [$this->ana->id]]]);
    TeamMessage::factory()->in($this->quietGroup, $this->ana)->create();
});

/**
 * Ids the list shows Ana with the query, sorted.
 *
 * @param  array<string, mixed>  $query
 * @return list<int>
 */
function listedThreads(array $query): array
{
    $ids = [];

    test()->actingAs(test()->ana)
        ->get(route('team-chat.index', $query))
        ->assertOk()
        ->assertInertia(function (Assert $page) use (&$ids) {
            $ids = collect($page->toArray()['props']['threads']['threads'])->pluck('id')->sort()->values()->all();
        });

    return $ids;
}

test('the unread and mentions toggles combine with the scope', function () {
    expect(listedThreads(['unread' => 1]))->toBe(collect([$this->direct->id, $this->group->id])->sort()->values()->all())
        ->and(listedThreads(['unread' => 1, 'filter' => 'groups']))->toBe([$this->group->id])
        ->and(listedThreads(['unread' => 1, 'filter' => 'direct']))->toBe([$this->direct->id])
        ->and(listedThreads(['mentions' => 1]))->toBe([$this->group->id])
        ->and(listedThreads(['mentions' => 1, 'filter' => 'direct']))->toBe([]);
});

test('the legacy filter values are the toggles over every active thread', function () {
    $this->actingAs($this->ana)
        ->get(route('team-chat.index', ['filter' => 'unread']))
        ->assertInertia(fn (Assert $page) => $page
            ->where('filters.filter', 'all')
            ->where('filters.unread', true)
            ->where('filters.mentions', false)
            ->has('threads.threads', 2));

    expect(listedThreads(['filter' => 'mentions']))->toBe([$this->group->id]);
});

test('the counters of the quick filters follow the scope', function () {
    $this->actingAs($this->ana)
        ->get(route('team-chat.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('threadCounts.unread', 2)
            ->where('threadCounts.mentions', 1));

    $this->actingAs($this->ana)
        ->get(route('team-chat.index', ['filter' => 'direct']))
        ->assertInertia(fn (Assert $page) => $page
            ->where('threadCounts.unread', 1)
            ->where('threadCounts.mentions', 0));
});

test('a mention of somebody else never counts for the viewer', function () {
    TeamMessage::factory()->in($this->quietGroup, $this->ciro)->create(['metadata' => ['mentions' => [$this->beto->id]]]);

    expect(listedThreads(['mentions' => 1]))->toBe([$this->group->id])
        ->and(listedThreads(['unread' => 1]))->toContain($this->quietGroup->id);
});

test('the toggles are validated', function () {
    $this->actingAs($this->ana)
        ->get(route('team-chat.index', ['unread' => 'quizá']))
        ->assertSessionHasErrors('unread');
});

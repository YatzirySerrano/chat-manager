<?php

use App\Actions\Conversations\TransferConversation;
use App\Enums\DataScope;
use App\Enums\Permission;
use App\Models\Connection;
use App\Models\Conversation;
use App\Models\Department;
use App\Models\Role;
use App\Models\User;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia as Assert;

/*
| Organizational supervision in the inbox (Fase 5.1): whoever supervises
| conversations (conversations.supervise) with a global or departments
| scope opens /conversations, with no query string, on every conversation of
| their read reach, whatever the responsible agent, department, transfer,
| connection or status. Agents keep their general inbox, the reach never
| widens and operations stay governed by the policy.
*/

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-20 10:00:00', 'UTC'));
    $this->sales = Department::factory()->create(['name' => 'Ventas']);
    $this->support = Department::factory()->create(['name' => 'Soporte']);
    $this->whatsapp = Connection::factory()->create(['name' => 'WhatsApp QA']);
    $this->other = Connection::factory()->create(['name' => 'Otra conexión']);

    $this->salesAgent = User::factory()->agent()->inDepartments($this->sales)->create();
    $this->salesColleague = User::factory()->agent()->inDepartments($this->sales)->create();
    $this->supportAgent = User::factory()->agent()->inDepartments($this->support)->create();
    $this->admin = User::factory()->administrator()->create();
    $this->supervisor = User::factory()->supervisor()->inDepartments($this->sales)->create();

    $this->salesOpen = Conversation::factory()->for($this->whatsapp, 'channelConnection')->inDepartment($this->sales)->assignedTo($this->salesAgent)->create();
    $this->salesColleagueOpen = Conversation::factory()->for($this->other, 'channelConnection')->inDepartment($this->sales)->assignedTo($this->salesColleague)->create();
    $this->salesPending = Conversation::factory()->for($this->whatsapp, 'channelConnection')->inDepartment($this->sales)->create();
    $this->supportOpen = Conversation::factory()->for($this->other, 'channelConnection')->inDepartment($this->support)->assignedTo($this->supportAgent)->create();
    $this->supportPending = Conversation::factory()->for($this->whatsapp, 'channelConnection')->inDepartment($this->support)->create();
    $this->unroutedPending = Conversation::factory()->for($this->whatsapp, 'channelConnection')->create();
    $this->salesClosed = Conversation::factory()->inDepartment($this->sales)->assignedTo($this->salesAgent)->closed()->create();
    $this->supportClosed = Conversation::factory()->inDepartment($this->support)->assignedTo($this->supportAgent)->closed()->create();

    // Accepted in sales, then transferred to an agent of support.
    $this->transferred = Conversation::factory()->for($this->whatsapp, 'channelConnection')->inDepartment($this->sales)->assignedTo($this->salesAgent)->create();
    app(TransferConversation::class)->handle($this->transferred, $this->support, $this->supportAgent, $this->salesAgent, 'Corresponde a soporte', 0);
});

/**
 * Ids the inbox lists for the viewer with the query, sorted.
 *
 * @param  array<string, string>  $query
 * @return list<int>
 */
function supervisedIds(User $viewer, array $query = []): array
{
    return collect(test()->actingAs($viewer)->getJson(route('conversations.page', [...$query, 'limit' => 500]))->assertOk()->json('data'))
        ->pluck('id')->sort()->values()->all();
}

/**
 * Ids of the first page of /conversations (the Inertia prop), sorted.
 *
 * @param  array<string, string>  $query
 * @return list<int>
 */
function firstPageIds(User $viewer, array $query = []): array
{
    $props = test()->actingAs($viewer)->get(route('conversations.index', $query))->assertOk()->viewData('page')['props'];

    return collect($props['conversations']['data'])->pluck('id')->sort()->values()->all();
}

/**
 * @param  list<Conversation>  $conversations
 * @return list<int>
 */
function supervisedIdsOf(array $conversations): array
{
    return collect($conversations)->pluck('id')->sort()->values()->all();
}

/**
 * A departments-scope role that only reads and supervises.
 */
function readOnlyDepartmentsSupervisor(Department $department): User
{
    $role = Role::factory()->scope(DataScope::Departments)->withPermissions([
        Permission::ViewConversations,
        Permission::SuperviseConversations,
    ])->create();

    return User::factory()->withRole($role)->inDepartments($department)->create();
}

test('the administrator opens the inbox on every active conversation of the organization', function () {
    $everyActive = supervisedIdsOf([
        $this->salesOpen, $this->salesColleagueOpen, $this->salesPending, $this->supportOpen,
        $this->supportPending, $this->unroutedPending, $this->transferred,
    ]);

    expect(firstPageIds($this->admin))->toBe($everyActive)
        ->and(supervisedIds($this->admin))->toBe($everyActive);

    $this->actingAs($this->admin)->get(route('conversations.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('filters.view', 'all')
            ->where('inbox.views', ['all', 'general', 'mine'])
            ->where('inbox.personal', false)
            ->where('inbox.scope', 'global')
            ->where('inbox.supervises', true));
});

test('the administrator\'s counters agree with what each chip lists', function () {
    $counts = $this->actingAs($this->admin)->get(route('conversations.index'))->viewData('page')['props']['counts'];

    expect($counts['general'])->toBe(7)
        ->and($counts['general'])->toBe(count(supervisedIds($this->admin)))
        ->and($counts['pending'])->toBe(3)
        ->and($counts['pending'])->toBe(count(supervisedIds($this->admin, ['status' => 'pending'])))
        ->and($counts['attending'])->toBe(4)
        ->and($counts['attending'])->toBe(count(supervisedIds($this->admin, ['status' => 'open'])))
        ->and($counts['mine'])->toBe(0)
        ->and($counts['mine'])->toBe(count(supervisedIds($this->admin, ['view' => 'mine'])));
});

test('the administrator still consults the closed conversations under the closed filter', function () {
    expect(supervisedIds($this->admin, ['status' => 'closed']))->toBe(supervisedIdsOf([$this->salesClosed, $this->supportClosed]))
        ->and(supervisedIds($this->admin, ['status' => 'all']))->toHaveCount(9);
});

test('the administrator narrows by connection without losing colleagues\' conversations', function () {
    expect(supervisedIds($this->admin, ['connection' => (string) $this->other->id]))
        ->toBe(supervisedIdsOf([$this->salesColleagueOpen, $this->supportOpen]));
});

test('old links keep working for the administrator', function () {
    expect(supervisedIds($this->admin, ['view' => 'queue']))->toBe(supervisedIdsOf([$this->salesPending, $this->supportPending, $this->unroutedPending]))
        ->and(supervisedIds($this->admin, ['view' => 'general']))->toBe(supervisedIdsOf([$this->salesPending, $this->supportPending, $this->unroutedPending]))
        ->and(supervisedIds($this->admin, ['view' => 'all']))->toBe(supervisedIds($this->admin));
});

test('operational actions of the administrator\'s rows still follow the policy', function () {
    $rows = collect($this->actingAs($this->admin)->getJson(route('conversations.page', ['limit' => 500]))->json('data'))->keyBy('id');

    expect($rows[$this->salesColleagueOpen->id]['can']['assign'])->toBeTrue()
        ->and($rows[$this->salesColleagueOpen->id]['can']['accept'])->toBeFalse()
        ->and($rows[$this->salesPending->id]['can']['accept'])->toBeTrue();
});

test('a departments supervisor opens on their departments\' conversations and the ones that passed through them', function () {
    $reach = supervisedIdsOf([$this->salesOpen, $this->salesColleagueOpen, $this->salesPending, $this->transferred]);

    expect(firstPageIds($this->supervisor))->toBe($reach)
        ->and(supervisedIds($this->supervisor))->toBe($reach)
        ->and(supervisedIds($this->supervisor, ['status' => 'closed']))->toBe(supervisedIdsOf([$this->salesClosed]));

    $this->actingAs($this->supervisor)->get(route('conversations.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('filters.view', 'all')
            ->where('inbox.scope', 'departments')
            ->where('counts.general', 4)
            ->where('counts.pending', 1));
});

test('a departments supervisor never reaches another department by filters', function (Closure $query) {
    expect(supervisedIds($this->supervisor, $query->call($this)))->not->toContain($this->supportOpen->id)
        ->not->toContain($this->supportPending->id)
        ->not->toContain($this->unroutedPending->id)
        ->not->toContain($this->supportClosed->id);
})->with([
    'department' => [fn () => ['department' => (string) $this->support->id]],
    'without department' => [fn () => ['department' => 'none']],
    'all statuses' => [fn () => ['status' => 'all']],
    'agent of support' => [fn () => ['agent' => (string) $this->supportAgent->id, 'status' => 'all']],
    'old general link' => [fn () => ['view' => 'general', 'status' => 'all']],
]);

test('a read-only departments supervisor sees their reach and gets no operation', function () {
    $readOnly = readOnlyDepartmentsSupervisor($this->sales);
    $rows = collect($this->actingAs($readOnly)->getJson(route('conversations.page', ['limit' => 500]))->json('data'));

    expect($rows->pluck('id')->sort()->values()->all())->toBe(supervisedIdsOf([$this->salesOpen, $this->salesColleagueOpen, $this->salesPending, $this->transferred]));

    foreach ($rows as $row) {
        foreach (['accept', 'assign', 'transfer', 'release', 'close', 'reply', 'addNote'] as $ability) {
            expect($row['can'][$ability])->toBeFalse("read-only supervision must not grant {$ability}");
        }
    }
});

test('an agent keeps the general inbox: their own and the pending ones they may accept', function () {
    expect(firstPageIds($this->salesAgent))->toBe(supervisedIdsOf([$this->salesOpen, $this->salesPending]))
        ->and(supervisedIds($this->salesAgent, ['view' => 'all']))->toBe(supervisedIdsOf([$this->salesOpen, $this->salesPending]));

    $this->actingAs($this->salesAgent)->get(route('conversations.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('filters.view', 'general')
            ->where('inbox.views', ['general', 'mine'])
            ->where('inbox.scope', 'assigned')
            ->where('inbox.supervises', false));
});

test('the superadministrator also opens on every conversation', function () {
    $root = User::factory()->superAdministrator()->create();

    expect(supervisedIds($root))->toBe(supervisedIds($this->admin));
});

test('a team user without supervision keeps the general inbox by default', function () {
    $role = Role::factory()->scope(DataScope::Departments)->withPermissions([
        Permission::ViewConversations,
        Permission::ReplyConversations,
        Permission::AcceptConversations,
    ])->create();
    $teamAgent = User::factory()->withRole($role)->inDepartments($this->sales)->create();

    expect(supervisedIds($teamAgent))->toBe(supervisedIdsOf([$this->salesPending]))
        ->and(supervisedIds($teamAgent, ['view' => 'all']))->toBe(supervisedIdsOf([$this->salesOpen, $this->salesColleagueOpen, $this->salesPending]));
});

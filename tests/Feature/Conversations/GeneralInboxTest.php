<?php

use App\Actions\Conversations\ConversationInboxQuery;
use App\Enums\ConversationStatus;
use App\Models\Conversation;
use App\Models\Department;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\WebChatFixtures;

/*
| The general inbox, default view of whoever attends conversations: in one
| query within their reach, the pending conversations they may accept and
| the open ones they attend, by latest activity. "On hold" is retired: legacy
| rows in that status leave the general inbox and its counters, and stay
| readable in "mine" and "all". Its chips and counters always agree with
| what they list.
*/

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-20 10:00:00', 'UTC'));
    $this->sales = Department::factory()->create(['name' => 'Ventas']);
    $this->support = Department::factory()->create(['name' => 'Soporte']);
    $this->agent = User::factory()->agent()->available()->inDepartments($this->sales)->create();
    $this->colleague = User::factory()->agent()->available()->inDepartments($this->sales)->create();
    $this->supervisor = User::factory()->supervisor()->available()->inDepartments($this->sales)->create();
    $this->admin = User::factory()->administrator()->available()->create();

    $this->mineOpen = Conversation::factory()->inDepartment($this->sales)->assignedTo($this->agent)->create();
    $this->mineOnHold = Conversation::factory()->inDepartment($this->sales)->assignedTo($this->agent)->create(['status' => ConversationStatus::OnHold]);
    $this->mineClosed = Conversation::factory()->inDepartment($this->sales)->assignedTo($this->agent)->closed()->create();
    $this->colleagueOpen = Conversation::factory()->inDepartment($this->sales)->assignedTo($this->colleague)->create();
    $this->colleagueOnHold = Conversation::factory()->inDepartment($this->sales)->assignedTo($this->colleague)->create(['status' => ConversationStatus::OnHold]);
    $this->salesPending = Conversation::factory()->inDepartment($this->sales)->create();
    $this->supportPending = Conversation::factory()->inDepartment($this->support)->create();
    $this->supportOnHold = Conversation::factory()->inDepartment($this->support)->assignedTo(User::factory()->agent()->inDepartments($this->support)->create())->create(['status' => ConversationStatus::OnHold]);
    $this->unroutedPending = Conversation::factory()->create();
});

/**
 * Ids listed by the general inbox (or another chip) for the viewer, sorted.
 *
 * @param  array<string, string>  $query
 * @return list<int>
 */
function generalIds(User $viewer, array $query = []): array
{
    return collect(test()->actingAs($viewer)->getJson(route('conversations.page', [...$query, 'limit' => 500]))->assertOk()->json('data'))
        ->pluck('id')->sort()->values()->all();
}

/**
 * @param  list<Conversation>  $conversations
 * @return list<int>
 */
function idsOf(array $conversations): array
{
    return collect($conversations)->pluck('id')->sort()->values()->all();
}

test('an agent\'s general inbox holds their open conversations and the pending ones they may accept', function () {
    expect(generalIds($this->agent))->toBe(idsOf([$this->mineOpen, $this->salesPending]));
});

test('legacy conversations on hold leave the general inbox but stay readable in "mine"', function () {
    expect(generalIds($this->agent))->not->toContain($this->mineOnHold->id)
        ->and(generalIds($this->agent, ['view' => 'mine']))->toBe(idsOf([$this->mineOpen, $this->mineOnHold]));
});

test('a supervisor\'s general inbox never lists colleagues\' open or legacy on hold conversations', function () {
    expect(generalIds($this->supervisor))->toBe(idsOf([$this->salesPending]))
        ->and(generalIds($this->supervisor, ['status' => 'all']))->toBe(idsOf([$this->salesPending, $this->mineClosed]));
});

test('a global general inbox adds the pending conversations without department', function () {
    expect(generalIds($this->admin))->toBe(idsOf([
        $this->salesPending, $this->supportPending, $this->unroutedPending,
    ]));
});

test('an old link to the on hold chip falls back to the active conversations', function () {
    $this->actingAs($this->agent)
        ->get(route('conversations.index', ['status' => 'on_hold']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('filters.status', 'active')
            ->where('counts', fn ($counts) => ! array_key_exists('on_hold', is_array($counts) ? $counts : $counts->all())));

    expect(generalIds($this->agent, ['status' => 'on_hold']))->toBe(generalIds($this->agent));
});

test('every counter is exactly what its chip lists with the same filters', function (string $viewer, array $filters) {
    $user = $this->{$viewer};
    $chips = [
        'general' => [],
        'mine' => ['view' => 'mine'],
        'pending' => ['status' => 'pending'],
        'attending' => ['status' => 'open'],
    ];

    $counts = $this->actingAs($user)->get(route('conversations.index', $filters))->viewData('page')['props']['counts'];

    expect(array_keys($counts))->toBe(array_keys($chips));

    foreach ($chips as $chip => $query) {
        expect($counts[$chip])->toBe(count(generalIds($user, [...$filters, ...$query])), "counts.{$chip}");
    }
})->with([
    'agent' => ['agent', []],
    'supervisor' => ['supervisor', []],
    'administrator' => ['admin', []],
    'administrator without department' => ['admin', ['department' => 'none']],
    'supervisor without agent' => ['supervisor', ['agent' => 'none']],
]);

test('the counters come from a single aggregate query', function () {
    $inbox = app(ConversationInboxQuery::class);
    $filters = $inbox->filters($this->admin, []);
    $this->admin->loadMissing('departments');

    DB::flushQueryLog();
    DB::enableQueryLog();
    $inbox->counts($this->admin, $filters);
    $queries = collect(DB::getQueryLog())->pluck('query')->filter(fn (string $sql) => str_contains($sql, 'from `conversations`'));
    DB::disableQueryLog();

    expect($queries)->toHaveCount(1);
});

test('the general inbox follows the latest activity, and its cursor reaches every row once', function () {
    $same = now()->subHour();
    Conversation::factory()->count(3)->inDepartment($this->sales)->assignedTo($this->agent)->create(['last_message_at' => $same]);
    Conversation::factory()->count(2)->inDepartment($this->sales)->create(['last_message_at' => $same]);
    Conversation::factory()->inDepartment($this->sales)->create(['last_message_at' => null]);

    $inbox = app(ConversationInboxQuery::class);
    $expected = $inbox->handle($this->agent, $inbox->filters($this->agent, []))->pluck('conversations.id')->all();

    $listed = [];
    $after = null;

    do {
        $page = $this->actingAs($this->agent)
            ->getJson(route('conversations.page', ['limit' => 2, ...($after === null ? [] : ['after' => $after])]))
            ->json();
        $listed = [...$listed, ...array_column($page['data'], 'id')];
        $after = $page['next_cursor'];
    } while ($page['has_more']);

    expect($expected)->toHaveCount(8)
        ->and($listed)->toBe($expected)
        ->and($this->actingAs($this->agent)->get(route('conversations.index'))->viewData('page')['props']['filters']['sort'])->toBe('recent');
});

test('the closed chip needs the history permission', function () {
    $this->actingAs($this->supervisor)
        ->get(route('conversations.index', ['status' => 'closed']))
        ->assertInertia(fn (Assert $page) => $page
            ->where('canSeeClosed', true)
            ->where('filters.status', 'closed')
            ->where('conversations.data', fn ($rows) => collect($rows)->pluck('id')->all() === [$this->mineClosed->id]));
});

test('the channel filter is offered only when the visible connections span more than one channel', function () {
    $this->actingAs($this->admin)
        ->get(route('conversations.index'))
        ->assertInertia(fn (Assert $page) => $page->where('filterOptions.channels', [['value' => 'whatsapp', 'label' => 'WhatsApp']]));

    $web = WebChatFixtures::connection();
    $visitor = Conversation::factory()->inDepartment($this->sales)->create(['connection_id' => $web->id]);

    $this->actingAs($this->admin)
        ->get(route('conversations.index', ['channel' => 'web_chat']))
        ->assertInertia(fn (Assert $page) => $page
            ->where('filterOptions.channels', [['value' => 'whatsapp', 'label' => 'WhatsApp'], ['value' => 'web_chat', 'label' => 'Chat web']])
            ->where('filters.channel', 'web_chat')
            ->where('conversations.data', fn ($rows) => collect($rows)->pluck('id')->all() === [$visitor->id])
            ->where('counts.pending', 1));

    expect(generalIds($this->admin, ['channel' => 'whatsapp']))->not->toContain($visitor->id);

    $this->actingAs($this->admin)
        ->get(route('conversations.index', ['channel' => 'fax']))
        ->assertSessionHasErrors('channel');
});

test('the agent filter is only for team inboxes: a personal inbox ignores it', function () {
    $this->actingAs($this->agent)
        ->get(route('conversations.index', ['agent' => (string) $this->colleague->id]))
        ->assertInertia(fn (Assert $page) => $page
            ->where('agents', [])
            ->where('filters.agent', null));

    expect(generalIds($this->agent, ['agent' => (string) $this->colleague->id]))->toBe(generalIds($this->agent))
        ->and(generalIds($this->supervisor, ['view' => 'all', 'agent' => (string) $this->colleague->id]))->toBe(idsOf([$this->colleagueOpen, $this->colleagueOnHold]));
});

test('tampering with the view, status or department never widens the general inbox', function (array $query) {
    $visible = Conversation::query()->visibleTo($this->agent)->pluck('id')->all();

    expect(array_diff(generalIds($this->agent, $query), $visible))->toBe([]);
})->with([
    'every status' => [['status' => 'all']],
    'closed' => [['status' => 'closed']],
    'another department' => [['department' => '999999']],
    'without department' => [['department' => 'none', 'status' => 'pending']],
    'every conversation' => [['view' => 'all', 'status' => 'all']],
]);

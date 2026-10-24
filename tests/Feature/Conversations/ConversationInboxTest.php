<?php

use App\Enums\ConversationEventType;
use App\Enums\ConversationStatus;
use App\Enums\DataScope;
use App\Enums\InboundMessageOutcome;
use App\Enums\Permission;
use App\Models\Connection;
use App\Models\Conversation;
use App\Models\Department;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

/*
| The inbox each person gets. One business number receives everything; who
| sees what is decided by the data scope and permissions, never by role names:
| personal inboxes (own assignments + the queue they may accept from) and
| team inboxes (every authorized conversation, with filters).
*/

beforeEach(function () {
    $this->sales = Department::factory()->create(['name' => 'Ventas']);
    $this->support = Department::factory()->create(['name' => 'Soporte']);
    $this->agent = User::factory()->agent()->available()->inDepartments($this->sales)->create();
    $this->colleague = User::factory()->agent()->available()->inDepartments($this->sales)->create();

    $this->mine = Conversation::factory()->inDepartment($this->sales)->assignedTo($this->agent)->create();
    $this->mineClosed = Conversation::factory()->inDepartment($this->sales)->assignedTo($this->agent)->closed()->create();
    $this->colleagues = Conversation::factory()->inDepartment($this->sales)->assignedTo($this->colleague)->create();
    $this->queued = Conversation::factory()->inDepartment($this->sales)->create();
    $this->otherQueue = Conversation::factory()->inDepartment($this->support)->create();
    $this->unrouted = Conversation::factory()->create();
});

/**
 * Ids of the listed conversations, sorted.
 *
 * @param  iterable<array{id: int}>  $rows
 * @return list<int>
 */
function listedIds(iterable $rows): array
{
    return collect($rows)->pluck('id')->sort()->values()->all();
}

test('an agent\'s inbox opens on the general inbox: their own conversations and the pending ones they may accept', function () {
    $this->actingAs($this->agent)
        ->get(route('conversations.index', ['status' => 'all']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('filters.view', 'general')
            ->where('inbox', ['personal' => true, 'views' => ['general', 'mine']])
            ->where('conversations.data', fn ($rows) => listedIds($rows) === listedIds([$this->mine, $this->mineClosed, $this->queued]))
            ->where('counts', ['general' => 2, 'mine' => 1, 'pending' => 1, 'attending' => 1, 'on_hold' => 0])
            ->where('agents', []));

    // The legacy queue is the general inbox narrowed to the pending ones.
    $this->actingAs($this->agent)
        ->get(route('conversations.index', ['view' => 'queue']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('filters.view', 'general')
            ->where('filters.status', 'pending')
            ->where('conversations.data', fn ($rows) => listedIds($rows) === [$this->queued->id])
            ->where('conversations.data.0.can.accept', true)
            ->where('counts.pending', 1));
});

test('the agent\'s own closed conversations follow the history permission', function () {
    $agent = conversationUser([Permission::ViewConversations, Permission::ReplyConversations, Permission::AcceptConversations], departments: [$this->sales]);
    $open = Conversation::factory()->inDepartment($this->sales)->assignedTo($agent)->create();
    Conversation::factory()->inDepartment($this->sales)->assignedTo($agent)->closed()->create();

    $this->actingAs($agent)
        ->get(route('conversations.index', ['status' => 'all']))
        ->assertInertia(fn (Assert $page) => $page
            ->where('canSeeClosed', false)
            ->where('conversations.data', fn ($rows) => listedIds($rows) === listedIds([$open, $this->queued])));

    // Asking for the closed ones falls back to the active ones.
    $this->actingAs($agent)
        ->get(route('conversations.index', ['status' => 'closed']))
        ->assertInertia(fn (Assert $page) => $page
            ->where('filters.status', 'active')
            ->where('conversations.data', fn ($rows) => listedIds($rows) === listedIds([$open, $this->queued])));
});

test('without the accept permission the pending ones of an agent are always empty, even asking for the queue', function () {
    $agent = conversationUser([Permission::ViewConversations, Permission::ReplyConversations], departments: [$this->sales]);
    $own = Conversation::factory()->inDepartment($this->sales)->assignedTo($agent)->create();

    $this->actingAs($agent)
        ->get(route('conversations.index', ['view' => 'queue']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('inbox.views', ['general', 'mine'])
            ->where('filters.view', 'general')
            ->where('filters.status', 'pending')
            ->where('conversations.data', [])
            ->where('counts', ['general' => 1, 'mine' => 1, 'pending' => 0, 'attending' => 1, 'on_hold' => 0]));

    $this->actingAs($agent)
        ->get(route('conversations.index'))
        ->assertInertia(fn (Assert $page) => $page->where('conversations.data', fn ($rows) => listedIds($rows) === [$own->id]));
});

test('tampering with the view or the filters never widens a personal inbox', function (array $query, Closure $expected) {
    $query = array_map(fn (string $value): string => match ($value) {
        'colleague' => (string) $this->colleague->id,
        'support' => (string) $this->support->id,
        default => $value,
    }, $query);

    $this->actingAs($this->agent)
        ->get(route('conversations.index', [...$query, 'status' => 'all']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('conversations.data', fn ($rows) => listedIds($rows) === listedIds($expected($this)))
            ->where('counts', fn ($counts) => $counts['general'] <= 2 && $counts['mine'] <= 1 && $counts['pending'] <= 1 && $counts['on_hold'] === 0));
})->with([
    'view of every conversation' => [['view' => 'all'], fn ($test) => [$test->mine, $test->mineClosed, $test->queued]],
    'a colleague as agent filter' => [['agent' => 'colleague'], fn ($test) => [$test->mine, $test->mineClosed, $test->queued]],
    'queue filtered by a colleague' => [['view' => 'queue', 'agent' => 'colleague'], fn ($test) => [$test->queued]],
    'queue of another department' => [['view' => 'queue', 'department' => 'support'], fn ($test) => []],
    'queue without department' => [['view' => 'queue', 'department' => 'none'], fn ($test) => []],
    'general inbox of another department' => [['view' => 'general', 'department' => 'support'], fn ($test) => []],
    'own view of another department' => [['view' => 'mine', 'department' => 'support'], fn ($test) => []],
]);

test('an unknown view is rejected', function () {
    $this->actingAs($this->agent)
        ->get(route('conversations.index', ['view' => 'everything']))
        ->assertSessionHasErrors('view');
});

test('team inboxes open on the general inbox, with every authorized conversation and "only mine" as views', function () {
    $supervisor = User::factory()->supervisor()->inDepartments($this->sales)->create();
    $own = Conversation::factory()->inDepartment($this->support)->assignedTo($supervisor)->create();

    $this->actingAs($supervisor)
        ->get(route('conversations.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('inbox', ['personal' => false, 'views' => ['general', 'mine', 'all']])
            ->where('filters.view', 'general')
            ->where('conversations.data', fn ($rows) => listedIds($rows) === listedIds([$this->queued, $own]))
            ->where('agents', fn ($agents) => collect($agents)->pluck('id')->contains($this->colleague->id)));

    $this->actingAs($supervisor)
        ->get(route('conversations.index', ['view' => 'all']))
        ->assertInertia(fn (Assert $page) => $page
            ->where('filters.view', 'all')
            ->where('conversations.data', fn ($rows) => listedIds($rows) === listedIds([$this->mine, $this->colleagues, $this->queued, $own])));

    $this->actingAs($supervisor)
        ->get(route('conversations.index', ['view' => 'mine']))
        ->assertInertia(fn (Assert $page) => $page->where('conversations.data', fn ($rows) => listedIds($rows) === [$own->id]));

    $this->actingAs($supervisor)
        ->get(route('conversations.index', ['view' => 'all', 'agent' => (string) $this->colleague->id]))
        ->assertInertia(fn (Assert $page) => $page->where('conversations.data', fn ($rows) => listedIds($rows) === [$this->colleagues->id]));

    $this->actingAs($supervisor)
        ->get(route('conversations.index', ['view' => 'all', 'department' => (string) $this->support->id]))
        ->assertInertia(fn (Assert $page) => $page
            ->where('conversations.data', fn ($rows) => listedIds($rows) === [$own->id])
            ->where('counts', ['general' => 1, 'mine' => 1, 'pending' => 0, 'attending' => 1, 'on_hold' => 0]));
});

test('a team inbox without attending conversations only has every authorized conversation', function () {
    $coordinator = conversationUser([Permission::ViewConversations, Permission::AssignConversations], DataScope::Global);

    $this->actingAs($coordinator)
        ->get(route('conversations.index', ['view' => 'mine', 'status' => 'all']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('inbox.views', ['all'])
            ->where('filters.view', 'all')
            ->where('conversations.data', fn ($rows) => count($rows) === 5));
});

test('administrators filter the authorized list by department, agent, status and connection', function () {
    $admin = User::factory()->administrator()->create();

    $filter = fn (array $query) => $this->actingAs($admin)->get(route('conversations.index', ['view' => 'all', ...$query]));

    $filter(['department' => 'none'])->assertInertia(fn (Assert $page) => $page->where('conversations.data', fn ($rows) => listedIds($rows) === [$this->unrouted->id]));
    $filter(['agent' => 'none'])->assertInertia(fn (Assert $page) => $page->where('conversations.data', fn ($rows) => listedIds($rows) === listedIds([$this->queued, $this->otherQueue, $this->unrouted])));
    $filter(['status' => 'closed'])->assertInertia(fn (Assert $page) => $page->where('conversations.data', fn ($rows) => listedIds($rows) === [$this->mineClosed->id]));
    $filter(['connection' => $this->mine->connection_id])->assertInertia(fn (Assert $page) => $page->where('conversations.data', fn ($rows) => listedIds($rows) === [$this->mine->id]));
});

test('the shared inbox: routed, waiting unassigned, offered to the department, first accept wins, later messages join', function () {
    $connection = Connection::factory()->create(['default_department_id' => $this->sales->id]);
    $supportAgent = User::factory()->agent()->available()->inDepartments($this->support)->create();

    $receipt = receiveWhatsapp($connection, '5215599990000', 'Hola, quiero información');
    $conversation = $receipt->conversation;

    expect($conversation)
        ->department_id->toBe($this->sales->id)
        ->status->toBe(ConversationStatus::Pending)
        ->assigned_user_id->toBeNull()
        ->and($conversation->events()->pluck('type')->all())->toBe([ConversationEventType::Created]);

    $queueOf = fn (User $user) => $this->actingAs($user)->get(route('conversations.index', ['view' => 'queue']));
    $lists = fn (Conversation $conversation) => fn (Assert $page) => $page->where('conversations.data', fn ($rows) => collect($rows)->pluck('id')->contains($conversation->id));
    $doesNotList = fn (Conversation $conversation) => fn (Assert $page) => $page->where('conversations.data', fn ($rows) => ! collect($rows)->pluck('id')->contains($conversation->id));

    $queueOf($this->agent)->assertInertia($lists($conversation));
    $queueOf($this->colleague)->assertInertia($lists($conversation));
    $queueOf($supportAgent)->assertInertia($doesNotList($conversation));

    $this->actingAs($this->agent)->post(route('conversations.accept', $conversation))->assertSessionHasNoErrors();

    $queueOf($this->colleague)->assertInertia($doesNotList($conversation));
    $this->actingAs($this->colleague)
        ->from(route('conversations.index'))
        ->post(route('conversations.accept', $conversation))
        ->assertSessionHasErrors(['conversation' => 'Otra persona ya aceptó esta conversación.']);

    $followUp = receiveWhatsapp($connection, '5215599990000', '¿Siguen ahí?');

    expect($followUp->outcome)->toBe(InboundMessageOutcome::Appended)
        ->and($followUp->conversation->id)->toBe($conversation->id)
        ->and($conversation->refresh())
        ->assigned_user_id->toBe($this->agent->id)
        ->status->toBe(ConversationStatus::Open)
        ->and($conversation->messages()->count())->toBe(2);
});

test('without a routing default the conversation waits in the administrators\' unrouted queue', function () {
    $connection = Connection::factory()->create();
    $admin = User::factory()->administrator()->create();

    $conversation = receiveWhatsapp($connection, '5215588880000')->conversation;

    expect($conversation->department_id)->toBeNull()
        ->and($conversation->status)->toBe(ConversationStatus::Pending);

    $this->actingAs($admin)
        ->get(route('conversations.index', ['department' => 'none']))
        ->assertInertia(fn (Assert $page) => $page
            ->where('canSeeUnrouted', true)
            ->where('conversations.data', fn ($rows) => collect($rows)->pluck('id')->contains($conversation->id)));

    $this->actingAs($this->agent)
        ->get(route('conversations.index', ['view' => 'queue']))
        ->assertInertia(fn (Assert $page) => $page->where('conversations.data', fn ($rows) => listedIds($rows) === [$this->queued->id]));
});

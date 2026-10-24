<?php

use App\Actions\Conversations\ConversationInboxQuery;
use App\Enums\ConversationStatus;
use App\Models\Conversation;
use App\Models\Department;
use App\Models\User;
use Carbon\CarbonImmutable;

/*
| The inbox list is paged with a keyset cursor over its order (latest
| activity by default, or status priority, then id): following the cursor reaches every
| conversation the viewer may see exactly once, with no upper bound, and
| never one outside their reach.
*/

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-20 10:00:00', 'UTC'));
    $this->department = Department::factory()->create();
    $this->agent = User::factory()->agent()->available()->inDepartments($this->department)->create();
    $this->admin = User::factory()->administrator()->create();
});

/**
 * Ids of every row reached by following the list endpoint page by page.
 *
 * @param  array<string, string>  $filters
 * @return list<int>
 */
function followInbox(object $test, User $viewer, array $filters, int $pageSize): array
{
    $ids = [];
    $after = null;

    do {
        $page = $test->actingAs($viewer)
            ->getJson(route('conversations.page', [...$filters, 'limit' => $pageSize, ...($after === null ? [] : ['after' => $after])]))
            ->assertOk()
            ->json();

        expect(count($page['data']))->toBeLessThanOrEqual($pageSize);
        $ids = [...$ids, ...array_column($page['data'], 'id')];
        $after = $page['next_cursor'];
    } while ($page['has_more']);

    return $ids;
}

test('following the cursor lists every conversation once and in order, whatever the sort', function (string $sort) {
    $same = now()->subHour();

    // Ties on the last activity, conversations without activity yet and
    // every status, so the cursor has to break ties by id and place NULLs.
    foreach ([ConversationStatus::Pending, ConversationStatus::Open, ConversationStatus::OnHold, ConversationStatus::Closed] as $status) {
        Conversation::factory()->count(3)->inDepartment($this->department)->create(['status' => $status, 'last_message_at' => $same]);
        Conversation::factory()->inDepartment($this->department)->create(['status' => $status, 'last_message_at' => null]);
        Conversation::factory()->inDepartment($this->department)->create(['status' => $status, 'last_message_at' => now()->subMinutes(5)]);
    }

    $inbox = app(ConversationInboxQuery::class);
    $expected = $inbox->handle($this->admin, $inbox->filters($this->admin, ['view' => 'all', 'status' => 'all', 'sort' => $sort]))->pluck('conversations.id')->all();

    expect($expected)->toHaveCount(20)
        ->and(followInbox($this, $this->admin, ['view' => 'all', 'status' => 'all', 'sort' => $sort], 3))->toBe($expected);
})->with(['priority', 'recent', 'oldest']);

test('paging never reaches conversations outside the viewer\'s reach', function () {
    $colleague = User::factory()->agent()->available()->inDepartments($this->department)->create();
    $mine = Conversation::factory()->count(5)->inDepartment($this->department)->assignedTo($this->agent)->create()->modelKeys();
    Conversation::factory()->count(5)->inDepartment($this->department)->assignedTo($colleague)->create();

    $listed = followInbox($this, $this->agent, ['status' => 'all'], 2);

    expect($listed)->toEqualCanonicalizing($mine);
});

test('a tampered cursor or an oversized page is rejected', function () {
    $this->actingAs($this->admin)
        ->getJson(route('conversations.page', ['after' => 'not-a-cursor']))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('after');

    $this->actingAs($this->admin)
        ->getJson(route('conversations.page', ['after' => ConversationInboxQuery::encodeCursor(['rank' => 9, 'at' => null, 'id' => 1])]))
        ->assertJsonValidationErrors('after');

    $this->actingAs($this->admin)
        ->getJson(route('conversations.page', ['limit' => 501]))
        ->assertJsonValidationErrors('limit');
});

test('people without access to conversations cannot page the list', function () {
    $this->get(route('conversations.page'))->assertRedirect(route('login'));

    $this->actingAs(User::factory()->create())
        ->getJson(route('conversations.page'))
        ->assertForbidden();
});

<?php

use App\Models\Conversation;
use App\Models\Department;
use App\Models\Tag;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

/*
| Tag filter of the inbox: several conversation tags combine with ANY (OR,
| default) or ALL (AND), the legacy single `tag` keeps working, and the
| filter only ever narrows what the viewer may already see.
*/

beforeEach(function () {
    $this->sales = Department::factory()->create();
    $this->support = Department::factory()->create();
    $this->admin = User::factory()->administrator()->create();
    $this->agent = User::factory()->agent()->inDepartments($this->sales)->create();

    $this->vip = Tag::factory()->create(['name' => 'VIP']);
    $this->urgent = Tag::factory()->create(['name' => 'Urgente']);

    $this->both = Conversation::factory()->inDepartment($this->sales)->assignedTo($this->agent)->create(['last_message_at' => now()->subMinutes(1)]);
    $this->onlyVip = Conversation::factory()->inDepartment($this->sales)->assignedTo($this->agent)->create(['last_message_at' => now()->subMinutes(2)]);
    $this->onlyUrgent = Conversation::factory()->inDepartment($this->sales)->assignedTo($this->agent)->create(['last_message_at' => now()->subMinutes(3)]);
    $this->untagged = Conversation::factory()->inDepartment($this->sales)->assignedTo($this->agent)->create(['last_message_at' => now()->subMinutes(4)]);
    $this->otherDepartment = Conversation::factory()->inDepartment($this->support)->assignedTo(User::factory()->agent()->inDepartments($this->support)->create())->create();

    $tag = fn (Conversation $conversation, Tag ...$tags) => $conversation->tags()->attach(
        collect($tags)->mapWithKeys(fn (Tag $tag): array => [$tag->id => ['tagged_by_user_id' => $this->admin->id, 'created_at' => now()]])->all(),
    );

    $tag($this->both, $this->vip, $this->urgent);
    $tag($this->onlyVip, $this->vip);
    $tag($this->onlyUrgent, $this->urgent);
    $tag($this->otherDepartment, $this->vip, $this->urgent);
});

/**
 * Ids the inbox lists for the viewer with the query, sorted.
 *
 * @param  array<string, mixed>  $query
 * @return list<int>
 */
function taggedIds(User $viewer, array $query): array
{
    return collect(test()->actingAs($viewer)->getJson(route('conversations.page', [...$query, 'limit' => 500]))->assertOk()->json('data'))
        ->pluck('id')->sort()->values()->all();
}

test('several tags match any of them by default and all of them when asked', function () {
    $tags = [$this->vip->id, $this->urgent->id];

    expect(taggedIds($this->agent, ['view' => 'mine', 'tags' => $tags]))
        ->toBe(collect([$this->both->id, $this->onlyVip->id, $this->onlyUrgent->id])->sort()->values()->all())
        ->and(taggedIds($this->agent, ['view' => 'mine', 'tags' => $tags, 'tag_match' => 'any']))
        ->toBe(taggedIds($this->agent, ['view' => 'mine', 'tags' => $tags]))
        ->and(taggedIds($this->agent, ['view' => 'mine', 'tags' => $tags, 'tag_match' => 'all']))
        ->toBe([$this->both->id]);
});

test('the legacy single tag keeps working and joins the list of tags', function () {
    expect(taggedIds($this->agent, ['view' => 'mine', 'tag' => $this->urgent->id]))
        ->toBe(collect([$this->both->id, $this->onlyUrgent->id])->sort()->values()->all())
        ->and(taggedIds($this->agent, ['view' => 'mine', 'tag' => $this->urgent->id, 'tags' => [$this->vip->id], 'tag_match' => 'all']))
        ->toBe([$this->both->id]);
});

test('the tag filter never reveals conversations outside the viewer\'s reach', function () {
    $listed = taggedIds($this->agent, ['view' => 'all', 'status' => 'all', 'tags' => [$this->vip->id, $this->urgent->id], 'tag_match' => 'all']);

    expect($listed)->not->toContain($this->otherDepartment->id)
        ->and(taggedIds($this->admin, ['view' => 'all', 'tags' => [$this->vip->id, $this->urgent->id], 'tag_match' => 'all']))
        ->toContain($this->otherDepartment->id);
});

test('the counters follow the tag filter', function () {
    $this->actingAs($this->agent)
        ->get(route('conversations.index', ['tags' => [$this->vip->id, $this->urgent->id], 'tag_match' => 'all']))
        ->assertInertia(fn (Assert $page) => $page
            ->where('filters.tags', [$this->vip->id, $this->urgent->id])
            ->where('filters.tag_match', 'all')
            ->where('counts.mine', 1)
            ->where('counts.general', 1));
});

test('the tag filter is validated', function (array $query, string $field) {
    $this->actingAs($this->agent)
        ->getJson(route('conversations.page', $query))
        ->assertUnprocessable()
        ->assertJsonValidationErrors($field);
})->with([
    'more than ten tags' => [['tags' => range(1, 11)], 'tags'],
    'not an id' => [['tags' => ['abc']], 'tags.0'],
    'repeated' => [['tags' => [5, 5]], 'tags.0'],
    'unknown match mode' => [['tags' => [1], 'tag_match' => 'some'], 'tag_match'],
]);

test('every tag mode is one lookup on the pivot per page, never a join with the catalog', function () {
    DB::flushQueryLog();
    DB::enableQueryLog();
    taggedIds($this->agent, ['view' => 'mine', 'tags' => [$this->vip->id, $this->urgent->id], 'tag_match' => 'all']);
    $list = collect(DB::getQueryLog())->pluck('query')->filter(fn (string $sql): bool => str_contains($sql, 'conversation_tag') && str_contains($sql, 'from `conversations`'));
    DB::disableQueryLog();

    expect($list)->toHaveCount(1)
        ->and($list->first())->not->toContain('inner join `tags`');
});

test('pagination over a tag filter reaches every row once', function () {
    $more = Conversation::factory()->count(4)->inDepartment($this->sales)->assignedTo($this->agent)->create();
    $more->each(fn (Conversation $conversation) => $conversation->tags()->attach($this->vip->id, ['tagged_by_user_id' => $this->admin->id, 'created_at' => now()]));

    $listed = [];
    $after = null;

    do {
        $page = $this->actingAs($this->agent)
            ->getJson(route('conversations.page', ['view' => 'mine', 'tags' => [$this->vip->id], 'limit' => 2, ...($after === null ? [] : ['after' => $after])]))
            ->json();
        $listed = [...$listed, ...array_column($page['data'], 'id')];
        $after = $page['next_cursor'];
    } while ($page['has_more']);

    expect($listed)->toHaveCount(6)
        ->and(array_unique($listed))->toHaveCount(6);
});

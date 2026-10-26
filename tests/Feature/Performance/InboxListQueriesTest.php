<?php

use App\Enums\DistributionMode;
use App\Http\Middleware\HandleInertiaRequests;
use App\Models\Conversation;
use App\Models\Department;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\Support\WebChatFixtures;

/*
| The inbox list must cost the same number of queries whatever the number of
| rows: the per-row abilities ("can") reuse the viewer's departments loaded
| once per request instead of asking the database again for every row
| (found by the load battery, docs/rendimiento-y-carga.md).
*/

/**
 * Queries run by a partial reload of the inbox list.
 */
function inboxListQueries(User $viewer, string $view, int $rows, string $props = 'conversations'): int
{
    DB::flushQueryLog();
    DB::enableQueryLog();

    test()->actingAs($viewer)
        ->get(route('conversations.index', ['view' => $view]), [
            'X-Inertia' => 'true',
            'X-Inertia-Partial-Component' => 'conversations/Index',
            'X-Inertia-Partial-Data' => $props,
            'X-Inertia-Version' => app(HandleInertiaRequests::class)->version(request()),
        ])
        ->assertOk()
        ->assertJsonCount($rows, 'props.conversations.data');

    $count = count(DB::getQueryLog());
    DB::disableQueryLog();

    return $count;
}

test('the queue of an agent costs the same queries with 2 or 12 waiting conversations', function () {
    $department = Department::factory()->distribution(DistributionMode::Shared)->create();
    $agent = User::factory()->agent()->available()->inDepartments($department)->create();

    Conversation::factory()->count(2)->inDepartment($department)->create();
    inboxListQueries($agent, 'queue', 2); // warms per-process caches (permissions, settings)
    $few = inboxListQueries($agent->fresh(), 'queue', 2);

    Conversation::factory()->count(10)->inDepartment($department)->create();
    $many = inboxListQueries($agent->fresh(), 'queue', 12);

    expect($many)->toBe($few);
});

test('the list of a supervisor costs the same queries with 2 or 12 conversations of the department', function () {
    $department = Department::factory()->distribution(DistributionMode::Shared)->create();
    $supervisor = User::factory()->supervisor()->inDepartments($department)->create();

    Conversation::factory()->count(2)->inDepartment($department)->create();
    inboxListQueries($supervisor, 'all', 2); // warms per-process caches (permissions, settings)
    $few = inboxListQueries($supervisor->fresh(), 'all', 2);

    Conversation::factory()->count(10)->inDepartment($department)->create();
    $many = inboxListQueries($supervisor->fresh(), 'all', 12);

    expect($many)->toBe($few);
});

test('the list of a global administrator costs the same queries with 2 or 12 pending conversations they may accept', function () {
    $department = Department::factory()->distribution(DistributionMode::Shared)->create();
    $admin = User::factory()->administrator()->available()->create();

    Conversation::factory()->count(2)->inDepartment($department)->create();
    inboxListQueries($admin, 'all', 2); // warms per-process caches (permissions, settings)
    $few = inboxListQueries($admin->fresh(), 'all', 2);

    Conversation::factory()->count(10)->inDepartment($department)->create();
    $many = inboxListQueries($admin->fresh(), 'all', 12);

    expect($many)->toBe($few);
});

test('the general inbox of an agent costs the same queries with 2 or 12 rows', function () {
    $department = Department::factory()->distribution(DistributionMode::Shared)->create();
    $agent = User::factory()->agent()->available()->inDepartments($department)->create();

    Conversation::factory()->inDepartment($department)->create();
    Conversation::factory()->inDepartment($department)->assignedTo($agent)->create();
    inboxListQueries($agent, 'general', 2); // warms per-process caches (permissions, settings)
    $few = inboxListQueries($agent->fresh(), 'general', 2);

    Conversation::factory()->count(5)->inDepartment($department)->create();
    Conversation::factory()->count(5)->inDepartment($department)->assignedTo($agent)->create();
    $many = inboxListQueries($agent->fresh(), 'general', 12);

    expect($many)->toBe($few);
});

test('a live reload of the general inbox (rows, counters and unread) with WhatsApp and web chat rows costs the same queries', function () {
    $department = Department::factory()->distribution(DistributionMode::Shared)->create();
    $admin = User::factory()->administrator()->available()->create();
    $webs = [WebChatFixtures::connection(), WebChatFixtures::connection()];
    $props = 'conversations,counts,unreadCount';

    Conversation::factory()->inDepartment($department)->create();
    Conversation::factory()->inDepartment($department)->create(['connection_id' => $webs[0]->id]);
    inboxListQueries($admin, 'general', 2, $props); // warms per-process caches (permissions, settings)
    $few = inboxListQueries($admin->fresh(), 'general', 2, $props);

    Conversation::factory()->count(4)->inDepartment($department)->create();
    Conversation::factory()->count(3)->inDepartment($department)->create(['connection_id' => $webs[0]->id]);
    Conversation::factory()->count(3)->inDepartment($department)->create(['connection_id' => $webs[1]->id]);
    $many = inboxListQueries($admin->fresh(), 'general', 12, $props);

    expect($many)->toBe($few);
});

test('a department deactivated after a listing no longer lets the same person accept from it', function () {
    $department = Department::factory()->distribution(DistributionMode::Shared)->create();
    $admin = User::factory()->administrator()->available()->create();
    $queued = Conversation::factory()->inDepartment($department)->create();

    $this->actingAs($admin)->get(route('conversations.index', ['view' => 'all']))
        ->assertInertia(fn ($page) => $page->where('conversations.data.0.can.accept', true));

    $department->forceFill(['is_active' => false])->save();

    // Out of the general inbox (nothing left to accept), still in "all".
    $this->actingAs($admin)->get(route('conversations.index', ['view' => 'general']))
        ->assertInertia(fn ($page) => $page->where('conversations.data', []));

    $this->actingAs($admin)->get(route('conversations.index', ['view' => 'all']))
        ->assertInertia(fn ($page) => $page->where('conversations.data.0.id', $queued->id)->where('conversations.data.0.can.accept', false));
});

test('the queue still lists only the departments the agent may accept from', function () {
    $own = Department::factory()->distribution(DistributionMode::Shared)->create();
    $manual = Department::factory()->distribution(DistributionMode::Manual)->create();
    $inactive = Department::factory()->distribution(DistributionMode::Shared)->inactive()->create();
    $foreign = Department::factory()->distribution(DistributionMode::Shared)->create();
    $agent = User::factory()->agent()->available()->inDepartments([$own, $manual, $inactive])->create();

    $visible = Conversation::factory()->inDepartment($own)->create();
    Conversation::factory()->inDepartment($manual)->create();
    Conversation::factory()->inDepartment($inactive)->create();
    Conversation::factory()->inDepartment($foreign)->create();

    $this->actingAs($agent)
        ->get(route('conversations.index', ['view' => 'queue']), [
            'X-Inertia' => 'true',
            'X-Inertia-Partial-Component' => 'conversations/Index',
            'X-Inertia-Partial-Data' => 'conversations',
            'X-Inertia-Version' => app(HandleInertiaRequests::class)->version(request()),
        ])
        ->assertOk()
        ->assertJsonPath('props.conversations.data.*.id', [$visible->id])
        ->assertJsonPath('props.conversations.data.0.can.accept', true)
        ->assertJsonPath('props.conversations.data.0.can.view', true);
});

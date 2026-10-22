<?php

use App\Enums\DistributionMode;
use App\Http\Middleware\HandleInertiaRequests;
use App\Models\Conversation;
use App\Models\Department;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/*
| The inbox list must cost the same number of queries whatever the number of
| rows: the per-row abilities ("can") reuse the viewer's departments loaded
| once per request instead of asking the database again for every row
| (found by the load battery, docs/rendimiento-y-carga.md).
*/

/**
 * Queries run by a partial reload of the inbox list.
 */
function inboxListQueries(User $viewer, string $view, int $rows): int
{
    DB::flushQueryLog();
    DB::enableQueryLog();

    test()->actingAs($viewer)
        ->get(route('conversations.index', ['view' => $view]), [
            'X-Inertia' => 'true',
            'X-Inertia-Partial-Component' => 'conversations/Index',
            'X-Inertia-Partial-Data' => 'conversations',
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

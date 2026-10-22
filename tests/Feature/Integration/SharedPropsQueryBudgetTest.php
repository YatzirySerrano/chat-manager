<?php

use App\Models\Department;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Realtime\RealtimeTestSupport;

/*
| The props shared with every Inertia page (platform settings, the user's
| role and permissions, real-time channels, the internal chat badge) must
| stay cheap: a full visit to a simple page runs a small, fixed number of
| queries whatever the size of the user's departments, and a partial reload
| that does not ask for them skips the lazy ones.
*/

/**
 * @param  array<string, string>  $headers
 */
function queriesForVisit(User $user, string $url, array $headers = []): int
{
    DB::flushQueryLog();
    DB::enableQueryLog();

    test()->actingAs($user)->get($url, $headers)->assertOk();

    $count = count(DB::getQueryLog());
    DB::disableQueryLog();

    return $count;
}

beforeEach(function () {
    RealtimeTestSupport::useReverb();
});

test('a full visit of a simple page costs a small fixed number of queries', function () {
    $departments = Department::factory()->count(2)->create();
    $agent = User::factory()->agent()->inDepartments($departments)->create();

    // The first visit warms the platform settings and permission caches.
    // Every request authenticates a fresh model, as a real session does.
    queriesForVisit($agent->fresh(), route('profile.edit'));
    $few = queriesForVisit($agent->fresh(), route('profile.edit'));

    $agent->departments()->attach(Department::factory()->count(8)->create()->pluck('id'));
    $many = queriesForVisit($agent->fresh(), route('profile.edit'));

    expect($many)->toBe($few)->and($few)->toBeLessThanOrEqual(8);
});

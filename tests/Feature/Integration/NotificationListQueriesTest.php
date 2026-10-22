<?php

use App\Enums\DataTransferType;
use App\Enums\DistributionMode;
use App\Models\Conversation;
use App\Models\DataTransfer;
use App\Models\Department;
use App\Models\User;
use App\Notifications\ConversationPendingNotification;
use App\Notifications\DataTransferFinishedNotification;
use Illuminate\Support\Facades\DB;

/*
| The notification center lists a page of mixed notifications whose text is
| built from the records they reference; that must cost the same number of
| queries for 2 or 8 notifications of each kind (no query per row).
*/

/**
 * Pending-queue and export notifications for the agent, one per department.
 */
function seedListedNotifications(User $agent, int $count): void
{
    foreach (range(1, $count) as $index) {
        $department = Department::factory()->distribution(DistributionMode::Shared)->create();
        $agent->departments()->attach($department->id);
        $conversation = Conversation::factory()->inDepartment($department)->create();
        $transfer = DataTransfer::factory()->completed()->create(['user_id' => $agent->id]);

        $agent->notify(new ConversationPendingNotification($conversation->id, $department->id, $conversation->id * 1000 + $index));
        $agent->notify(new DataTransferFinishedNotification($transfer->id, DataTransferType::Export, 'completed'));
    }
}

function notificationListQueries(User $agent, int $rows): int
{
    DB::flushQueryLog();
    DB::enableQueryLog();

    test()->actingAs($agent)->getJson(route('notifications.index'))->assertOk()->assertJsonCount($rows, 'data');

    $count = count(DB::getQueryLog());
    DB::disableQueryLog();

    return $count;
}

test('the notification list costs the same queries with 4 or 16 notifications', function () {
    $agent = User::factory()->agent()->available()->create();

    seedListedNotifications($agent, 2);
    // The first request warms the platform settings and permission caches.
    notificationListQueries($agent->fresh(), 4);
    $few = notificationListQueries($agent->fresh(), 4);

    seedListedNotifications($agent, 6);
    $many = notificationListQueries($agent->fresh(), 16);

    expect($many)->toBe($few);
});

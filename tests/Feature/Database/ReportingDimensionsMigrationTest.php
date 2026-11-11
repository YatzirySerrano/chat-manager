<?php

use App\Actions\Analytics\RebuildConversationMetrics;
use App\Enums\ConversationEventType;
use App\Enums\MessageDirection;
use App\Enums\MessageStatus;
use App\Models\Connection;
use App\Models\Conversation;
use App\Models\Department;
use App\Models\Message;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
| The migration that adds `conversation_metrics.left_backlog_at` backfills it
| for the rows that already exist, so the backlog of the reports is right
| before any `analytics:rebuild`. Its SQL must give exactly what
| ConversationMetricsCalculator computes.
|
| Rolling a migration back commits what the test created, so the database
| is rebuilt before the next test.
*/

afterEach(function () {
    RefreshDatabaseState::$migrated = false;
});

/**
 * An event of the conversation's log, some minutes after its reception.
 */
function backfillEvent(Conversation $conversation, ConversationEventType $type, int $minute, ?User $to = null): void
{
    DB::table('conversation_events')->insert([
        'conversation_id' => $conversation->id,
        'type' => $type->value,
        'to_user_id' => $to?->id,
        'created_at' => CarbonImmutable::parse($conversation->created_at)->addMinutes($minute),
    ]);
}

/**
 * A human reply accepted by the provider, some minutes after the reception.
 */
function backfillReply(Conversation $conversation, User $sender, int $minute, MessageStatus $status = MessageStatus::Sent): void
{
    Message::factory()->create([
        'conversation_id' => $conversation->id,
        'direction' => MessageDirection::Outbound,
        'status' => $status,
        'sender_user_id' => $sender->id,
        'created_at' => CarbonImmutable::parse($conversation->created_at)->addMinutes($minute),
    ]);
}

test('the backfill of left_backlog_at gives every existing row what the calculator computes', function () {
    $department = Department::factory()->create();
    $agent = User::factory()->agent()->inDepartments($department)->create();
    $connection = Connection::factory()->operational()->create();
    $received = CarbonImmutable::parse('2026-10-19 15:00:00', 'UTC');
    $conversation = fn (): Conversation => Conversation::factory()->for($connection, 'channelConnection')->inDepartment($department)->create(['created_at' => $received]);

    $unattended = $conversation();

    $acceptedThenClosed = $conversation();
    backfillEvent($acceptedThenClosed, ConversationEventType::Accepted, 5, $agent);
    backfillEvent($acceptedThenClosed, ConversationEventType::Closed, 30);

    $closedWithoutAttention = $conversation();
    backfillEvent($closedWithoutAttention, ConversationEventType::Closed, 12);

    $repliedBeforeAnyAssignment = $conversation();
    backfillReply($repliedBeforeAnyAssignment, $agent, 3);
    backfillEvent($repliedBeforeAnyAssignment, ConversationEventType::Assigned, 9, $agent);

    $closedThenAttendedAfterReopening = $conversation();
    backfillEvent($closedThenAttendedAfterReopening, ConversationEventType::Closed, 4);
    backfillEvent($closedThenAttendedAfterReopening, ConversationEventType::Reopened, 20);
    backfillEvent($closedThenAttendedAfterReopening, ConversationEventType::Accepted, 25, $agent);

    // The automatic distribution and a failed reply are not attention.
    $onlyDistributed = $conversation();
    backfillEvent($onlyDistributed, ConversationEventType::AutoAssigned, 1, $agent);
    backfillReply($onlyDistributed, $agent, 2, MessageStatus::Failed);

    app(RebuildConversationMetrics::class)->handle();

    $rows = fn () => DB::table('conversation_metrics')->orderBy('conversation_id')->get(['conversation_id', 'left_backlog_at', 'received_at', 'computed_at', 'first_assigned_at', 'resolved_at'])->keyBy('conversation_id');
    $computed = $rows();

    // The fixtures cover every branch of the definition.
    expect($computed[$unattended->id]->left_backlog_at)->toBeNull()
        ->and($computed[$onlyDistributed->id]->left_backlog_at)->toBeNull()
        ->and($computed[$acceptedThenClosed->id]->left_backlog_at)->toBe('2026-10-19 15:05:00')
        ->and($computed[$closedWithoutAttention->id]->left_backlog_at)->toBe('2026-10-19 15:12:00')
        ->and($computed[$repliedBeforeAnyAssignment->id]->left_backlog_at)->toBe('2026-10-19 15:03:00')
        ->and($computed[$closedThenAttendedAfterReopening->id]->left_backlog_at)->toBe('2026-10-19 15:04:00');

    // The rows as they were before this release: without the new columns.
    $migration = 'database/migrations/2026_11_10_201846_add_reporting_dimensions_to_conversation_metrics_table.php';
    $this->artisan('migrate:rollback', ['--path' => $migration, '--force' => true])->assertSuccessful();
    expect(Schema::hasColumn('conversation_metrics', 'left_backlog_at'))->toBeFalse();

    $this->travel(3)->hours();
    $this->artisan('migrate', ['--path' => $migration, '--force' => true])->assertSuccessful();

    // Same backlog exit as the calculator, and nothing else moved.
    expect($rows()->map(fn (object $row): array => (array) $row)->all())
        ->toBe($computed->map(fn (object $row): array => (array) $row)->all())
        ->and(DB::table('conversation_metrics')->whereNull('left_backlog_at')->count())->toBe(2);
});

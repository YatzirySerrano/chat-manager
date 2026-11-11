<?php

use App\Models\Connection;
use App\Models\Conversation;
use App\Models\ResponseCycle;
use App\Services\Sla\SlaEvaluator;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/*
| The SLA evaluator runs every minute: breached requests that nobody answers
| stay open for hours, so its pending stages must not read again the cycles
| a previous run already processed.
*/

/**
 * Open cycles of 10 minutes started an hour ago that the evaluator already
 * warned and breached.
 */
function evaluatorProcessedOpenCycles(int $count): void
{
    $connection = Connection::factory()->operational()->create();
    $startedAt = CarbonImmutable::now()->subHour();

    Conversation::factory()->count($count)->for($connection, 'channelConnection')->create()
        ->each(fn (Conversation $conversation) => ResponseCycle::factory()->withTarget(600)->create([
            'conversation_id' => $conversation->id,
            'started_at' => $startedAt,
            'warn_at' => $startedAt->addSeconds(480),
            'due_at' => $startedAt->addSeconds(600),
            'warned_at' => $startedAt->addSeconds(480),
            'breached_at' => $startedAt->addSeconds(600),
            'last_alert_at' => $startedAt->addSeconds(600),
        ]));
}

/**
 * Plan of a candidates query.
 */
function evaluatorPlan(Builder $query): object
{
    return collect(DB::select('EXPLAIN '.$query->toSql(), $query->getBindings()))->sole();
}

/**
 * Rows the storage engine read to answer the query.
 */
function evaluatorRowsRead(Builder $query): int
{
    $read = fn (): int => (int) collect(DB::select("SHOW SESSION STATUS LIKE 'Handler_read%'"))->sum(fn (object $row): int => (int) $row->Value);
    $before = $read();
    $query->pluck('id');

    return $read() - $before;
}

test('the pending stages of the evaluator skip the cycles already processed that are still open', function () {
    evaluatorProcessedOpenCycles(40);
    $evaluator = app(SlaEvaluator::class);
    $now = CarbonImmutable::now();

    $breaches = evaluatorPlan($evaluator->breachCandidates($now));
    $warnings = evaluatorPlan($evaluator->warningCandidates($now));

    // Equality on the null stamp and range on the date, in index order.
    expect($breaches->key)->toBe('response_cycles_active_breached_at_due_at_index')
        ->and($breaches->type)->toBe('range')
        ->and((string) $breaches->Extra)->not->toContain('filesort')
        ->and($warnings->key)->toBe('response_cycles_active_warned_at_warn_at_index')
        ->and($warnings->type)->toBe('range')
        ->and((string) $warnings->Extra)->not->toContain('filesort')
        // None of the 40 processed cycles is read (one probe that finds nothing).
        ->and(evaluatorRowsRead($evaluator->breachCandidates($now)))->toBeLessThanOrEqual(2)
        ->and(evaluatorRowsRead($evaluator->warningCandidates($now)))->toBeLessThanOrEqual(2)
        ->and($evaluator->run($now))->toBe(['breached' => 0, 'warned' => 0, 'reminded' => 0]);
});

test('the reminders read the breached open cycles through the index, never the ones still inside their deadline', function () {
    evaluatorProcessedOpenCycles(3);
    $connection = Connection::factory()->operational()->create();
    Conversation::factory()->count(40)->for($connection, 'channelConnection')->create()
        ->each(fn (Conversation $conversation) => ResponseCycle::factory()->withTarget(86400)->create(['conversation_id' => $conversation->id]));
    $evaluator = app(SlaEvaluator::class);
    $now = CarbonImmutable::now();
    $candidates = fn (): Builder => $evaluator->reminderCandidates($now, 5, 3);

    expect(evaluatorPlan($candidates()))
        ->key->toBe('response_cycles_active_breached_at_due_at_index')
        ->type->toBe('range')
        ->and((string) evaluatorPlan($candidates())->Extra)->not->toContain('filesort')
        ->and($candidates()->count())->toBe(3)
        ->and(evaluatorRowsRead($candidates()))->toBeLessThanOrEqual(8);
});

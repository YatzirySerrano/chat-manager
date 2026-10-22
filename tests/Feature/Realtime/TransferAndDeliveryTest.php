<?php

use App\Actions\Conversations\AcceptConversation;
use App\Enums\DataTransferStatus;
use App\Events\Broadcasts\RealtimeSignal;
use App\Events\DataTransfers\DataTransferFinished;
use App\Jobs\GenerateReport;
use App\Models\Conversation;
use App\Models\DataTransfer;
use App\Models\Department;
use App\Models\User;
use App\Reports\ReportRegistry;
use App\Services\Exports\ReportExporter;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Tests\Feature\Realtime\RealtimeTestSupport;

test('a finished export is announced to its owner only', function () {
    config(['broadcasting.default' => 'log']);
    $transfer = DataTransfer::factory()->completed()->create();
    Event::fake([RealtimeSignal::class]);

    DataTransferFinished::dispatch($transfer->id, $transfer->status->value);

    Event::assertDispatched(RealtimeSignal::class, fn (RealtimeSignal $signal): bool => $signal->event === 'transfer.changed'
        && $signal->channels === ["users.{$transfer->user_id}"]
        && $signal->payload === ['transfer_id' => $transfer->id, 'type' => 'export', 'status' => 'completed']);
});

test('a transfer reaching a final state is announced once, whoever updated it', function () {
    config(['broadcasting.default' => 'log']);
    $transfer = DataTransfer::factory()->create();
    Event::fake([RealtimeSignal::class]);

    $transfer->update(['status' => DataTransferStatus::Processing]);
    $transfer->update(['status' => DataTransferStatus::Completed, 'finished_at' => now()]);
    $transfer->update(['rows_processed' => 10]);

    Event::assertDispatchedTimes(RealtimeSignal::class, 1);
    Event::assertDispatched(RealtimeSignal::class, fn (RealtimeSignal $signal): bool => $signal->event === 'transfer.changed'
        && $signal->channels === ["users.{$transfer->user_id}"]
        && $signal->payload['status'] === 'completed');
});

test('the export job signals when a transfer ends, also when it fails', function () {
    $owner = User::factory()->agent()->create();
    $transfer = DataTransfer::factory()->create(['user_id' => $owner->id, 'report' => 'audit']);
    Event::fake([DataTransferFinished::class]);

    (new GenerateReport($transfer->id))->handle(app(ReportRegistry::class), app(ReportExporter::class));

    expect($transfer->fresh()->status)->toBe(DataTransferStatus::Failed);
    Event::assertDispatched(DataTransferFinished::class, 1);
    Event::assertDispatched(DataTransferFinished::class, fn (DataTransferFinished $event): bool => $event->transferId === $transfer->id
        && $event->status === DataTransferStatus::Failed->value);
});

test('an unreachable real-time server never breaks the operation that produced the signal', function () {
    RealtimeTestSupport::useReverb();
    Log::spy();
    $department = Department::factory()->create();
    $agent = User::factory()->agent()->available()->inDepartments($department)->create();
    $conversation = Conversation::factory()->inDepartment($department)->create();

    app(AcceptConversation::class)->handle($conversation, $agent);

    expect($conversation->fresh()->assigned_user_id)->toBe($agent->id);
    Log::shouldHaveReceived('warning')->withArgs(fn (string $message, array $context): bool => str_contains($message, 'tiempo real')
        && ! str_contains(json_encode($context), RealtimeTestSupport::SECRET));
});

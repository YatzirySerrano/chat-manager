<?php

use App\Actions\Analytics\RebuildConversationMetrics;
use App\Actions\Conversations\ConversationInboxQuery;
use App\Actions\Messaging\ReconcileStuckDeliveries;
use App\Enums\ConversationOrigin;
use App\Enums\ConversationStatus;
use App\Enums\CustomerWindowStatus;
use App\Enums\MessageDirection;
use App\Enums\MessageStatus;
use App\Events\Conversations\ConversationChanged;
use App\Events\DataTransfers\DataTransferFinished;
use App\Events\Messaging\MessageRecorded;
use App\Models\Connection;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\ConversationMetric;
use App\Models\DataTransfer;
use App\Models\Message;
use App\Models\User;
use App\Services\Analytics\ConversationMetricsCalculator;
use App\Services\Conversations\CustomerServiceWindow;
use App\Services\DashboardMetrics;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\Support\WebChatFixtures;

require_once __DIR__.'/HistoryImportSupport.php';

/*
| Imported history never behaves as live activity (contract C3): archived
| history stays out of the inbox, its search and exports, and the API;
| every imported conversation stays out of the dashboard, the metrics, the
| 24 hour window, reopening / late arrivals of inbound messages and the
| delivery reconciliation; importing dispatches no notification, event or
| automation.
*/

beforeEach(function () {
    Storage::fake('local');
    $this->admin = User::factory()->superAdministrator()->create();
    $this->connection = Connection::factory()->operational()->create(['reopen_window_minutes' => 120]);
});

/**
 * A conversation as the importer leaves it.
 */
function historyImportedConversation(Connection $connection, Contact $contact, bool $archived, array $attributes = []): Conversation
{
    $batch = DataTransfer::factory()->create(['type' => 'history_import', 'report' => 'whaticket-history', 'status' => 'completed']);
    $conversation = Conversation::factory()->closed()->create(['connection_id' => $connection->id, 'contact_id' => $contact->id]);
    $conversation->forceFill([
        'origin' => ConversationOrigin::Whaticket,
        'history_only_at' => $archived ? now() : null,
        'imported_by_transfer_id' => $batch->id,
        'closed_at' => now()->subMinutes(5),
        ...$attributes,
    ])->save();

    return $conversation->refresh();
}

test('archived history is not in the inbox, its counters or its search; operational history is under closed', function () {
    $contact = Contact::factory()->create(['phone_e164' => '+525500000021']);
    $archived = historyImportedConversation($this->connection, $contact, true);
    $operational = historyImportedConversation($this->connection, Contact::factory()->create(['phone_e164' => '+525500000022']), false);
    $live = Conversation::factory()->closed()->create(['connection_id' => $this->connection->id]);

    $inbox = app(ConversationInboxQuery::class);
    $closed = $inbox->handle($this->admin, $inbox->filters($this->admin, ['view' => 'all', 'status' => 'closed']))->pluck('conversations.id')->all();
    $search = $inbox->handle($this->admin, $inbox->filters($this->admin, ['view' => 'all', 'status' => 'all', 'search' => '5500000021']))->pluck('conversations.id')->all();

    expect($closed)->toContain($operational->id, $live->id)
        ->and($closed)->not->toContain($archived->id)
        ->and($search)->toBe([]);
});

test('imported history is outside the dashboard and every metric', function () {
    $imported = historyImportedConversation($this->connection, Contact::factory()->create(), false, ['closed_at' => now()]);
    Conversation::factory()->closed()->create(['connection_id' => $this->connection->id, 'closed_at' => now()]);
    ConversationMetric::query()->insert(['conversation_id' => $imported->id, 'connection_id' => $this->connection->id, 'status' => 'closed', 'received_at' => now(), 'computed_at' => now()]);

    expect(app(DashboardMetrics::class)->overviewFor($this->admin)['conversations']['closed_today'])->toBe(1)
        ->and(app(ConversationMetricsCalculator::class)->recompute($imported->id))->toBeNull()
        ->and(ConversationMetric::query()->where('conversation_id', $imported->id)->exists())->toBeFalse()
        ->and(app(RebuildConversationMetrics::class)->count(null, [$imported->id]))->toBe(0);

    app(RebuildConversationMetrics::class)->handle();
    expect(ConversationMetric::query()->where('conversation_id', $imported->id)->exists())->toBeFalse();
});

test('a customer who writes again starts a live conversation: imported history is never reopened nor receives the message', function () {
    $contact = Contact::factory()->create(['phone_e164' => '+525500000031']);
    // Closed a few minutes ago, inside the reopen window, and "after" the message time (late arrival case).
    $imported = historyImportedConversation($this->connection, $contact, true, ['closed_at' => now()->addMinutes(10)]);

    $receipt = receiveWhatsapp($this->connection, '5215500000031', 'Hola de nuevo (ficticio)');

    $live = Conversation::query()->live()->where('contact_id', $contact->id)->sole();
    expect($receipt->conversation->id)->toBe($live->id)
        ->and($live->status)->not->toBe(ConversationStatus::Closed)
        ->and($imported->refresh()->status)->toBe(ConversationStatus::Closed)
        ->and($imported->reopen_count)->toBe(0)
        ->and(Message::query()->where('conversation_id', $imported->id)->count())->toBe(0);
});

test('imported history never opens the 24 hour window', function () {
    $imported = historyImportedConversation($this->connection, Contact::factory()->create(), false, ['last_inbound_at' => now()->subMinutes(5)]);

    expect(app(CustomerServiceWindow::class)->for($imported)->status)->toBe(CustomerWindowStatus::None);
});

test('the integration API never lists nor reaches imported history', function () {
    $imported = historyImportedConversation($this->connection, Contact::factory()->create(), false);
    $archived = historyImportedConversation($this->connection, Contact::factory()->create(), true);
    $live = Conversation::factory()->create(['connection_id' => $this->connection->id]);
    ['token' => $token, 'plain' => $plain] = WebChatFixtures::apiToken(['connection_ids' => [$this->connection->id]]);

    expect($this->withToken($plain)->getJson(route('api.v1.conversations.index'))->assertOk()->json('data.*.id'))->toBe([$live->id])
        ->and($token->reaches($imported))->toBeFalse();

    $this->withToken($plain)->getJson(route('api.v1.conversations.show', ['conversation' => $archived->id]))->assertNotFound();
});

test('the delivery reconciliation never touches messages of imported history', function () {
    $imported = historyImportedConversation($this->connection, Contact::factory()->create(), false);
    $message = Message::factory()->create([
        'conversation_id' => $imported->id,
        'direction' => MessageDirection::Outbound,
        'status' => MessageStatus::Sending,
    ]);
    $message->forceFill(['sending_started_at' => now()->subHour()])->save();

    expect(app(ReconcileStuckDeliveries::class)->handle())->toBe(0)
        ->and($message->refresh()->status)->toBe(MessageStatus::Sending);
});

test('importing sends no notification and dispatches no message, conversation or transfer event', function () {
    Notification::fake();
    Event::fake([MessageRecorded::class, ConversationChanged::class, DataTransferFinished::class]);
    $lastInbound = $this->connection->last_inbound_at?->toIso8601String();

    $batch = historyStandardBatch($this->admin);
    historyMapPlaceholders($this->admin, $batch);
    historyDecide($this->admin, $batch, 'import');
    historyDecide($this->admin, $batch, 'archive', ['group' => true]);
    historyDecide($this->admin, $batch, 'exclude', ['empty' => true]);
    historyConfirm($this->admin, $batch);

    expect(Conversation::query()->imported()->count())->toBe(4);
    Notification::assertNothingSent();
    Event::assertNotDispatched(MessageRecorded::class);
    Event::assertNotDispatched(ConversationChanged::class);
    Event::assertNotDispatched(DataTransferFinished::class);
    expect(Conversation::query()->imported()->whereNotNull('last_inbound_at')->count())->toBe(0)
        ->and(Message::query()->whereIn('conversation_id', Conversation::query()->imported()->select('id'))->whereIn('status', ['queued', 'sending', 'accepted', 'delivered', 'read', 'failed'])->count())->toBe(0)
        ->and($this->connection->refresh()->last_inbound_at?->toIso8601String())->toBe($lastInbound);
});

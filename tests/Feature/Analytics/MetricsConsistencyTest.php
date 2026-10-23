<?php

use App\Actions\Analytics\RebuildConversationMetrics;
use App\Actions\Conversations\AcceptConversation;
use App\Actions\Conversations\CloseConversation;
use App\Actions\Conversations\ReopenConversation;
use App\Actions\Conversations\TransferConversation;
use App\Enums\ConversationEventType;
use App\Enums\DistributionMode;
use App\Enums\MessageDirection;
use App\Models\Connection;
use App\Models\Conversation;
use App\Models\ConversationMetric;
use App\Models\Department;
use App\Models\Message;
use App\Models\User;
use App\Services\Analytics\ConversationMetricsCalculator;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use Tests\Support\WhatsAppFixtures;

/*
| Coherence of the per-conversation metrics: what the event listeners keep
| up to date, what the rebuild recomputes and what a direct query over the
| source tables says must be the same figures; repeated webhooks never
| count twice and the automatic distribution is not human attention.
*/

beforeEach(function () {
    Http::preventStrayRequests();
    // Monday 2026-10-19 10:00 in Mexico City.
    $this->start = CarbonImmutable::parse('2026-10-19 10:00', 'America/Mexico_City')->utc();
    $this->travelTo($this->start);
    $this->automatic = Department::factory()->distribution(DistributionMode::Automatic)->create(['name' => 'Automático']);
    $this->manual = Department::factory()->create(['name' => 'Manual']);
    $this->agent = User::factory()->agent()->available()->inDepartments([$this->automatic, $this->manual])->create();
    $this->colleague = User::factory()->agent()->available()->inDepartments($this->manual)->create();
    $this->admin = User::factory()->administrator()->create();
    $this->connection = Connection::factory()->operational()->create([
        'business_account_id' => '102290129340398',
        'default_department_id' => $this->automatic->id,
        'welcome_enabled' => true,
        'welcome_message' => 'Hola, gracias por escribirnos.',
    ]);
    WhatsAppFixtures::transport();
});

/**
 * A reply written by the agent through the real endpoint.
 */
function replyAsAgent(User $agent, Conversation $conversation, string $body = 'Con gusto le ayudo.'): void
{
    test()->actingAs($agent)
        ->postJson(route('conversations.messages.store', $conversation), ['body' => $body, 'client_reference' => (string) str()->uuid()])
        ->assertStatus(202);
}

/**
 * The figures of one conversation computed straight from the source tables,
 * independently of the calculator (the "direct query" side).
 *
 * @return array<string, mixed>
 */
function directFigures(Conversation $conversation): array
{
    $events = $conversation->events()->orderBy('created_at')->orderBy('id')->get();
    $human = Message::query()
        ->where('conversation_id', $conversation->id)
        ->where('direction', MessageDirection::Outbound)
        ->whereNotNull('sender_user_id')
        ->whereIn('status', ConversationMetricsCalculator::ACCEPTED_STATUSES)
        ->get()
        ->reject(fn (Message $message): bool => (bool) ($message->metadata['automatic'] ?? false))
        ->min('created_at');
    $attention = $events->first(fn ($event): bool => in_array($event->type, [ConversationEventType::Accepted, ConversationEventType::Assigned], true)
        || ($event->type === ConversationEventType::Transferred && $event->to_user_id !== null))?->created_at;
    $firstAttention = collect([$attention, $human])->filter()->min();
    $resolved = $events->firstWhere('type', ConversationEventType::Closed)?->created_at;
    $seconds = fn ($moment): ?int => $moment === null ? null : (int) $conversation->created_at->diffInSeconds($moment);

    return [
        'seconds_to_first_attention' => $seconds($firstAttention),
        'seconds_to_first_human_response' => $seconds($human),
        'seconds_to_resolution' => $seconds($resolved),
        // Continuous attention (no schedule): business time is calendar time.
        'business_seconds_to_first_human_response' => $seconds($human),
        'business_seconds_to_resolution' => $seconds($resolved),
        'closed_count' => $events->where('type', ConversationEventType::Closed)->count(),
        'reopened_count' => $events->where('type', ConversationEventType::Reopened)->count(),
        'transfers_count' => $events->where('type', ConversationEventType::Transferred)->count(),
        'messages_in' => Message::query()->where('conversation_id', $conversation->id)->where('direction', MessageDirection::Inbound)->count(),
        'messages_out' => Message::query()->where('conversation_id', $conversation->id)->where('direction', MessageDirection::Outbound)->count(),
    ];
}

/**
 * @return array<int, array<string, mixed>>
 */
function storedFigures(): array
{
    return ConversationMetric::query()->orderBy('conversation_id')->get()
        ->mapWithKeys(fn (ConversationMetric $metric): array => [$metric->conversation_id => $metric->only([
            'seconds_to_first_attention', 'seconds_to_first_human_response', 'seconds_to_resolution',
            'business_seconds_to_first_human_response', 'business_seconds_to_resolution',
            'closed_count', 'reopened_count', 'transfers_count', 'messages_in', 'messages_out',
        ])])
        ->all();
}

test('the event driven figures, the rebuild and a direct query agree for a set of cases', function () {
    // 1. Distributed automatically and answered 4 minutes later.
    $autoAnswered = receiveWhatsapp($this->connection, '5215500000001')->conversation;
    $this->travelTo($this->start->addMinutes(4));
    replyAsAgent($this->agent, $autoAnswered->refresh());

    // 2. Distributed automatically, never answered (only the automatic greeting).
    $autoUnanswered = receiveWhatsapp($this->connection, '5215500000002')->conversation;

    // 3. Manual queue: accepted, closed, reopened and closed again.
    $this->travelTo($this->start->addMinutes(10));
    $manual = Conversation::factory()->for($this->connection, 'channelConnection')->inDepartment($this->manual)->create();
    $this->travelTo($this->start->addMinutes(12));
    app(AcceptConversation::class)->handle($manual, $this->agent);
    $this->travelTo($this->start->addMinutes(20));
    app(CloseConversation::class)->handle($manual->refresh(), $this->admin);
    $this->travelTo($this->start->addMinutes(25));
    app(ReopenConversation::class)->handle($manual->refresh(), $this->admin);
    $this->travelTo($this->start->addMinutes(30));
    app(CloseConversation::class)->handle($manual->refresh(), $this->admin);

    // 4. Transferred to a specific agent by a supervisor, then answered.
    $transferred = Conversation::factory()->for($this->connection, 'channelConnection')->inDepartment($this->manual)->create();
    $this->travelTo($this->start->addMinutes(33));
    app(TransferConversation::class)->handle($transferred, $this->manual, $this->colleague, $this->admin);
    $this->travelTo($this->start->addMinutes(35));
    replyAsAgent($this->colleague, $transferred->refresh());

    $byEvents = storedFigures();

    ConversationMetric::query()->delete();
    app(RebuildConversationMetrics::class)->handle();
    $byRebuild = storedFigures();

    $direct = Conversation::query()->orderBy('id')->get()->mapWithKeys(fn (Conversation $conversation): array => [$conversation->id => directFigures($conversation)])->all();

    expect($byEvents)->toHaveCount(4)
        ->and($byEvents)->toEqual($direct)
        ->and($byRebuild)->toEqual($direct)
        // The automatic distribution is not attention: it starts with the reply.
        ->and($direct[$autoAnswered->id]['seconds_to_first_attention'])->toBe(240)
        ->and($direct[$autoUnanswered->id]['seconds_to_first_attention'])->toBeNull()
        ->and($direct[$manual->id])->toMatchArray(['seconds_to_first_attention' => 120, 'seconds_to_resolution' => 600, 'closed_count' => 2, 'reopened_count' => 1])
        ->and($direct[$transferred->id])->toMatchArray(['seconds_to_first_attention' => 180, 'seconds_to_first_human_response' => 300, 'transfers_count' => 1]);
});

test('an automatically assigned conversation is attended when its agent accepts or answers it', function () {
    $conversation = receiveWhatsapp($this->connection)->conversation;
    $metric = fn (): ConversationMetric => ConversationMetric::query()->where('conversation_id', $conversation->id)->sole();

    expect($conversation->events()->where('type', ConversationEventType::AutoAssigned)->exists())->toBeTrue()
        ->and($metric()->first_assigned_at)->toBeNull()
        ->and($metric()->seconds_to_first_attention)->toBeNull();

    // A later acceptance (e.g. after the agent returned it) is the attention.
    $this->travelTo($this->start->addMinutes(3));
    $conversation->events()->create(['type' => ConversationEventType::Accepted, 'actor_user_id' => $this->agent->id, 'to_user_id' => $this->agent->id, 'created_at' => now()]);
    app(RebuildConversationMetrics::class)->handle(conversationIds: [$conversation->id]);

    expect($metric()->seconds_to_first_attention)->toBe(180);
});

test('a repeated webhook delivery never counts the message twice', function () {
    $payload = whatsappWebhook($this->connection, [whatsappTextMessage('5215512345678', 'Hola', id: 'wamid.repeated.1')], ['5215512345678' => 'Laura Pérez']);

    WhatsAppFixtures::post($this, $this->connection, $payload)->assertOk();
    WhatsAppFixtures::post($this, $this->connection, $payload)->assertOk();
    app(RebuildConversationMetrics::class)->handle();

    expect(ConversationMetric::query()->sole())
        ->messages_in->toBe(1)
        ->messages_out->toBe(1);
});

test('the daily reconciliation picks up a delivery status that changed only the message', function () {
    $conversation = Conversation::factory()->for($this->connection, 'channelConnection')->inDepartment($this->manual)->assignedTo($this->agent)
        ->create(['created_at' => now()->subDays(10), 'updated_at' => now()->subDays(10)]);
    $reply = WhatsAppFixtures::outbound($conversation, ['sender_user_id' => $this->agent->id, 'created_at' => now()->subDays(10)->addMinutes(5), 'updated_at' => now()->subDays(10)->addMinutes(5)]);
    app(RebuildConversationMetrics::class)->handle();
    expect(ConversationMetric::query()->where('conversation_id', $conversation->id)->value('first_human_response_at'))->toBeNull();

    // The status moves without the metrics job (e.g. a lost refresh).
    Message::withoutEvents(fn () => $reply->forceFill(['status' => 'sent'])->save());

    $this->artisan('analytics:rebuild', ['--days' => 2])->expectsOutputToContain('Métricas recalculadas: 1')->assertSuccessful();

    expect(ConversationMetric::query()->where('conversation_id', $conversation->id)->value('seconds_to_first_human_response'))->toBe(300);
});

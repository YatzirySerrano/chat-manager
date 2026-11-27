<?php

use App\Enums\ConversationOrigin;
use App\Enums\ConversationOutcome;
use App\Enums\MessageDirection;
use App\Enums\MessageStatus;
use App\Enums\MessageType;
use App\Models\Conversation;
use App\Models\ConversationRead;
use App\Models\Department;
use App\Models\Message;
use App\Models\User;
use App\Services\Conversations\ContactFullHistory;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia as Assert;

/*
| «Solicitudes anteriores» (details panel) tells each previous conversation
| as the request it was: whose client, its facts, who attended it and where
| it ended. «Conversación completa» reads every visible conversation of the
| contact as one chat, paged backwards with a cursor, with a boundary per
| request; nothing the viewer may not open is ever listed.
*/

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-26 12:00:00', 'UTC'));
    $this->department = Department::factory()->create();
    $this->agent = User::factory()->agent()->available()->inDepartments($this->department)->create(['name' => 'Alejandra']);
    $this->colleague = User::factory()->agent()->available()->inDepartments($this->department)->create();
    $this->conversation = Conversation::factory()->inDepartment($this->department)->assignedTo($this->agent)->create();
});

/**
 * A closed earlier conversation of the same contact and connection.
 *
 * @param  array<string, mixed>  $attributes
 */
function previousRequest(object $test, User $assignee, string $createdAt, array $attributes = []): Conversation
{
    return Conversation::factory()->inDepartment($test->department)->assignedTo($assignee)->closed()->create([
        'contact_id' => $test->conversation->contact_id,
        'connection_id' => $test->conversation->connection_id,
        'created_at' => CarbonImmutable::parse($createdAt, 'UTC'),
        ...$attributes,
    ]);
}

/**
 * @return list<array{key: string, label: string, value: string}>
 */
function requestFieldsOf(string $client, ?string $type = null, ?string $product = null, ?string $amount = null): array
{
    return array_values(array_filter([
        ['key' => 'cliente', 'label' => 'Cliente', 'value' => $client],
        $type === null ? null : ['key' => 'tipo', 'label' => 'Tipo', 'value' => $type],
        $product === null ? null : ['key' => 'producto', 'label' => 'Producto', 'value' => $product],
        $amount === null ? null : ['key' => 'monto_solicitado', 'label' => 'Monto solicitado', 'value' => $amount],
    ]));
}

/**
 * Messages one minute apart from the given time, oldest first.
 *
 * @return list<Message>
 */
function historyMessages(Conversation $conversation, int $count, string $from): array
{
    $start = CarbonImmutable::parse($from, 'UTC');
    $messages = [];

    foreach (range(0, $count - 1) as $offset) {
        $at = $start->addMinutes($offset);
        $message = Message::factory()->create(['conversation_id' => $conversation->id, 'body' => "C{$conversation->id} #{$offset}"]);
        $message->forceFill(['created_at' => $at, 'sent_at' => $at])->save();
        $messages[] = $message;
    }

    return $messages;
}

test('previous requests say whose client they were and where they ended', function () {
    $approved = previousRequest($this, $this->agent, '2026-11-20 10:00:00', [
        'request_fields' => requestFieldsOf('BEATRIZ GUERRERO OLVERA', 'Reactivación', 'S12', '$5,000'),
        'completed_at' => now(),
        'outcome' => ConversationOutcome::Approved,
        'outcome_amount' => '3000.00',
    ]);
    $rejected = previousRequest($this, $this->agent, '2026-11-21 10:00:00', [
        'request_fields' => requestFieldsOf('JUAN PÉREZ'),
        'completed_at' => now(),
        'outcome' => ConversationOutcome::Rejected,
    ]);
    $completed = previousRequest($this, $this->agent, '2026-11-22 10:00:00', ['completed_at' => now()]);
    $closed = previousRequest($this, $this->agent, '2026-11-23 10:00:00');

    $this->actingAs($this->agent)
        ->get(route('conversations.index', ['c' => $this->conversation->id]))
        ->assertInertia(fn (Assert $page) => $page
            ->where('details.historyTotal', 4)
            ->where('details.history', function ($history) use ($approved, $rejected, $completed, $closed) {
                $rows = collect($history)->keyBy('id');

                expect($rows->keys()->all())->toBe([$closed->id, $completed->id, $rejected->id, $approved->id])
                    ->and($rows[$approved->id])->toMatchArray([
                        'client' => 'BEATRIZ GUERRERO OLVERA',
                        'type' => 'Reactivación',
                        'product' => 'S12',
                        'requested_amount' => '$5,000',
                        'assignee' => 'Alejandra',
                        'result' => ['value' => 'approved', 'label' => 'Crédito aprobado', 'amount' => '3000.00'],
                    ])
                    ->and($rows[$approved->id]['department']['id'])->toBe($this->department->id)
                    ->and($rows[$rejected->id]['client'])->toBe('JUAN PÉREZ')
                    ->and($rows[$rejected->id]['result'])->toBe(['value' => 'rejected', 'label' => 'Rechazada', 'amount' => null])
                    ->and($rows[$completed->id]['client'])->toBeNull()
                    ->and($rows[$completed->id]['result']['value'])->toBe('completed')
                    ->and($rows[$closed->id]['result'])->toBe(['value' => 'closed', 'label' => 'Cerrada', 'amount' => null])
                    // The fields the panel already used are still there.
                    ->and($rows[$closed->id])->toHaveKeys(['status', 'status_label', 'created_at', 'closed_at', 'origin', 'history_only', 'history_url']);

                return true;
            }));
});

test('an outcome only counts once the request was completed', function () {
    $previous = previousRequest($this, $this->agent, '2026-11-20 10:00:00', ['outcome' => ConversationOutcome::Approved, 'outcome_amount' => '1000.00']);

    $this->actingAs($this->agent)
        ->get(route('conversations.index', ['c' => $this->conversation->id]))
        ->assertInertia(fn (Assert $page) => $page
            ->where('details.history.0.id', $previous->id)
            ->where('details.history.0.result.value', 'closed'));
});

test('previous requests only list what the viewer may see, and count only that', function () {
    $own = previousRequest($this, $this->agent, '2026-11-20 10:00:00');
    previousRequest($this, $this->colleague, '2026-11-21 10:00:00', ['request_fields' => requestFieldsOf('CLIENTE AJENO')]);

    $this->actingAs($this->agent)
        ->get(route('conversations.index', ['c' => $this->conversation->id]))
        ->assertInertia(fn (Assert $page) => $page
            ->where('details.historyTotal', 1)
            ->where('details.history', fn ($history) => collect($history)->pluck('id')->all() === [$own->id]));
});

test('the full history reads every visible conversation of the contact in chronological order, with a boundary per request', function () {
    $older = previousRequest($this, $this->agent, '2026-11-20 10:00:00', [
        'request_fields' => requestFieldsOf('BEATRIZ GUERRERO OLVERA'),
        'completed_at' => now(),
        'outcome' => ConversationOutcome::Approved,
        'outcome_amount' => '3000.00',
    ]);
    $olderMessages = historyMessages($older, 2, '2026-11-20 10:00:00');
    $currentMessages = historyMessages($this->conversation, 3, '2026-11-26 09:00:00');
    Message::factory()->create([
        'conversation_id' => $this->conversation->id,
        'type' => MessageType::Reaction,
        'body' => null,
        'metadata' => ['content' => ['emoji' => '👍', 'message_id' => 'wamid.x']],
    ]);

    $response = $this->actingAs($this->agent)
        ->getJson(route('conversations.full-history', $this->conversation))
        ->assertOk()
        ->assertJsonPath('next', null)
        ->assertJsonPath('segments.0.conversation.id', $older->id)
        ->assertJsonPath('segments.0.conversation.client', 'BEATRIZ GUERRERO OLVERA')
        ->assertJsonPath('segments.0.conversation.result.value', 'approved')
        ->assertJsonPath('segments.0.conversation.assignee', 'Alejandra')
        ->assertJsonPath('segments.0.conversation.is_current', false)
        ->assertJsonPath('segments.0.starts', true)
        ->assertJsonPath('segments.1.conversation.id', $this->conversation->id)
        ->assertJsonPath('segments.1.conversation.is_current', true)
        ->assertJsonStructure(['contact' => ['display_name'], 'appearance']);

    expect(collect($response->json('segments.0.messages'))->pluck('id')->all())->toBe(collect($olderMessages)->pluck('id')->all())
        // Reactions travel on the message they react to, not as bubbles.
        ->and(collect($response->json('segments.1.messages'))->pluck('id')->all())->toBe(collect($currentMessages)->pluck('id')->all());
});

test('the full history pages backwards across conversations without gaps or repetitions', function () {
    $older = previousRequest($this, $this->agent, '2026-11-20 10:00:00');
    $olderMessages = historyMessages($older, 30, '2026-11-20 10:00:00');
    $currentMessages = historyMessages($this->conversation, ContactFullHistory::PAGE_SIZE - 10, '2026-11-26 09:00:00');

    $first = $this->actingAs($this->agent)->getJson(route('conversations.full-history', $this->conversation))->assertOk();

    // The page ends inside the older conversation: its newest 10 messages, and the cursor stops there.
    expect($first->json('segments.0.conversation.id'))->toBe($older->id)
        ->and($first->json('segments.0.starts'))->toBeFalse()
        ->and(collect($first->json('segments.0.messages'))->pluck('id')->all())->toBe(collect(array_slice($olderMessages, 20))->pluck('id')->all())
        ->and($first->json('segments.1.starts'))->toBeTrue()
        ->and($first->json('next'))->toBe(['conversation' => $older->id, 'message' => $olderMessages[20]->id]);

    $second = $this->actingAs($this->agent)->getJson(route('conversations.full-history', [
        'conversation' => $this->conversation,
        'before_conversation' => $older->id,
        'before_message' => $olderMessages[20]->id,
    ]))->assertOk()->assertJsonPath('next', null)->assertJsonCount(1, 'segments');

    $seen = collect($second->json('segments.0.messages'))
        ->merge($first->json('segments.0.messages'))
        ->merge($first->json('segments.1.messages'))
        ->pluck('id')
        ->all();

    expect($second->json('segments.0.starts'))->toBeTrue()
        ->and($seen)->toBe(collect([...$olderMessages, ...$currentMessages])->pluck('id')->all());
});

test('a page that fills up right at a boundary continues with the previous conversation', function () {
    $older = previousRequest($this, $this->agent, '2026-11-20 10:00:00');
    historyMessages($older, 3, '2026-11-20 10:00:00');
    historyMessages($this->conversation, ContactFullHistory::PAGE_SIZE, '2026-11-26 09:00:00');

    $this->actingAs($this->agent)->getJson(route('conversations.full-history', $this->conversation))
        ->assertOk()
        ->assertJsonCount(1, 'segments')
        ->assertJsonPath('segments.0.starts', true)
        ->assertJsonPath('next', ['conversation' => $older->id, 'message' => null]);

    $this->actingAs($this->agent)->getJson(route('conversations.full-history', ['conversation' => $this->conversation, 'before_conversation' => $older->id]))
        ->assertOk()
        ->assertJsonPath('segments.0.conversation.id', $older->id)
        ->assertJsonCount(3, 'segments.0.messages')
        ->assertJsonPath('next', null);
});

test('the full history never lists conversations the viewer may not open', function () {
    $own = previousRequest($this, $this->agent, '2026-11-19 10:00:00');
    historyMessages($own, 1, '2026-11-19 10:00:00');
    $colleagues = previousRequest($this, $this->colleague, '2026-11-20 10:00:00', ['request_fields' => requestFieldsOf('CLIENTE AJENO')]);
    historyMessages($colleagues, 2, '2026-11-20 10:00:00');
    $archived = previousRequest($this, $this->agent, '2026-11-21 10:00:00', ['origin' => ConversationOrigin::Whaticket, 'history_only_at' => now()]);
    historyMessages($archived, 2, '2026-11-21 10:00:00');
    // Another contact's conversation is never part of this history.
    historyMessages(Conversation::factory()->inDepartment($this->department)->assignedTo($this->agent)->create(), 2, '2026-11-22 10:00:00');
    historyMessages($this->conversation, 1, '2026-11-26 09:00:00');

    $response = $this->actingAs($this->agent)->getJson(route('conversations.full-history', $this->conversation))->assertOk();

    expect(collect($response->json('segments'))->pluck('conversation.id')->all())->toBe([$own->id, $this->conversation->id])
        ->and($response->getContent())->not->toContain('CLIENTE AJENO');

    // A cursor naming a conversation outside the viewer's reach reads as an empty page.
    $this->actingAs($this->agent)->getJson(route('conversations.full-history', ['conversation' => $this->conversation, 'before_conversation' => $colleagues->id]))
        ->assertOk()
        ->assertJsonPath('segments', [])
        ->assertJsonPath('next', null);

    // Opened from a conversation the viewer may not see: as if it did not exist.
    $this->actingAs($this->agent)->getJson(route('conversations.full-history', $colleagues))->assertNotFound();
});

test('reading the full history changes no read marker', function () {
    historyMessages($this->conversation, 2, '2026-11-26 09:00:00');
    Message::factory()->create([
        'conversation_id' => $this->conversation->id,
        'direction' => MessageDirection::Outbound,
        'status' => MessageStatus::Sent,
        'sender_user_id' => $this->agent->id,
    ]);
    $reads = ConversationRead::query()->count();

    $this->actingAs($this->agent)->getJson(route('conversations.full-history', $this->conversation))->assertOk();

    expect(ConversationRead::query()->count())->toBe($reads);
});

test('the full history validates its cursor', function () {
    $this->actingAs($this->agent)
        ->getJson(route('conversations.full-history', ['conversation' => $this->conversation, 'before_conversation' => 'x']))
        ->assertUnprocessable();
});

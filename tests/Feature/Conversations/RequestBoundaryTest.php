<?php

use App\Enums\AuditAction;
use App\Enums\ConversationEventType;
use App\Enums\ConversationOrigin;
use App\Enums\ConversationOutcome;
use App\Enums\ConversationStatus;
use App\Enums\MessageDirection;
use App\Enums\MessageType;
use App\Models\AuditLog;
use App\Models\Conversation;
use App\Models\ConversationMetric;
use App\Models\ConversationNote;
use App\Models\Department;
use App\Models\Message;
use App\Models\MessageAttachment;
use App\Models\ResponseCycle;
use App\Models\User;
use App\Services\Analytics\ConversationMetricsCalculator;
use Carbon\CarbonImmutable;
use Illuminate\Testing\TestResponse;

/*
| «Corregir tamaño de la solicitud» (AdjustRequestBoundary): from the
| separator of a request in «Conversación completa», a manager moves the
| boundary with the request right before it (same contact and connection).
| Messages move between both, and a request left empty is absorbed by the
| other one: hidden everywhere, never deleted, and when the absorbed one was
| still in attention the joined request takes its state.
*/

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-27 12:00:00', 'UTC'));
    $this->department = Department::factory()->create();
    $this->admin = User::factory()->administrator()->create();
    $this->agent = User::factory()->agent()->available()->inDepartments($this->department)->create();
    $this->julia = Conversation::factory()->inDepartment($this->department)->assignedTo($this->agent)->closed()->create([
        'created_at' => CarbonImmutable::parse('2026-11-27 09:00:00', 'UTC'),
        'completed_at' => CarbonImmutable::parse('2026-11-27 10:00:00', 'UTC'),
        'request_fields' => [['key' => 'cliente', 'label' => 'Cliente', 'value' => 'JULIA', 'message_id' => null, 'captured_at' => null]],
    ]);
    $this->beatriz = Conversation::factory()->inDepartment($this->department)->assignedTo($this->agent)->create([
        'contact_id' => $this->julia->contact_id,
        'connection_id' => $this->julia->connection_id,
        'created_at' => CarbonImmutable::parse('2026-11-27 11:00:00', 'UTC'),
    ]);
});

/**
 * Messages one minute apart, oldest first.
 *
 * @return list<Message>
 */
function boundaryMessages(Conversation $conversation, int $count, string $from, MessageDirection $direction = MessageDirection::Inbound): array
{
    $start = CarbonImmutable::parse($from, 'UTC');
    $messages = [];

    foreach (range(0, $count - 1) as $offset) {
        $at = $start->addMinutes($offset);
        $message = Message::factory()->create(['conversation_id' => $conversation->id, 'direction' => $direction, 'body' => "C{$conversation->id} #{$offset}"]);
        $message->forceFill(['created_at' => $at, 'sent_at' => $at])->save();
        $messages[] = $message;
    }

    return $messages;
}

/**
 * @param  array<string, mixed>  $extra
 */
function adjustBoundary(User $actor, Conversation $current, Conversation $previous, ?int $startsAt, array $extra = []): TestResponse
{
    return test()->actingAs($actor)->postJson(route('conversations.boundary.store', $current), [
        'previous_id' => $previous->id,
        'starts_at' => $startsAt,
        'version' => $current->refresh()->version,
        'previous_version' => $previous->refresh()->version,
        ...$extra,
    ]);
}

test('the tail of the earlier request moves into the later one, with its files and captured data', function () {
    $tail = boundaryMessages($this->julia, 5, '2026-11-27 09:00:00');
    $head = boundaryMessages($this->beatriz, 2, '2026-11-27 11:00:00');
    $attachment = MessageAttachment::query()->forceCreate(['message_id' => $tail[4]->id, 'disk' => 'local', 'path' => 'media/ine.pdf', 'mime_type' => 'application/pdf', 'file_name' => 'ine.pdf']);
    $this->julia->forceFill(['request_fields' => [
        ['key' => 'cliente', 'label' => 'Cliente', 'value' => 'JULIA', 'message_id' => $tail[0]->id, 'captured_at' => null],
        ['key' => 'producto', 'label' => 'Producto', 'value' => 'S12', 'message_id' => $tail[3]->id, 'captured_at' => null],
    ]])->save();
    $versions = [$this->julia->version, $this->beatriz->version];

    adjustBoundary($this->admin, $this->beatriz, $this->julia, $tail[3]->id)
        ->assertOk()
        ->assertJson(['moved_to_current' => 2, 'moved_to_previous' => 0, 'merged' => null]);

    $this->julia->refresh();
    $this->beatriz->refresh();

    expect(Message::query()->where('conversation_id', $this->beatriz->id)->orderBy('id')->pluck('id')->all())
        ->toBe([$tail[3]->id, $tail[4]->id, $head[0]->id, $head[1]->id])
        ->and($attachment->refresh()->message->conversation_id)->toBe($this->beatriz->id)
        // Derived columns follow the messages; created_at stays.
        ->and($this->julia->last_message_at->toDateTimeString())->toBe('2026-11-27 09:02:00')
        ->and($this->julia->last_inbound_at->toDateTimeString())->toBe('2026-11-27 09:02:00')
        ->and($this->beatriz->created_at->toDateTimeString())->toBe('2026-11-27 11:00:00')
        ->and($this->julia->version)->toBe($versions[0] + 1)
        ->and($this->beatriz->version)->toBe($versions[1] + 1)
        // State untouched: no merge.
        ->and($this->julia->status)->toBe(ConversationStatus::Closed)
        ->and($this->julia->completed_at)->not->toBeNull()
        ->and($this->beatriz->status)->toBe(ConversationStatus::Open)
        // «Producto» was captured from a moved message: it goes with it.
        ->and(collect($this->julia->request_fields)->pluck('key')->all())->toBe(['cliente'])
        ->and(collect($this->beatriz->request_fields)->pluck('value', 'key')->all())->toBe(['producto' => 'S12']);

    $event = $this->julia->events()->where('type', ConversationEventType::BoundaryAdjusted)->sole();

    expect($event->actor_user_id)->toBe($this->admin->id)
        ->and($event->metadata)->toMatchArray(['side' => 'previous', 'other_conversation_id' => $this->beatriz->id, 'moved_in' => 0, 'moved_out' => 2, 'merged' => null])
        ->and($event->note)->toContain('2 mensajes pasaron a la solicitud siguiente')
        ->and($this->beatriz->events()->where('type', ConversationEventType::BoundaryAdjusted)->sole()->metadata)->toMatchArray(['moved_in' => 2])
        ->and(AuditLog::query()->where('action', AuditAction::ConversationBoundaryAdjusted)->sole())
        ->auditable_id->toBe($this->beatriz->id)
        ->user_id->toBe($this->admin->id);
});

test('the head of the later request moves into the earlier one, and a reaction follows its message', function () {
    $tail = boundaryMessages($this->julia, 2, '2026-11-27 09:00:00');
    $head = boundaryMessages($this->beatriz, 3, '2026-11-27 11:00:00');
    $reaction = Message::factory()->create([
        'conversation_id' => $this->beatriz->id,
        'type' => MessageType::Reaction,
        'body' => null,
        'metadata' => ['content' => ['message_id' => $head[0]->whatsapp_message_id, 'emoji' => '👍']],
    ]);
    $reaction->forceFill(['created_at' => CarbonImmutable::parse('2026-11-27 11:00:30', 'UTC')])->save();

    adjustBoundary($this->admin, $this->beatriz, $this->julia, $head[1]->id)
        ->assertOk()
        ->assertJson(['moved_to_previous' => 1, 'moved_to_current' => 0, 'merged' => null]);

    expect($head[0]->refresh()->conversation_id)->toBe($this->julia->id)
        ->and($reaction->refresh()->conversation_id)->toBe($this->julia->id)
        ->and($head[1]->refresh()->conversation_id)->toBe($this->beatriz->id)
        ->and($tail[1]->refresh()->conversation_id)->toBe($this->julia->id)
        ->and($this->julia->refresh()->last_inbound_at->toDateTimeString())->toBe('2026-11-27 11:00:30');
});

test('joining an active later request into a completed one reopens it with the later state and keeps the result', function () {
    boundaryMessages($this->julia, 2, '2026-11-27 09:00:00');
    $head = boundaryMessages($this->beatriz, 2, '2026-11-27 11:00:00');
    $this->julia->forceFill(['outcome' => ConversationOutcome::Approved, 'outcome_amount' => '3000.00', 'outcome_at' => now(), 'assigned_user_id' => null])->save();
    $note = ConversationNote::factory()->create(['conversation_id' => $this->beatriz->id]);
    $cycle = ResponseCycle::factory()->create(['conversation_id' => $this->beatriz->id, 'started_at' => now()->subMinutes(5)]);
    $this->beatriz->forceFill(['open_response_cycle_id' => $cycle->id])->save();
    app(ConversationMetricsCalculator::class)->recompute($this->beatriz->id);

    adjustBoundary($this->admin, $this->beatriz, $this->julia, null)
        ->assertOk()
        ->assertJson(['moved_to_previous' => 2, 'merged' => 'into_previous', 'survivor_id' => $this->julia->id]);

    $this->julia->refresh();
    $this->beatriz->refresh();

    expect($this->beatriz)
        ->status->toBe(ConversationStatus::Closed)
        ->merged_into_conversation_id->toBe($this->julia->id)
        ->and($this->beatriz->merged_at)->not->toBeNull()
        ->and($this->beatriz->open_response_cycle_id)->toBeNull()
        // The joined request goes on as the later one was: in attention, to complete again.
        ->and($this->julia->status)->toBe(ConversationStatus::Open)
        ->and($this->julia->assigned_user_id)->toBe($this->agent->id)
        ->and($this->julia->completed_at)->toBeNull()
        ->and($this->julia->closed_at)->toBeNull()
        ->and($this->julia->outcome)->toBe(ConversationOutcome::Approved)
        ->and($this->julia->last_inbound_at->toDateTimeString())->toBe('2026-11-27 11:01:00')
        ->and($head[1]->refresh()->conversation_id)->toBe($this->julia->id)
        ->and($note->refresh()->conversation_id)->toBe($this->julia->id)
        ->and($cycle->refresh()->conversation_id)->toBe($this->julia->id)
        ->and($cycle->isActive())->toBeTrue()
        ->and($this->julia->open_response_cycle_id)->toBe($cycle->id)
        // Nothing deleted: both rows and every message are kept.
        ->and(Message::query()->whereIn('conversation_id', [$this->julia->id, $this->beatriz->id])->count())->toBe(4)
        // Outside the metrics, the inbox and the history; counted in the survivor.
        ->and(ConversationMetric::query()->where('conversation_id', $this->beatriz->id)->exists())->toBeFalse()
        ->and(ConversationMetric::query()->where('conversation_id', $this->julia->id)->value('messages_in'))->toBe(4)
        ->and($this->beatriz->events()->where('type', ConversationEventType::BoundaryAdjusted)->sole()->metadata)->toMatchArray(['merged' => 'absorbed']);

    $listed = collect($this->actingAs($this->admin)->getJson(route('conversations.page', ['view' => 'all', 'status' => 'all', 'limit' => 100]))->assertOk()->json('data'))->pluck('id');

    expect($listed)->toContain($this->julia->id)->not->toContain($this->beatriz->id)
        ->and(Conversation::query()->visibleTo($this->admin)->whereKey($this->beatriz->id)->exists())->toBeFalse();

    // An old link opens the request that holds its messages.
    $this->actingAs($this->admin)->get(route('conversations.show', $this->beatriz))->assertRedirect(route('conversations.show', $this->julia->id));

    $segments = $this->actingAs($this->admin)->getJson(route('conversations.full-history', $this->julia))->assertOk()->json('segments');

    expect(collect($segments)->pluck('conversation.id')->all())->toBe([$this->julia->id]);
});

test('moving every message of the earlier request absorbs it into the later one, which keeps its state', function () {
    $tail = boundaryMessages($this->julia, 2, '2026-11-27 09:00:00');
    boundaryMessages($this->beatriz, 1, '2026-11-27 11:00:00');

    adjustBoundary($this->admin, $this->beatriz, $this->julia, $tail[0]->id)
        ->assertOk()
        ->assertJson(['moved_to_current' => 2, 'merged' => 'into_current', 'survivor_id' => $this->beatriz->id]);

    expect($this->julia->refresh())
        ->merged_into_conversation_id->toBe($this->beatriz->id)
        ->status->toBe(ConversationStatus::Closed)
        ->and($this->beatriz->refresh()->status)->toBe(ConversationStatus::Open)
        ->and($this->beatriz->assigned_user_id)->toBe($this->agent->id)
        // The captured «Cliente» of the absorbed request is kept.
        ->and(collect($this->beatriz->request_fields)->pluck('value', 'key')->all())->toBe(['cliente' => 'JULIA']);
});

test('the dialog lists the messages around the boundary and only managers may use it', function () {
    boundaryMessages($this->julia, 3, '2026-11-27 09:00:00');
    boundaryMessages($this->beatriz, 2, '2026-11-27 11:00:00');

    $this->actingAs($this->admin)->getJson(route('conversations.boundary.show', $this->beatriz))
        ->assertOk()
        ->assertJsonPath('previous.id', $this->julia->id)
        ->assertJsonPath('current.id', $this->beatriz->id)
        ->assertJsonPath('previous.client', 'JULIA')
        ->assertJsonCount(5, 'messages')
        ->assertJsonPath('previous_total', 3)
        ->assertJsonPath('current_more', false);

    $segments = collect($this->actingAs($this->admin)->getJson(route('conversations.full-history', $this->beatriz))->assertOk()->json('segments'))
        ->mapWithKeys(fn (array $segment): array => [$segment['conversation']['id'] => $segment['can_adjust_boundary']]);

    expect($segments->all())->toBe([$this->julia->id => false, $this->beatriz->id => true]);

    // The agent attends the request but does not manage conversations.
    $this->actingAs($this->agent)->getJson(route('conversations.boundary.show', $this->beatriz))->assertForbidden();
    adjustBoundary($this->agent, $this->beatriz, $this->julia, null)->assertForbidden();

    expect(collect($this->actingAs($this->agent)->getJson(route('conversations.full-history', $this->beatriz))->json('segments'))->pluck('can_adjust_boundary')->unique()->all())->toBe([false])
        ->and(Message::query()->where('conversation_id', $this->beatriz->id)->count())->toBe(2);
});

test('only consecutive live requests are adjusted, on the versions the user saw', function () {
    boundaryMessages($this->julia, 1, '2026-11-27 09:00:00');
    boundaryMessages($this->beatriz, 1, '2026-11-27 11:00:00');
    $between = Conversation::factory()->inDepartment($this->department)->closed()->create([
        'contact_id' => $this->julia->contact_id,
        'connection_id' => $this->julia->connection_id,
        'created_at' => CarbonImmutable::parse('2026-11-27 10:30:00', 'UTC'),
    ]);

    adjustBoundary($this->admin, $this->beatriz, $this->julia, null)->assertConflict();

    $between->forceFill(['origin' => ConversationOrigin::Whaticket])->save();

    // The request right before is imported history: read only.
    adjustBoundary($this->admin, $this->beatriz, $between, null)->assertForbidden();

    $between->forceFill(['origin' => ConversationOrigin::Live])->save();

    adjustBoundary($this->admin, $this->beatriz, $between, null, ['previous_version' => $between->version + 5])->assertConflict();
    adjustBoundary($this->admin, $this->beatriz, $between, 999999999)->assertUnprocessable()->assertJsonValidationErrors('starts_at');

    expect(Message::query()->where('conversation_id', $this->beatriz->id)->count())->toBe(1)
        ->and($between->refresh()->merged_at)->toBeNull();
});

<?php

use App\Enums\AuditAction;
use App\Enums\ConversationEventType;
use App\Enums\ConversationOutcome;
use App\Enums\MessageDirection;
use App\Enums\MessageStatus;
use App\Http\Resources\ConversationEventResource;
use App\Http\Resources\ConversationResource;
use App\Models\AuditLog;
use App\Models\Conversation;
use App\Models\Department;
use App\Models\Message;
use App\Models\User;

/*
| Credit result of a completed request: «Solicitud completada» says the
| request was attended; «Crédito aprobado» (with the authorized amount) or
| «Rechazada» say what became of it. Optional when completing, correctable
| afterwards by whoever completed it or by whoever manages it.
*/

beforeEach(function () {
    $this->department = Department::factory()->create();
    $this->agent = User::factory()->agent()->inDepartments($this->department)->create();
    $this->conversation = Conversation::factory()->inDepartment($this->department)->assignedTo($this->agent)->create();
});

function completeRequest(Conversation $conversation, User $actor, array $data = []): void
{
    test()->actingAs($actor)
        ->from(route('conversations.show', $conversation))
        ->post(route('conversations.close', $conversation), ['version' => $conversation->refresh()->version, 'completed' => true, ...$data])
        ->assertRedirect()
        ->assertSessionHasNoErrors();
}

test('completing with an approval records the result, the event and the audit entry', function () {
    completeRequest($this->conversation, $this->agent, ['outcome' => 'approved', 'outcome_amount' => '3000']);

    $conversation = $this->conversation->refresh();
    $event = $conversation->events()->where('type', ConversationEventType::Closed)->sole();

    expect($conversation)
        ->outcome->toBe(ConversationOutcome::Approved)
        ->outcome_amount->toBe('3000.00')
        ->outcome_by_user_id->toBe($this->agent->id)
        ->and($conversation->outcome_at)->not->toBeNull()
        ->and($event->metadata)->toMatchArray(['completed' => true, 'outcome' => 'approved', 'outcome_amount' => '3000.00'])
        ->and((new ConversationEventResource($event))->resolve(request())['outcome'])->toBe('Crédito aprobado por $3,000')
        ->and(AuditLog::query()->where('action', AuditAction::ConversationOutcomeRecorded)->sole()->properties)
        ->toMatchArray(['outcome' => 'approved', 'outcome_amount' => '3000.00', 'on_completion' => true]);
});

test('a rejection never keeps an amount', function () {
    completeRequest($this->conversation, $this->agent, ['outcome' => 'rejected', 'outcome_amount' => '3000']);

    expect($this->conversation->refresh())
        ->outcome->toBe(ConversationOutcome::Rejected)
        ->outcome_amount->toBeNull();
});

test('completing without a result works exactly as before', function () {
    completeRequest($this->conversation, $this->agent);

    $conversation = $this->conversation->refresh();

    expect($conversation->completed_at)->not->toBeNull()
        ->and($conversation->outcome)->toBeNull()
        ->and($conversation->outcome_at)->toBeNull()
        ->and($conversation->events()->where('type', ConversationEventType::Closed)->sole()->metadata)->toBe(['completed' => true])
        ->and(AuditLog::query()->where('action', AuditAction::ConversationOutcomeRecorded)->exists())->toBeFalse();
});

test('a plain close ignores a result and an invalid result is refused', function () {
    $this->actingAs($this->agent)
        ->post(route('conversations.close', $this->conversation), ['version' => 0, 'outcome' => 'approved', 'outcome_amount' => '100'])
        ->assertSessionHasNoErrors();

    expect($this->conversation->refresh()->outcome)->toBeNull();

    $other = Conversation::factory()->inDepartment($this->department)->assignedTo($this->agent)->create();

    $this->actingAs($this->agent)
        ->post(route('conversations.close', $other), ['version' => 0, 'completed' => true, 'outcome' => 'maybe'])
        ->assertSessionHasErrors('outcome');
    $this->actingAs($this->agent)
        ->post(route('conversations.close', $other), ['version' => 0, 'completed' => true, 'outcome' => 'approved', 'outcome_amount' => '$3,000'])
        ->assertSessionHasErrors('outcome_amount');

    expect($other->refresh()->completed_at)->toBeNull();
});

test('who completed it corrects the result afterwards and the timeline says so', function () {
    completeRequest($this->conversation, $this->agent);

    $this->actingAs($this->agent)
        ->put(route('conversations.outcome.update', $this->conversation), ['outcome' => 'approved', 'outcome_amount' => '4500.50'])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $conversation = $this->conversation->refresh();
    $event = $conversation->events()->where('type', ConversationEventType::OutcomeRecorded)->sole();

    expect($conversation)
        ->outcome->toBe(ConversationOutcome::Approved)
        ->outcome_amount->toBe('4500.50')
        ->and($event->metadata)->toMatchArray(['outcome' => 'approved', 'from_outcome' => null])
        ->and((new ConversationEventResource($event))->resolve(request()))
        ->toMatchArray(['action' => 'registró el resultado de la solicitud', 'outcome' => 'Crédito aprobado por $4,500.50']);

    // Back to «Sin resultado».
    $this->actingAs($this->agent)
        ->put(route('conversations.outcome.update', $this->conversation), ['outcome' => null])
        ->assertSessionHasNoErrors();

    expect($conversation->refresh())->outcome->toBeNull()->outcome_amount->toBeNull()
        ->and(AuditLog::query()->where('action', AuditAction::ConversationOutcomeRecorded)->count())->toBe(2);
});

test('a supervisor of the department corrects it; another agent may not', function () {
    completeRequest($this->conversation, $this->agent, ['outcome' => 'rejected']);
    $supervisor = User::factory()->supervisor()->inDepartments($this->department)->create();
    $colleague = User::factory()->agent()->inDepartments($this->department)->create();

    $this->actingAs($colleague)
        ->put(route('conversations.outcome.update', $this->conversation), ['outcome' => 'approved'])
        ->assertNotFound();

    $this->actingAs($supervisor)
        ->put(route('conversations.outcome.update', $this->conversation), ['outcome' => 'approved', 'outcome_amount' => '2000'])
        ->assertSessionHasNoErrors();

    expect($this->conversation->refresh())
        ->outcome->toBe(ConversationOutcome::Approved)
        ->outcome_by_user_id->toBe($supervisor->id);
});

test('only a completed request takes a result, and reopening it clears the result', function () {
    $open = Conversation::factory()->inDepartment($this->department)->assignedTo($this->agent)->create();
    $admin = User::factory()->administrator()->create();

    $this->actingAs($admin)
        ->put(route('conversations.outcome.update', $open), ['outcome' => 'approved'])
        ->assertStatus(302)
        ->assertSessionHasErrors();
    expect($open->refresh()->outcome)->toBeNull();

    completeRequest($this->conversation, $this->agent, ['outcome' => 'approved', 'outcome_amount' => '3000']);
    $this->actingAs($admin)->post(route('conversations.reopen', $this->conversation), ['version' => $this->conversation->refresh()->version])->assertSessionHasNoErrors();

    expect($this->conversation->refresh())
        ->outcome->toBeNull()
        ->outcome_amount->toBeNull()
        ->outcome_at->toBeNull();
});

test('the conversation resource exposes the result and whether the viewer may change it', function () {
    completeRequest($this->conversation, $this->agent, ['outcome' => 'approved', 'outcome_amount' => '3000']);

    $this->actingAs($this->agent);
    $data = (new ConversationResource($this->conversation->refresh()))->resolve(request()->setUserResolver(fn () => $this->agent));

    expect($data['outcome'])->toMatchArray(['value' => 'approved', 'label' => 'Crédito aprobado', 'amount' => '3000.00'])
        ->and($data['can']['record_outcome'])->toBeTrue();
});

test('the suggestion reads the latest authorization written by a person', function () {
    $reply = fn (string $body, array $attributes = []) => Message::factory()->create([
        'conversation_id' => $this->conversation->id,
        'direction' => MessageDirection::Outbound,
        'status' => MessageStatus::Delivered,
        'sender_user_id' => $this->agent->id,
        'body' => $body,
        ...$attributes,
    ]);
    $reply("*Se autoriza reactivación de crédito*\nProducto: S12\nMonto autorizado: \$3,000\nPlazo: 12");
    $reply('¡Gracias por tu preferencia!', ['metadata' => ['automatic' => true]]);
    $reply('Cualquier duda aquí estamos');

    $this->actingAs($this->agent)
        ->getJson(route('conversations.outcome.suggestion', $this->conversation))
        ->assertOk()
        ->assertJsonPath('suggestion.outcome', 'approved')
        ->assertJsonPath('suggestion.amount', '3000.00')
        ->assertJsonPath('suggestion.source', 'message')
        ->assertJsonPath('suggestion.excerpt', 'Se autoriza reactivación de crédito');

    $reply('Solicitud rechazada');

    $this->actingAs($this->agent)
        ->getJson(route('conversations.outcome.suggestion', $this->conversation))
        ->assertJsonPath('suggestion.outcome', 'rejected')
        ->assertJsonPath('suggestion.amount', null);
});

test('the captured authorized amount is suggested without a reply that says so', function () {
    $this->conversation->forceFill(['request_fields' => [
        ['key' => 'monto_autorizado', 'label' => 'Monto autorizado', 'value' => '$5,000'],
    ]])->save();

    $this->actingAs($this->agent)
        ->getJson(route('conversations.outcome.suggestion', $this->conversation))
        ->assertOk()
        ->assertJsonPath('suggestion.outcome', 'approved')
        ->assertJsonPath('suggestion.amount', '5000.00')
        ->assertJsonPath('suggestion.source', 'request_fields');

    $stranger = User::factory()->agent()->create();

    $this->actingAs($stranger)
        ->getJson(route('conversations.outcome.suggestion', $this->conversation))
        ->assertNotFound();
});

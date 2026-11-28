<?php

use App\Enums\AuditAction;
use App\Enums\ConversationEventType;
use App\Enums\ConversationOrigin;
use App\Enums\ConversationOutcome;
use App\Enums\ConversationStatus;
use App\Http\Resources\ConversationEventResource;
use App\Http\Resources\ConversationResource;
use App\Models\AuditLog;
use App\Models\Conversation;
use App\Models\Department;
use App\Models\User;
use Illuminate\Support\Carbon;

/*
| The authorized amount of a completed request stays correctable (a typo),
| from «Resultado del crédito» or from «Resolución»: both are the same
| amount (outcome_amount and «Monto autorizado» in the request data), kept
| in step in one transaction, recorded in the timeline and the audit log.
*/

beforeEach(function () {
    $this->department = Department::factory()->create();
    $this->agent = User::factory()->agent()->inDepartments($this->department)->create();
    $this->conversation = Conversation::factory()->inDepartment($this->department)->assignedTo($this->agent)->create();
});

function completeApproved(Conversation $conversation, User $actor, string $amount = '3000'): Conversation
{
    test()->actingAs($actor)
        ->post(route('conversations.close', $conversation), ['version' => $conversation->refresh()->version, 'completed' => true, 'outcome' => 'approved', 'outcome_amount' => $amount])
        ->assertSessionHasNoErrors();

    return $conversation->refresh();
}

function resolutionValue(Conversation $conversation, string $key): ?string
{
    foreach ($conversation->refresh()->request_fields ?? [] as $field) {
        if ($field['key'] === $key) {
            return $field['value'];
        }
    }

    return null;
}

function correctResolution(Conversation $conversation, User $actor, array $fields, string $mode = 'merge')
{
    return test()->actingAs($actor)->putJson(route('conversations.request-fields.update', $conversation), ['mode' => $mode, 'fields' => $fields]);
}

test('who completed it corrects the amount from the result card and the request data follows', function () {
    Carbon::setTestNow('2026-11-20 10:00:00');
    completeApproved($this->conversation, $this->agent);
    Carbon::setTestNow('2026-11-27 12:00:00');

    $this->actingAs($this->agent)
        ->put(route('conversations.outcome.update', $this->conversation), ['outcome' => 'approved', 'outcome_amount' => '3500'])
        ->assertSessionHasNoErrors();

    $conversation = $this->conversation->refresh();
    $event = $conversation->events()->where('type', ConversationEventType::OutcomeRecorded)->sole();

    expect($conversation->outcome_amount)->toBe('3500.00')
        // A typo does not move the credit to another period of the reports.
        ->and($conversation->outcome_at->toDateString())->toBe('2026-11-20')
        ->and(resolutionValue($conversation, 'monto_autorizado'))->toBe('$3,500')
        ->and($event->metadata)->toMatchArray(['amount_corrected' => true, 'from_outcome_amount' => '3000.00', 'outcome_amount' => '3500.00'])
        ->and((new ConversationEventResource($event))->resolve(request()))
        ->toMatchArray(['action' => 'corrigió el monto autorizado', 'outcome' => '$3,000 → $3,500'])
        ->and(AuditLog::query()->where('action', AuditAction::ConversationOutcomeRecorded)->latest('id')->first()->properties)
        ->toMatchArray(['amount_corrected' => true, 'previous_outcome_amount' => '3000.00', 'outcome_amount' => '3500.00']);

    Carbon::setTestNow();
});

test('who completed it corrects «Monto autorizado» in the resolution and the result follows', function () {
    completeApproved($this->conversation, $this->agent);

    correctResolution($this->conversation, $this->agent, [['label' => 'Monto autorizado', 'value' => '$3,500']])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $conversation = $this->conversation->refresh();
    $event = $conversation->events()->where('type', ConversationEventType::OutcomeRecorded)->sole();

    expect($conversation->outcome)->toBe(ConversationOutcome::Approved)
        ->and($conversation->outcome_amount)->toBe('3500.00')
        ->and($conversation->status)->toBe(ConversationStatus::Closed)
        ->and(resolutionValue($conversation, 'monto_autorizado'))->toBe('$3,500')
        ->and($event->metadata)->toMatchArray(['amount_corrected' => true, 'source' => 'request_fields', 'from_outcome_amount' => '3000.00'])
        ->and(AuditLog::query()->where('action', AuditAction::ConversationRequestFieldsChanged)->latest('id')->first()->properties['changes'][0])
        ->toMatchArray(['label' => 'Monto autorizado', 'previous' => '$3,000', 'value' => '$3,500']);

    // The other amounts of the resolution are correctable too, without touching the result.
    correctResolution($this->conversation, $this->agent, [['label' => 'Desembolso', 'value' => '2130']])->assertSessionHasNoErrors();

    expect(resolutionValue($conversation, 'desembolso'))->toBe('2130')
        ->and($conversation->refresh()->outcome_amount)->toBe('3500.00')
        ->and($conversation->events()->where('type', ConversationEventType::OutcomeRecorded)->count())->toBe(1);
});

test('the analyst who attended it corrects it although a supervisor completed it', function () {
    $supervisor = User::factory()->supervisor()->inDepartments($this->department)->create();
    completeApproved($this->conversation, $supervisor);

    expect($this->conversation->refresh()->assigned_user_id)->toBe($this->agent->id);

    correctResolution($this->conversation, $this->agent, [['label' => 'Monto autorizado', 'value' => '2800']])->assertSessionHasNoErrors();

    expect($this->conversation->refresh()->outcome_amount)->toBe('2800.00');
});

test('a supervisor of the department corrects it from the resolution', function () {
    completeApproved($this->conversation, $this->agent);
    $supervisor = User::factory()->supervisor()->inDepartments($this->department)->create();

    correctResolution($this->conversation, $supervisor, [['label' => 'Monto autorizado', 'value' => '4000']])->assertSessionHasNoErrors();

    $conversation = $this->conversation->refresh();

    expect($conversation->outcome_amount)->toBe('4000.00')
        ->and($conversation->outcome_by_user_id)->toBe($this->agent->id)
        ->and($conversation->events()->where('type', ConversationEventType::OutcomeRecorded)->sole()->actor_user_id)->toBe($supervisor->id);
});

test('an unrelated agent may not correct it', function () {
    completeApproved($this->conversation, $this->agent);
    $colleague = User::factory()->agent()->inDepartments($this->department)->create();

    correctResolution($this->conversation, $colleague, [['label' => 'Monto autorizado', 'value' => '9000']])->assertStatus(404);
    $this->actingAs($colleague)
        ->put(route('conversations.outcome.update', $this->conversation), ['outcome' => 'approved', 'outcome_amount' => '9000'])
        ->assertNotFound();

    expect($this->conversation->refresh()->outcome_amount)->toBe('3000.00')
        ->and(resolutionValue($this->conversation, 'monto_autorizado'))->toBe('$3,000');
});

test('on a completed request only the resolution amounts are correctable by the analyst', function () {
    completeApproved($this->conversation, $this->agent);

    correctResolution($this->conversation, $this->agent, [['label' => 'Cliente', 'value' => 'OTRA PERSONA']])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('fields');

    expect(resolutionValue($this->conversation, 'cliente'))->toBeNull();
});

test('removing «Monto autorizado» leaves the approval without amount', function () {
    completeApproved($this->conversation, $this->agent);

    correctResolution($this->conversation, $this->agent, [], 'replace')->assertSessionHasNoErrors();

    expect($this->conversation->refresh())
        ->outcome->toBe(ConversationOutcome::Approved)
        ->outcome_amount->toBeNull();
});

test('a rejected request keeps its result when the amount is typed', function () {
    test()->actingAs($this->agent)
        ->post(route('conversations.close', $this->conversation), ['version' => $this->conversation->refresh()->version, 'completed' => true, 'outcome' => 'rejected'])
        ->assertSessionHasNoErrors();

    correctResolution($this->conversation, $this->agent, [['label' => 'Monto autorizado', 'value' => '3000']])->assertSessionHasNoErrors();

    expect($this->conversation->refresh())
        ->outcome->toBe(ConversationOutcome::Rejected)
        ->outcome_amount->toBeNull()
        ->and(resolutionValue($this->conversation, 'monto_autorizado'))->toBe('3000');
});

test('imported history is never corrected', function () {
    $admin = User::factory()->administrator()->create();
    $imported = Conversation::factory()->inDepartment($this->department)->closed()->create([
        'origin' => ConversationOrigin::Whaticket,
        'completed_at' => now(),
        'closed_by_user_id' => $admin->id,
        'outcome' => ConversationOutcome::Approved,
        'outcome_amount' => '3000.00',
    ]);

    correctResolution($imported, $admin, [['label' => 'Monto autorizado', 'value' => '5000']])->assertForbidden();
    $this->actingAs($admin)
        ->put(route('conversations.outcome.update', $imported), ['outcome' => 'approved', 'outcome_amount' => '5000'])
        ->assertForbidden();

    expect($imported->refresh()->outcome_amount)->toBe('3000.00')
        ->and((new ConversationResource($imported))->resolve(request()->setUserResolver(fn () => $admin))['can']['correct_resolution'])->toBeFalse();
});

test('the conversation resource says who may correct the resolution', function () {
    completeApproved($this->conversation, $this->agent);
    $colleague = User::factory()->agent()->inDepartments($this->department)->create();

    $for = fn (User $viewer): bool => (new ConversationResource($this->conversation->refresh()))
        ->resolve(request()->setUserResolver(fn () => $viewer))['can']['correct_resolution'];

    expect($for($this->agent))->toBeTrue()
        ->and($for($colleague))->toBeFalse();
});

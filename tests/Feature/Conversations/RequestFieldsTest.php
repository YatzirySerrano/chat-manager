<?php

use App\Enums\AuditAction;
use App\Events\Conversations\ConversationChanged;
use App\Models\AuditLog;
use App\Models\Conversation;
use App\Models\Department;
use App\Models\Message;
use App\Models\User;
use Illuminate\Support\Facades\Event;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;

/*
| «Datos de la solicitud»: the request data an analyst takes from a message
| («Tomar datos de la solicitud») and keeps on the conversation, authorized
| like the request category and audited.
*/

beforeEach(function () {
    $this->department = Department::factory()->create();
    $this->agent = User::factory()->agent()->available()->inDepartments($this->department)->create();
    $this->supervisor = User::factory()->supervisor()->inDepartments($this->department)->create();
    $this->mine = Conversation::factory()->inDepartment($this->department)->assignedTo($this->agent)->create();
    $this->pending = Conversation::factory()->inDepartment($this->department)->create();
    $this->request = Message::factory()->create([
        'conversation_id' => $this->mine->id,
        'body' => "Solicitud de crédito\nNuevo\nCliente: Beatriz Guerrero Olvera\nProducto: s12\nMonto solicitado: \$5,000",
    ]);
});

/**
 * @param  list<array<string, mixed>>  $fields
 */
function saveRequestFields(object $test, User $actor, Conversation $conversation, array $fields, string $mode = 'merge'): TestResponse
{
    return $test->actingAs($actor)->putJson(route('conversations.request-fields.update', $conversation), ['mode' => $mode, 'fields' => $fields]);
}

test('the message is read into suggestions for whoever sees the conversation', function () {
    $this->actingAs($this->agent)
        ->getJson(route('conversations.request-fields.parse', [$this->mine, $this->request]))
        ->assertOk()
        ->assertJsonPath('fields.0', ['label' => 'Tipo', 'key' => 'tipo', 'value' => 'Nuevo', 'known' => true])
        ->assertJsonPath('fields.1.value', 'Beatriz Guerrero Olvera')
        ->assertJsonCount(4, 'fields')
        ->assertJsonPath('labels.0', ['key' => 'cliente', 'label' => 'Cliente']);

    $other = Message::factory()->create();
    $this->actingAs($this->agent)->getJson(route('conversations.request-fields.parse', [$this->mine, $other]))->assertNotFound();

    $colleague = User::factory()->agent()->inDepartments($this->department)->create();
    $this->actingAs($colleague)->getJson(route('conversations.request-fields.parse', [$this->mine, $this->request]))->assertNotFound();
});

test('the responsible agent keeps the fields taken from a message, audited and signalled', function () {
    Event::fake([ConversationChanged::class]);

    saveRequestFields($this, $this->agent, $this->mine, [
        ['label' => 'Cliente', 'value' => '  Beatriz   Guerrero Olvera ', 'message_id' => $this->request->id],
        ['label' => 'producto', 'value' => 'S12', 'message_id' => $this->request->id],
        ['label' => 'Domicilio', 'value' => 'Calle 5'],
        ['label' => 'Ciclo', 'value' => ''],
    ])->assertRedirect();

    $fields = $this->mine->fresh()->request_fields;

    expect($fields)->toHaveCount(3)
        ->and(collect($fields)->map(fn (array $field): array => [$field['key'], $field['label'], $field['value'], $field['message_id']])->all())->toBe([
            ['cliente', 'Cliente', 'Beatriz Guerrero Olvera', $this->request->id],
            ['producto', 'Producto', 'S12', $this->request->id],
            ['domicilio', 'Domicilio', 'Calle 5', null],
        ])
        ->and($fields[0]['captured_at'])->not->toBeNull()
        ->and($this->mine->fresh()->version)->toBe($this->mine->version);

    $audit = AuditLog::query()->where('action', AuditAction::ConversationRequestFieldsChanged)->sole();

    expect($audit->user_id)->toBe($this->agent->id)
        ->and($audit->properties['mode'])->toBe('merge')
        ->and($audit->properties['changes'])->toHaveCount(3);

    Event::assertDispatched(ConversationChanged::class, fn (ConversationChanged $changed): bool => $changed->conversationId === $this->mine->id && $changed->reason === 'request_fields');
});

test('a merge replaces the same field and keeps the rest; a replace edits and removes', function () {
    saveRequestFields($this, $this->agent, $this->mine, [
        ['label' => 'Cliente', 'value' => 'Beatriz'],
        ['label' => 'Producto', 'value' => 'S12'],
    ])->assertRedirect();
    $capturedAt = $this->mine->fresh()->request_fields[1]['captured_at'];

    saveRequestFields($this, $this->agent, $this->mine, [['label' => '*CLIENTE*', 'value' => 'Beatriz Guerrero']])->assertRedirect();

    expect(collect($this->mine->fresh()->request_fields)->pluck('value', 'key')->all())->toBe(['cliente' => 'Beatriz Guerrero', 'producto' => 'S12']);

    $this->travel(5)->minutes();
    saveRequestFields($this, $this->agent, $this->mine, [['label' => 'Producto', 'value' => 'S12']], 'replace')->assertRedirect();

    $fields = $this->mine->fresh()->request_fields;
    expect($fields)->toHaveCount(1)
        ->and($fields[0]['captured_at'])->toBe($capturedAt);

    // Saving the same thing again changes nothing and records nothing.
    $audits = AuditLog::query()->where('action', AuditAction::ConversationRequestFieldsChanged)->count();
    saveRequestFields($this, $this->agent, $this->mine, [['label' => 'Producto', 'value' => 'S12']])->assertRedirect();
    expect(AuditLog::query()->where('action', AuditAction::ConversationRequestFieldsChanged)->count())->toBe($audits);

    // «Quitar todo».
    saveRequestFields($this, $this->agent, $this->mine, [], 'replace')->assertRedirect();
    expect($this->mine->fresh()->request_fields)->toBeNull();
});

test('who may keep request data', function (string $actor, string $conversation, int $status) {
    $user = match ($actor) {
        'agent' => $this->agent,
        'supervisor' => $this->supervisor,
        'colleague' => User::factory()->agent()->inDepartments($this->department)->create(),
        'outsider' => User::factory()->supervisor()->inDepartments(Department::factory()->create())->create(),
    };

    saveRequestFields($this, $user, $this->{$conversation}, [['label' => 'Producto', 'value' => 'S12']])->assertStatus($status);

    expect($this->{$conversation}->fresh()->request_fields[0]['value'] ?? null)->toBe($status === 302 ? 'S12' : null);
})->with([
    'the responsible agent' => ['agent', 'mine', 302],
    'an agent on a pending request they have not taken' => ['agent', 'pending', 403],
    'a colleague who cannot even see it' => ['colleague', 'mine', 404],
    'whoever assigns, on a colleague\'s conversation' => ['supervisor', 'mine', 302],
    'someone of another department' => ['outsider', 'pending', 404],
]);

test('sizes, labels and the source message are validated', function () {
    $tooMany = array_map(fn (int $index): array => ['label' => "Dato {$index}a", 'value' => 'x'], range(1, 21));

    saveRequestFields($this, $this->agent, $this->mine, $tooMany)->assertUnprocessable()->assertJsonValidationErrors('fields');
    saveRequestFields($this, $this->agent, $this->mine, [['label' => str_repeat('a', 41), 'value' => 'x']])->assertUnprocessable()->assertJsonValidationErrors('fields.0.label');
    saveRequestFields($this, $this->agent, $this->mine, [['label' => 'Cliente', 'value' => str_repeat('a', 256)]])->assertUnprocessable()->assertJsonValidationErrors('fields.0.value');
    saveRequestFields($this, $this->agent, $this->mine, [['label' => '✅', 'value' => 'x']])->assertUnprocessable()->assertJsonValidationErrors('fields.0.label');
    saveRequestFields($this, $this->agent, $this->mine, [['label' => 'Cliente', 'value' => 'Ana', 'message_id' => Message::factory()->create()->id]])->assertUnprocessable()->assertJsonValidationErrors('fields');
    $this->actingAs($this->agent)->putJson(route('conversations.request-fields.update', $this->mine), ['fields' => []])->assertUnprocessable()->assertJsonValidationErrors('mode');

    // Twenty stored at most, counting the ones already kept.
    saveRequestFields($this, $this->agent, $this->mine, array_slice($tooMany, 0, 20))->assertRedirect();
    saveRequestFields($this, $this->agent, $this->mine, [['label' => 'Cliente', 'value' => 'Ana']])->assertUnprocessable()->assertJsonValidationErrors('fields');

    expect($this->mine->fresh()->request_fields)->toHaveCount(20);
});

test('the open conversation carries its request data', function () {
    saveRequestFields($this, $this->agent, $this->mine, [['label' => 'Producto', 'value' => 'S12']])->assertRedirect();

    $this->actingAs($this->agent)->get(route('conversations.index', ['c' => $this->mine->id]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('conversation.id', $this->mine->id)
            ->where('conversation.request_fields.0.key', 'producto')
            ->where('conversation.request_fields.0.value', 'S12'));
});

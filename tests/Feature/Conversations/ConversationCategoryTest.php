<?php

use App\Enums\AuditAction;
use App\Enums\ConversationEventType;
use App\Enums\ConversationReadiness;
use App\Enums\DataScope;
use App\Enums\Permission;
use App\Events\Conversations\ConversationChanged;
use App\Models\AuditLog;
use App\Models\Conversation;
use App\Models\ConversationCategory;
use App\Models\Department;
use App\Models\User;
use App\Services\ServiceRules;
use Illuminate\Support\Facades\Event;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;

/*
| The request category of a conversation: set by its responsible agent or
| by whoever assigns conversations or confirms requests, recorded as an
| event, shown on the inbox row and filterable together with the readiness.
*/

beforeEach(function () {
    $this->department = Department::factory()->create();
    $this->agent = User::factory()->agent()->available()->inDepartments($this->department)->create();
    $this->supervisor = User::factory()->supervisor()->inDepartments($this->department)->create();
    $this->billing = ConversationCategory::factory()->create(['name' => 'Facturación']);
    $this->returns = ConversationCategory::factory()->create(['name' => 'Devoluciones']);
    $this->mine = Conversation::factory()->inDepartment($this->department)->assignedTo($this->agent)->create();
    $this->pending = Conversation::factory()->inDepartment($this->department)->create();
});

function setCategory(object $test, User $actor, Conversation $conversation, mixed $categoryId): TestResponse
{
    return $test->actingAs($actor)->putJson(route('conversations.category.update', $conversation), ['category_id' => $categoryId]);
}

/**
 * @param  array<string, mixed>  $query
 * @return list<int>
 */
function categoryFilteredIds(object $test, User $viewer, array $query): array
{
    $rows = $test->actingAs($viewer)->get(route('conversations.index', $query))->viewData('page')['props']['conversations']['data'];

    return collect($rows)->pluck('id')->sort()->values()->all();
}

test('the responsible agent classifies their conversation and the change is recorded and signalled', function () {
    $this->mine->forceFill(['category_id' => $this->returns->id])->save();
    Event::fake([ConversationChanged::class]);

    setCategory($this, $this->agent, $this->mine, $this->billing->id)->assertRedirect();

    $event = $this->mine->events()->where('type', ConversationEventType::CategoryChanged)->sole();

    expect($this->mine->fresh())->category_id->toBe($this->billing->id)->version->toBe(0)
        ->and($event->actor_user_id)->toBe($this->agent->id)
        ->and($event->metadata)->toBe([
            'from_category_id' => $this->returns->id,
            'from_category' => 'Devoluciones',
            'to_category_id' => $this->billing->id,
            'to_category' => 'Facturación',
        ]);

    Event::assertDispatched(ConversationChanged::class, fn (ConversationChanged $changed): bool => $changed->conversationId === $this->mine->id
        && $changed->reason === 'category_changed'
        && $changed->eventId === $event->id);
});

test('the category can be cleared and setting the same one records nothing', function () {
    $this->mine->forceFill(['category_id' => $this->billing->id])->save();

    setCategory($this, $this->agent, $this->mine, $this->billing->id)->assertRedirect();
    expect($this->mine->events()->where('type', ConversationEventType::CategoryChanged)->count())->toBe(0);

    setCategory($this, $this->agent, $this->mine, null)->assertRedirect();

    expect($this->mine->fresh()->category_id)->toBeNull()
        ->and($this->mine->events()->where('type', ConversationEventType::CategoryChanged)->sole()->metadata)->toMatchArray(['from_category' => 'Facturación', 'to_category' => null]);
});

test('who may classify a conversation', function (string $actor, string $conversation, int $status) {
    // Phase-two behavior (FEATURE_PHASE_TWO); rules resolved before follow it too.
    config(['features.phase_two' => true]);
    app(ServiceRules::class)->flush();

    $user = match ($actor) {
        'agent' => $this->agent,
        'supervisor' => $this->supervisor,
        'colleague' => User::factory()->agent()->inDepartments($this->department)->create(),
        'confirmer' => conversationUser([Permission::ViewConversations, Permission::ConfirmConversationReady], DataScope::Departments, [$this->department]),
        'reader' => conversationUser([Permission::ViewConversations, Permission::ViewConversationHistory], DataScope::Departments, [$this->department]),
        'outsider' => User::factory()->supervisor()->inDepartments(Department::factory()->create())->create(),
    };

    setCategory($this, $user, $this->{$conversation}, $this->billing->id)->assertStatus($status);

    expect($this->{$conversation}->fresh()->category_id)->toBe($status === 302 ? $this->billing->id : null);
})->with([
    'the responsible agent' => ['agent', 'mine', 302],
    'an agent on a pending request they have not taken' => ['agent', 'pending', 403],
    'a colleague who cannot even see it' => ['colleague', 'mine', 404],
    'whoever assigns, on a pending request' => ['supervisor', 'pending', 302],
    'whoever assigns, on a colleague\'s conversation' => ['supervisor', 'mine', 302],
    'whoever confirms requests' => ['confirmer', 'pending', 302],
    'a reader without any of the permissions' => ['reader', 'pending', 403],
    'someone of another department' => ['outsider', 'pending', 404],
]);

test('a custom role that classifies through the confirmation permission keeps classifying while phase two is off', function () {
    // Categories are phase one: the phase-two flag hides the confirmation
    // itself, never the classifying it has always allowed.
    $confirmer = conversationUser([Permission::ViewConversations, Permission::ConfirmConversationReady], DataScope::Departments, [$this->department]);

    expect($confirmer->holdsPermission(Permission::ConfirmConversationReady))->toBeFalse();

    setCategory($this, $confirmer, $this->pending, $this->billing->id)->assertStatus(302);

    expect($this->pending->fresh()->category_id)->toBe($this->billing->id);
});

test('only an existing, active category can be chosen', function () {
    $deleted = ConversationCategory::factory()->inactive()->create(['name' => 'Antigua']);

    setCategory($this, $this->agent, $this->mine, $deleted->id)->assertUnprocessable()
        ->assertJsonPath('errors.category_id.0', 'La categoría «Antigua» está eliminada y no puede asignarse.');
    setCategory($this, $this->agent, $this->mine, 999999)->assertUnprocessable()->assertJsonValidationErrors('category_id');
    $this->actingAs($this->agent)->putJson(route('conversations.category.update', $this->mine), [])->assertUnprocessable()->assertJsonValidationErrors('category_id');

    expect($this->mine->fresh()->category_id)->toBeNull();
});

test('the inbox row shows the category to everyone and the page offers the active catalog', function () {
    $this->pending->forceFill(['category_id' => $this->billing->id])->save();
    ConversationCategory::factory()->inactive()->create(['name' => 'Antigua']);

    $this->actingAs($this->agent)->get(route('conversations.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('categories', fn ($categories) => collect($categories)->pluck('name')->all() === ['Devoluciones', 'Facturación'])
            ->where('conversations.data', fn ($rows) => collect($rows)->firstWhere('id', $this->pending->id)['category'] === [
                'id' => $this->billing->id,
                'name' => 'Facturación',
                'color' => $this->billing->color->value,
                'is_active' => true,
            ] && collect($rows)->firstWhere('id', $this->mine->id)['category'] === null));
});

test('the inbox filters by category and by readiness, and its counters follow', function () {
    // Phase-two behavior (FEATURE_PHASE_TWO); rules resolved before follow it too.
    config(['features.phase_two' => true]);
    app(ServiceRules::class)->flush();

    $this->pending->forceFill(['category_id' => $this->billing->id, 'readiness' => ConversationReadiness::NeedsInfo])->save();
    $this->mine->forceFill(['category_id' => $this->billing->id])->save();
    $other = Conversation::factory()->inDepartment($this->department)->create(['category_id' => $this->returns->id]);

    expect(categoryFilteredIds($this, $this->agent, ['category' => $this->billing->id]))->toBe([$this->mine->id, $this->pending->id])
        ->and(categoryFilteredIds($this, $this->agent, ['readiness' => 'needs_info']))->toBe([$this->pending->id])
        ->and(categoryFilteredIds($this, $this->agent, ['readiness' => 'ready']))->toBe([$this->mine->id, $other->id])
        ->and(categoryFilteredIds($this, $this->agent, ['category' => $this->billing->id, 'readiness' => 'ready']))->toBe([$this->mine->id]);

    $this->actingAs($this->agent)->get(route('conversations.index', ['category' => $this->billing->id, 'readiness' => 'needs_info']))
        ->assertInertia(fn (Assert $page) => $page
            ->where('filters.category', $this->billing->id)
            ->where('filters.readiness', 'needs_info')
            ->where('counts.general', 1)
            ->where('counts.pending', 1)
            ->where('counts.attending', 0)
            ->where('counts.mine', 0));

    $this->actingAs($this->agent)->get(route('conversations.index', ['readiness' => 'listo']))->assertSessionHasErrors('readiness');
});

/**
 * Someone who assigns conversations of the department but may not make
 * exceptions to the queue rules.
 */
function categoryAssignerWithoutExceptions(Department $department): User
{
    return conversationUser([Permission::ViewConversations, Permission::AssignConversations, Permission::AcceptConversations, Permission::ReplyConversations], DataScope::Departments, [$department]);
}

test('a category that would let a waiting request be taken sooner needs the exception permission', function (array $departmentRules, array $categoryRules, array $attributes) {
    // Phase-two behavior (FEATURE_PHASE_TWO); rules resolved before follow it too.
    config(['features.phase_two' => true]);
    app(ServiceRules::class)->flush();

    $this->department->forceFill(['service_rules' => $departmentRules])->save();
    $express = ConversationCategory::factory()->withServiceRules($categoryRules)->create(['name' => 'Exprés']);
    $this->pending->forceFill($attributes)->save();
    $assigner = categoryAssignerWithoutExceptions($this->department);

    setCategory($this, $assigner, $this->pending, $express->id)
        ->assertForbidden()
        ->assertJsonPath('message', 'Cambiar a esta categoría adelantaría la solicitud en la cola. Necesitas el permiso para hacer excepciones a las reglas de la cola.');

    expect($this->pending->fresh()->category_id)->toBeNull()
        ->and($this->pending->events()->where('type', ConversationEventType::CategoryChanged)->exists())->toBeFalse()
        ->and(AuditLog::query()->where('action', AuditAction::ConversationCategoryChanged)->exists())->toBeFalse();

    // The rules still hold it: the shortcut did not open the queue.
    $this->actingAs($assigner)->postJson(route('conversations.accept', $this->pending))->assertConflict();

    // With the permission it is allowed, and it leaves a trace.
    setCategory($this, $this->supervisor, $this->pending, $express->id)->assertRedirect();

    expect($this->pending->fresh()->category_id)->toBe($express->id)
        ->and(AuditLog::query()->where('action', AuditAction::ConversationCategoryChanged)->sole())
        ->user_id->toBe($this->supervisor->id)
        ->auditable_id->toBe($this->pending->id)
        ->properties->toBe([
            'previous_category_id' => null,
            'previous_category' => null,
            'new_category_id' => $express->id,
            'new_category' => 'Exprés',
            'queue_rules_relaxed' => true,
            'queue_eligibility_changed' => true,
        ]);
})->with([
    'no confirmation required' => [['intake.confirmation_required' => true], ['intake.confirmation_required' => false], ['readiness' => ConversationReadiness::NeedsInfo, 'queued_at' => null]],
    'a shorter minimum wait' => [['queue.min_wait_minutes' => 60], ['queue.min_wait_minutes' => 0], []],
]);

test('a category change that does not relax the queue rules needs no exception permission and is audited', function () {
    $this->department->forceFill(['service_rules' => ['queue.min_wait_minutes' => 60, 'intake.confirmation_required' => true]])->save();
    $strict = ConversationCategory::factory()->withServiceRules(['queue.min_wait_minutes' => 120])->create();
    $express = ConversationCategory::factory()->withServiceRules(['queue.min_wait_minutes' => 0, 'intake.confirmation_required' => false])->create();
    $assigner = categoryAssignerWithoutExceptions($this->department);

    // Same rules, and stricter ones, on a waiting request.
    setCategory($this, $assigner, $this->pending, $this->billing->id)->assertRedirect();
    setCategory($this, $assigner, $this->pending, $strict->id)->assertRedirect();
    // Laxer rules on a conversation that is not waiting in any queue.
    setCategory($this, $this->agent, $this->mine, $express->id)->assertRedirect();

    expect($this->pending->fresh()->category_id)->toBe($strict->id)
        ->and($this->mine->fresh()->category_id)->toBe($express->id)
        ->and(AuditLog::query()->where('action', AuditAction::ConversationCategoryChanged)->orderBy('id')->get()->map(fn (AuditLog $entry): array => [
            $entry->properties['new_category_id'], $entry->properties['queue_rules_relaxed'], $entry->properties['queue_eligibility_changed'],
        ])->all())->toBe([
            [$this->billing->id, false, false],
            [$strict->id, false, false],
            [$express->id, false, false],
        ]);
});

test('classifying is refused to someone without the permission before their input is validated', function () {
    $reader = conversationUser([Permission::ViewConversations, Permission::ViewConversationHistory], DataScope::Departments, [$this->department]);

    setCategory($this, $reader, $this->pending, 'no-es-un-id')->assertForbidden();
    setCategory($this, $this->supervisor, $this->pending, 'no-es-un-id')->assertUnprocessable();
});

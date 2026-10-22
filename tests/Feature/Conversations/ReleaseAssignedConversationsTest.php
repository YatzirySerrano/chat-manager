<?php

use App\Actions\Conversations\ReleaseAssignedConversations;
use App\Enums\ConversationEventType;
use App\Enums\ConversationStatus;
use App\Enums\DistributionMode;
use App\Models\Conversation;
use App\Models\Department;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/*
| When an agent can no longer attend (deleted, role changed, removed from a
| department) their conversations go back to the queue through the engine:
| state matrix, version, an "unassigned" event with the department, and in
| automatic departments a new available agent once the change commits.
*/

beforeEach(function () {
    $this->department = Department::factory()->create();
    $this->agent = User::factory()->agent()->available()->inDepartments($this->department)->create();
    $this->admin = User::factory()->administrator()->create();
});

test('released conversations return to the queue with version and event', function () {
    $open = Conversation::factory()->inDepartment($this->department)->assignedTo($this->agent)->create();
    $held = Conversation::factory()->inDepartment($this->department)->assignedTo($this->agent)->onHold('Esperando datos')->create();
    $closed = Conversation::factory()->inDepartment($this->department)->assignedTo($this->agent)->closed()->create();

    $released = DB::transaction(fn () => app(ReleaseAssignedConversations::class)->handle($this->agent, null, $this->admin, 'Cambio de rol'));

    expect($released)->toBe(2);

    foreach ([$open, $held] as $conversation) {
        $conversation->refresh();
        $event = $conversation->events()->sole();

        expect($conversation->status)->toBe(ConversationStatus::Pending)
            ->and($conversation->assigned_user_id)->toBeNull()
            ->and($conversation->on_hold_reason)->toBeNull()
            ->and($conversation->version)->toBe(1)
            ->and($event->type)->toBe(ConversationEventType::Unassigned)
            ->and($event->from_user_id)->toBe($this->agent->id)
            ->and($event->from_department_id)->toBe($this->department->id)
            ->and($event->actor_user_id)->toBe($this->admin->id)
            ->and($event->note)->toBe('Cambio de rol')
            ->and($event->metadata)->toBe(['trigger' => 'access_change']);
    }

    expect($closed->refresh()->assigned_user_id)->toBe($this->agent->id);
});

test('only the conversations of the department left are released', function () {
    $other = Department::factory()->create();
    $this->agent->departments()->attach($other->id);
    $kept = Conversation::factory()->inDepartment($other)->assignedTo($this->agent)->create();
    $released = Conversation::factory()->inDepartment($this->department)->assignedTo($this->agent)->create();

    $this->actingAs($this->admin)
        ->delete(route('departments.members.destroy', [$this->department, $this->agent]))
        ->assertSessionHasNoErrors();

    expect($released->refresh()->status)->toBe(ConversationStatus::Pending)
        ->and($kept->refresh()->assigned_user_id)->toBe($this->agent->id);
});

test('in automatic departments another available agent receives the released conversation', function () {
    $this->department->forceFill(['distribution_mode' => DistributionMode::Automatic])->save();
    $replacement = User::factory()->agent()->available()->inDepartments($this->department)->create();
    $conversation = Conversation::factory()->inDepartment($this->department)->assignedTo($this->agent)->create();

    DB::transaction(fn () => app(ReleaseAssignedConversations::class)->handle($this->agent, null, $this->admin, 'Usuario eliminado'));

    $conversation->refresh();

    expect($conversation->assigned_user_id)->toBe($replacement->id)
        ->and($conversation->status)->toBe(ConversationStatus::Open)
        ->and($conversation->events()->orderBy('id')->pluck('type')->all())->toBe([ConversationEventType::Unassigned, ConversationEventType::AutoAssigned]);
});

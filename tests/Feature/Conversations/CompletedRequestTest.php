<?php

use App\Enums\ConversationEventType;
use App\Enums\ConversationStatus;
use App\Enums\InboundMessageOutcome;
use App\Models\Connection;
use App\Models\Conversation;
use App\Models\Department;
use App\Models\User;

/*
| «Solicitud completada»: closing a conversation as a completed credit
| request means the customer's next message never reopens it, even inside
| the connection's reopening window; it is a new request in «Pendientes».
*/

test('closing as completed records it and a plain close does not', function (bool $completed) {
    $department = Department::factory()->create();
    $agent = User::factory()->agent()->inDepartments($department)->create();
    $conversation = Conversation::factory()->inDepartment($department)->assignedTo($agent)->create();

    $this->actingAs($agent)
        ->from(route('conversations.show', $conversation))
        ->post(route('conversations.close', $conversation), ['version' => 0, 'completed' => $completed])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $conversation->refresh();
    $event = $conversation->events()->where('type', ConversationEventType::Closed)->sole();

    expect($conversation->status)->toBe(ConversationStatus::Closed)
        ->and($conversation->completed_at !== null)->toBe($completed)
        ->and(($event->metadata['completed'] ?? false))->toBe($completed);
})->with(['completed' => true, 'plain close' => false]);

test('after a completed request the next message is a new request in the waiting list', function () {
    $connection = Connection::factory()->create(['reopen_window_minutes' => 120]);
    $department = Department::factory()->create();
    $agent = User::factory()->agent()->inDepartments($department)->create();
    $first = receiveWhatsapp($connection);
    $first->conversation->forceFill([
        'department_id' => $department->id,
        'assigned_user_id' => $agent->id,
        'status' => ConversationStatus::Closed,
        'closed_at' => now()->subMinutes(10),
        'completed_at' => now()->subMinutes(10),
    ])->save();

    $receipt = receiveWhatsapp($connection, body: 'Hola, quiero otro crédito');

    expect($receipt->outcome)->toBe(InboundMessageOutcome::Created)
        ->and($receipt->conversation->id)->not->toBe($first->conversation->id)
        ->and($receipt->conversation->status)->toBe(ConversationStatus::Pending)
        ->and($receipt->conversation->assigned_user_id)->toBeNull()
        ->and($first->conversation->refresh())
        ->status->toBe(ConversationStatus::Closed)
        ->reopen_count->toBe(0);
});

test('reopening a completed request by hand clears the mark', function () {
    $department = Department::factory()->create();
    $agent = User::factory()->agent()->inDepartments($department)->create();
    $admin = User::factory()->administrator()->create();
    $conversation = Conversation::factory()->inDepartment($department)->assignedTo($agent)->create();

    $this->actingAs($agent)->post(route('conversations.close', $conversation), ['version' => 0, 'completed' => true])->assertSessionHasNoErrors();
    $this->actingAs($admin)->post(route('conversations.reopen', $conversation), ['version' => 1])->assertSessionHasNoErrors();

    expect($conversation->refresh())
        ->status->not->toBe(ConversationStatus::Closed)
        ->completed_at->toBeNull();
});

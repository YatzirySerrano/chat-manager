<?php

use App\Models\Connection;
use App\Models\Conversation;
use App\Models\Department;
use App\Models\User;

/*
| The reopening toast only mentions the 24 hour window on channels that have
| one: the web chat has no customer service window.
*/

test('reopening a whatsapp conversation reminds that its 24 hour window does not change', function () {
    $department = Department::factory()->create();
    $agent = User::factory()->agent()->available()->inDepartments($department)->create();
    $conversation = Conversation::factory()->inDepartment($department)->assignedTo($agent)->closed()->create();

    $this->actingAs(User::factory()->administrator()->create())
        ->from(route('conversations.show', $conversation))
        ->post(route('conversations.reopen', $conversation), ['version' => 0])
        ->assertSessionHasNoErrors()
        ->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'Conversación reabierta. La ventana de 24 horas de WhatsApp no cambia.']);
});

test('reopening a web chat conversation does not mention a 24 hour window', function () {
    $department = Department::factory()->create();
    $agent = User::factory()->agent()->available()->inDepartments($department)->create();
    $conversation = Conversation::factory()
        ->for(Connection::factory()->webChat(), 'channelConnection')
        ->inDepartment($department)
        ->assignedTo($agent)
        ->closed()
        ->create();

    $this->actingAs(User::factory()->administrator()->create())
        ->from(route('conversations.show', $conversation))
        ->post(route('conversations.reopen', $conversation), ['version' => 0])
        ->assertSessionHasNoErrors()
        ->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'Conversación reabierta.']);

    expect($conversation->refresh()->status->value)->toBe('open');
});

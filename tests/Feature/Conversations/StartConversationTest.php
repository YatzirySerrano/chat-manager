<?php

use App\Enums\ConversationEventType;
use App\Enums\ConversationStatus;
use App\Models\Connection;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Department;
use App\Models\User;

function startChatSetup(): array
{
    $department = Department::factory()->create();
    $connection = Connection::factory()->whatsAppWeb()->create();
    $connection->departments()->attach($department);
    $agent = User::factory()->agent()->available()->inDepartments($department)->create();

    return [$department, $connection, $agent];
}

test('an agent starts a chat with a typed number: the contact is registered and the conversation is theirs', function () {
    [$department, $connection, $agent] = startChatSetup();

    $this->actingAs($agent)
        ->post(route('conversations.start.store'), [
            'connection_id' => $connection->id,
            'department_id' => $department->id,
            'phone' => '55 1234 5678',
            'name' => 'Cliente Nuevo',
        ])
        ->assertSessionHasNoErrors()
        ->assertRedirect();

    $contact = Contact::query()->where('phone_e164', '+525512345678')->sole();
    $conversation = Conversation::query()->where('contact_id', $contact->id)->sole();

    expect($contact->name)->toBe('Cliente Nuevo')
        ->and($conversation->connection_id)->toBe($connection->id)
        ->and($conversation->department_id)->toBe($department->id)
        ->and($conversation->status)->toBe(ConversationStatus::Open)
        ->and($conversation->assigned_user_id)->toBe($agent->id)
        ->and($conversation->events()->where('type', ConversationEventType::Created)->value('actor_user_id'))->toBe($agent->id);
});

test('a typed number that belongs to a contact reuses it', function () {
    [$department, $connection, $agent] = startChatSetup();
    $contact = Contact::factory()->create(['phone_e164' => '+525512345678']);

    $this->actingAs($agent)
        ->post(route('conversations.start.store'), [
            'connection_id' => $connection->id,
            'department_id' => $department->id,
            'phone' => '+52 55 1234 5678',
        ])
        ->assertSessionHasNoErrors();

    expect(Contact::query()->where('phone_e164', '+525512345678')->count())->toBe(1)
        ->and(Conversation::query()->where('contact_id', $contact->id)->count())->toBe(1);
});

test('a contact attended by someone else is refused naming who attends it', function () {
    [$department, $connection, $agent] = startChatSetup();
    $colleague = User::factory()->agent()->inDepartments($department)->create(['name' => 'Daniela']);
    $contact = Contact::factory()->create(['phone_e164' => '+525512345678']);
    Conversation::factory()->inDepartment($department)->assignedTo($colleague)->create([
        'connection_id' => $connection->id,
        'contact_id' => $contact->id,
    ]);

    $this->actingAs($agent)
        ->post(route('conversations.start.store'), [
            'connection_id' => $connection->id,
            'department_id' => $department->id,
            'phone' => '5512345678',
        ])
        ->assertSessionHasErrors(['start' => 'Este contacto ya está siendo atendido por el usuario Daniela.']);

    expect(Conversation::query()->where('contact_id', $contact->id)->count())->toBe(1);
});

test('the agent’s own open conversation is reused instead of creating another', function () {
    [$department, $connection, $agent] = startChatSetup();
    $contact = Contact::factory()->create(['phone_e164' => '+525512345678', 'created_by_user_id' => $agent->id]);
    $open = Conversation::factory()->inDepartment($department)->assignedTo($agent)->create([
        'connection_id' => $connection->id,
        'contact_id' => $contact->id,
    ]);

    $this->actingAs($agent)
        ->post(route('conversations.start.store'), [
            'connection_id' => $connection->id,
            'department_id' => $department->id,
            'contact_id' => $contact->id,
        ])
        ->assertRedirect(route('conversations.show', $open));
});

test('departments and connections outside the agent’s reach are refused', function () {
    [$department, $connection, $agent] = startChatSetup();
    $other = Department::factory()->create();

    $this->actingAs($agent)
        ->post(route('conversations.start.store'), [
            'connection_id' => $connection->id,
            'department_id' => $other->id,
            'phone' => '5512345678',
        ])
        ->assertSessionHasErrors('department_id');

    $foreign = Connection::factory()->whatsAppWeb()->create();
    $foreign->departments()->attach($other);

    $this->actingAs($agent)
        ->post(route('conversations.start.store'), [
            'connection_id' => $foreign->id,
            'department_id' => $department->id,
            'phone' => '5512345678',
        ])
        ->assertSessionHasErrors('connection_id');

    expect(Conversation::query()->count())->toBe(0);
});

test('the dialog lists the agent’s WhatsApp connections, departments and recent contacts', function () {
    [$department, $connection, $agent] = startChatSetup();
    Connection::factory()->webChat()->create();
    $contact = Contact::factory()->create(['phone_e164' => '+525512345678', 'created_by_user_id' => $agent->id]);

    $this->actingAs($agent)
        ->getJson(route('conversations.start.options'))
        ->assertOk()
        ->assertJsonPath('connections.0.id', $connection->id)
        ->assertJsonPath('connections.0.modality', 'qr')
        ->assertJsonCount(1, 'connections')
        ->assertJsonPath('departments.0.id', $department->id)
        ->assertJsonPath('default_department_id', $department->id);

    $this->actingAs($agent)
        ->getJson(route('conversations.start.contacts'))
        ->assertOk()
        ->assertJsonPath('contacts.0.id', $contact->id);

    $this->actingAs($agent)
        ->getJson(route('conversations.start.contacts', ['q' => '5598765432']))
        ->assertOk()
        ->assertJsonPath('typed_phone', '+525598765432');
});

<?php

use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Department;
use App\Models\Message;
use App\Models\User;
use App\Models\WhatsappAccount;
use Illuminate\Database\UniqueConstraintViolationException;

test('administrators can see every conversation', function () {
    $admin = User::factory()->administrator()->create();
    Conversation::factory()->count(2)->create();

    expect(Conversation::query()->visibleTo($admin)->count())->toBe(2);
});

test('supervisors see their departments and their own assignments only', function () {
    [$sales, $support] = Department::factory()->count(2)->create();
    $supervisor = User::factory()->supervisor()->inDepartments($support)->create();
    $departmentConversation = Conversation::factory()->inDepartment($support)->create();
    $assignedElsewhere = Conversation::factory()->inDepartment($sales)->assignedTo($supervisor)->create();
    $foreign = Conversation::factory()->inDepartment($sales)->create();

    expect(Conversation::query()->visibleTo($supervisor)->pluck('id')->all())
        ->toEqualCanonicalizing([$departmentConversation->id, $assignedElsewhere->id])
        ->and($supervisor->can('view', $foreign))->toBeFalse()
        ->and($supervisor->can('view', $departmentConversation))->toBeTrue()
        ->and($supervisor->can('assign', $departmentConversation))->toBeTrue();
});

test('agents only see the conversations assigned to them', function () {
    $department = Department::factory()->create();
    $agent = User::factory()->agent()->inDepartments($department)->create();
    $assigned = Conversation::factory()->inDepartment($department)->assignedTo($agent)->create();
    $queued = Conversation::factory()->inDepartment($department)->create();

    expect(Conversation::query()->visibleTo($agent)->pluck('id')->all())->toBe([$assigned->id])
        ->and($agent->can('view', $queued))->toBeFalse()
        ->and($agent->can('reply', $assigned))->toBeTrue()
        ->and($agent->can('assign', $assigned))->toBeFalse()
        ->and($agent->can('transfer', $assigned))->toBeFalse();
});

test('deactivated users see no conversations', function () {
    $agent = User::factory()->agent()->inactive()->create();
    Conversation::factory()->assignedTo($agent)->create();

    expect(Conversation::query()->visibleTo($agent)->count())->toBe(0);
});

test('a contact can only have one unclosed conversation per WhatsApp number', function () {
    $account = WhatsappAccount::factory()->create();
    $contact = Contact::factory()->create();
    Conversation::factory()->for($account)->for($contact)->create();

    expect(fn () => Conversation::factory()->for($account)->for($contact)->create())
        ->toThrow(UniqueConstraintViolationException::class);
});

test('a new conversation can start once the previous one is closed', function () {
    $account = WhatsappAccount::factory()->create();
    $contact = Contact::factory()->create();
    Conversation::factory()->for($account)->for($contact)->closed()->create();
    Conversation::factory()->for($account)->for($contact)->closed()->create();

    $open = Conversation::factory()->for($account)->for($contact)->create();

    expect($open->exists)->toBeTrue();
});

test('the same WhatsApp message cannot be stored twice', function () {
    $message = Message::factory()->create();

    expect(fn () => Message::factory()->create(['whatsapp_message_id' => $message->whatsapp_message_id]))
        ->toThrow(UniqueConstraintViolationException::class);
});

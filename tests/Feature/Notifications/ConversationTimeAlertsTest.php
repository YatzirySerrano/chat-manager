<?php

use App\Enums\DataScope;
use App\Enums\Permission;
use App\Models\Conversation;
use App\Models\Department;
use App\Notifications\NotificationKind;
use Illuminate\Support\Facades\Artisan;

/*
| «Alertas de tiempo»: a ready request waiting in «Pendientes» more than 5
| minutes alerts who can take it; a conversation in attention more than 15
| minutes alerts its agent. Once per wait or attention.
*/

test('a ready request waiting too long alerts who can take it, once', function () {
    $department = Department::factory()->create();
    $agent = conversationUser([Permission::ViewConversations, Permission::ReplyConversations, Permission::AcceptConversations], DataScope::Departments, [$department]);
    $conversation = Conversation::factory()->inDepartment($department)->create(['queued_at' => now()->subMinutes(7)]);

    Artisan::call('conversations:time-alerts');
    Artisan::call('conversations:time-alerts');

    expect($agent->notifications()->where('kind', NotificationKind::ConversationQueueOverdue->value)->count())->toBe(1)
        ->and($agent->notifications()->first()->data['conversation_id'])->toBe($conversation->id);
});

test('a conversation in attention too long alerts its agent', function () {
    $department = Department::factory()->create();
    $agent = conversationUser([Permission::ViewConversations, Permission::ReplyConversations], DataScope::Departments, [$department]);
    Conversation::factory()->inDepartment($department)->assignedTo($agent)->create(['assigned_at' => now()->subMinutes(20)]);
    Conversation::factory()->inDepartment($department)->assignedTo($agent)->create(['assigned_at' => now()->subMinutes(3)]);

    Artisan::call('conversations:time-alerts');

    expect($agent->notifications()->where('kind', NotificationKind::ConversationAttentionOverdue->value)->count())->toBe(1);
});

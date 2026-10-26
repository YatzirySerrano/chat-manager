<?php

use App\Actions\Conversations\AcceptConversation;
use App\Actions\Conversations\AssignConversation;
use App\Actions\Conversations\TransferConversation;
use App\Enums\DataScope;
use App\Enums\Permission;
use App\Models\Connection;
use App\Models\Department;
use App\Models\Role;
use App\Models\User;
use App\Notifications\NotificationKind;
use Illuminate\Notifications\DatabaseNotification;

/*
| Seeing a conversation is not being notified about it (Fase 5.1). The
| inbox follows the read reach (supervisors see every conversation of it);
| notifications, and with them the sound, toast, push and system alerts of
| the client (useNotificationCenter: notificationAlertPlan()), follow the
| notification rules only:
| - a customer message: the agent responsible for the conversation;
| - a pending conversation: whoever may accept it now;
| - an assignment or transfer to a person: that person.
| A supervisor who may read every conversation receives nothing about the
| conversations other agents attend.
*/

beforeEach(function () {
    $this->sales = Department::factory()->create();
    $this->connection = Connection::factory()->create(['default_department_id' => $this->sales->id]);
    $this->agent = User::factory()->agent()->inDepartments($this->sales)->create();
    $this->admin = User::factory()->administrator()->create();
    $this->supervisor = User::factory()->supervisor()->inDepartments($this->sales)->create();

    $role = Role::factory()->scope(DataScope::Departments)->withPermissions([
        Permission::ViewConversations,
        Permission::SuperviseConversations,
    ])->create();
    $this->readOnly = User::factory()->withRole($role)->inDepartments($this->sales)->create();
});

/**
 * Notifications of the kind the user received.
 */
function supervisionNotificationCount(User $user, NotificationKind $kind): int
{
    return DatabaseNotification::query()
        ->where('notifiable_type', $user->getMorphClass())
        ->where('notifiable_id', $user->id)
        ->where('kind', $kind->value)
        ->count();
}

test('customer messages of a colleague\'s conversation never notify the supervisors who can read it', function () {
    $conversation = receiveWhatsapp($this->connection, '5215512345678', 'Hola')->conversation;
    app(AcceptConversation::class)->handle($conversation, $this->agent);

    receiveWhatsapp($this->connection, '5215512345678', '¿Siguen ahí?');
    receiveWhatsapp($this->connection, '5215512345678', 'Necesito ayuda');

    foreach ([$this->admin, $this->supervisor, $this->readOnly] as $supervisor) {
        expect($supervisor->can('view', $conversation->fresh()))->toBeTrue()
            ->and(supervisionNotificationCount($supervisor, NotificationKind::ConversationMessage))->toBe(0);
    }

    // The responsible agent gets one aggregated notification.
    expect(supervisionNotificationCount($this->agent, NotificationKind::ConversationMessage))->toBe(1);
});

test('a new pending conversation notifies whoever may accept it, never a read-only supervisor', function () {
    receiveWhatsapp($this->connection, '5215512345678', 'Hola');

    expect(supervisionNotificationCount($this->agent, NotificationKind::ConversationPending))->toBe(1)
        ->and(supervisionNotificationCount($this->supervisor, NotificationKind::ConversationPending))->toBe(1)
        ->and(supervisionNotificationCount($this->admin, NotificationKind::ConversationPending))->toBe(1)
        ->and(supervisionNotificationCount($this->readOnly, NotificationKind::ConversationPending))->toBe(0);
});

test('assigning or transferring to an agent notifies that agent, not the supervisor who did it nor the others watching', function () {
    $conversation = receiveWhatsapp($this->connection, '5215512345678', 'Hola')->conversation;
    $colleague = User::factory()->agent()->inDepartments($this->sales)->create();

    app(AssignConversation::class)->handle($conversation, $this->agent, $this->admin);
    app(TransferConversation::class)->handle($conversation->fresh(), $this->sales, $colleague, $this->admin, 'Turno', $conversation->fresh()->version);

    expect(supervisionNotificationCount($this->agent, NotificationKind::ConversationAssigned))->toBe(1)
        ->and(supervisionNotificationCount($colleague, NotificationKind::ConversationTransferred))->toBe(1);

    foreach ([$this->admin, $this->supervisor, $this->readOnly] as $supervisor) {
        expect(supervisionNotificationCount($supervisor, NotificationKind::ConversationAssigned))->toBe(0)
            ->and(supervisionNotificationCount($supervisor, NotificationKind::ConversationTransferred))->toBe(0);
    }
});

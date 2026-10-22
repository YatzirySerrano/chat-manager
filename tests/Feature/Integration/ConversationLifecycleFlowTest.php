<?php

use App\Actions\Conversations\CloseConversation;
use App\Actions\Conversations\TransferConversation;
use App\Actions\Roles\SaveRole;
use App\Actions\Users\DeleteUser;
use App\Enums\ConversationStatus;
use App\Enums\DataScope;
use App\Enums\DistributionMode;
use App\Enums\Permission;
use App\Events\Broadcasts\RealtimeSignal;
use App\Models\Connection;
use App\Models\Conversation;
use App\Models\ConversationMetric;
use App\Models\Department;
use App\Models\Message;
use App\Models\Role;
use App\Models\User;
use App\Notifications\NotificationKind;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Tests\Support\WhatsAppFixtures;

/*
| Cross-module flow of an attended conversation, every queue inline
| (SIMULATED WhatsApp transport double, never Meta): a transfer between
| departments re-distributes it, notifies the receiving agent, signals both
| departments and the previous agent and counts in the metrics; closing it
| sends the farewell; deleting the responsible account releases what it
| still attended and tells their sessions.
*/

beforeEach(function () {
    Http::preventStrayRequests();
    config(['broadcasting.default' => 'log']);
    WhatsAppFixtures::transport();

    $this->root = User::factory()->superAdministrator()->create();
    $this->sales = Department::factory()->distribution(DistributionMode::Automatic)->create();
    $this->support = Department::factory()->distribution(DistributionMode::Automatic)->create();
    $this->seller = User::factory()->agent()->available()->inDepartments($this->sales)->create();
    $this->technician = User::factory()->agent()->available()->inDepartments($this->support)->create();
    $this->connection = Connection::factory()->operational()->create([
        'default_department_id' => $this->sales->id,
        'farewell_enabled' => true,
        'farewell_message' => 'Gracias por contactarnos.',
    ]);

    $this->conversation = receiveWhatsapp($this->connection)->conversation->refresh();
});

test('a transfer re-distributes, notifies, reaches every audience and is counted', function () {
    expect($this->conversation->assigned_user_id)->toBe($this->seller->id);
    Event::fake([RealtimeSignal::class]);

    app(TransferConversation::class)->handle($this->conversation, $this->support, null, $this->root, 'Requiere soporte técnico');

    $conversation = $this->conversation->refresh();
    expect($conversation)
        ->department_id->toBe($this->support->id)
        ->assigned_user_id->toBe($this->technician->id)
        ->and($this->technician->notifications()->pluck('kind')->all())->toContain(NotificationKind::ConversationAssigned->value);

    $channels = collect(Event::dispatched(RealtimeSignal::class))
        ->map(fn (array $arguments): RealtimeSignal => $arguments[0])
        ->filter(fn (RealtimeSignal $signal): bool => $signal->event === 'inbox.changed')
        ->flatMap(fn (RealtimeSignal $signal): array => $signal->channels)
        ->unique()
        ->all();

    expect($channels)
        ->toContain("users.{$this->seller->id}")
        ->toContain("users.{$this->technician->id}")
        ->toContain("inbox.departments.{$this->sales->id}")
        ->toContain("inbox.departments.{$this->support->id}");

    expect(ConversationMetric::query()->where('conversation_id', $conversation->id)->sole())
        ->transfers_count->toBe(1)
        ->department_id->toBe($this->support->id)
        ->agent_id->toBe($this->technician->id);
});

test('closing sends the farewell once and resolves the conversation in the metrics', function () {
    app(CloseConversation::class)->handle($this->conversation, $this->seller);

    $farewell = Message::query()->where('metadata->automatic_kind', 'farewell')->sole();
    expect($farewell)
        ->conversation_id->toBe($this->conversation->id)
        ->body->toBe('Gracias por contactarnos.')
        ->sender_user_id->toBeNull();

    $metric = ConversationMetric::query()->where('conversation_id', $this->conversation->id)->sole();
    expect($metric)
        ->status->toBe(ConversationStatus::Closed)
        ->closed_count->toBe(1)
        ->resolved_at->not->toBeNull()
        ->first_human_response_at->toBeNull();
});

test('deleting the responsible account releases its conversation to another agent and revalidates its sessions', function () {
    $colleague = User::factory()->agent()->available()->inDepartments($this->sales)->create();
    Event::fake([RealtimeSignal::class]);

    app(DeleteUser::class)->handle($this->seller, $this->root);

    expect($this->conversation->refresh()->assigned_user_id)->toBe($colleague->id)
        ->and($this->seller->notifications()->pluck('kind')->all())->not->toContain(NotificationKind::AccessChanged->value)
        ->and($colleague->notifications()->pluck('kind')->all())->toContain(NotificationKind::ConversationAssigned->value);

    Event::assertDispatched(RealtimeSignal::class, fn (RealtimeSignal $signal): bool => $signal->event === 'access.changed'
        && $signal->channels === ["users.{$this->seller->id}"]);
    Event::assertDispatched(RealtimeSignal::class, fn (RealtimeSignal $signal): bool => $signal->event === 'inbox.changed'
        && in_array("users.{$colleague->id}", $signal->channels, true));
});

test('a role change that removes replying releases the conversation, notifies and revalidates the agent', function () {
    $colleague = User::factory()->agent()->available()->inDepartments($this->sales)->create();
    $readOnly = Role::factory()->scope(DataScope::Departments)->withPermissions([Permission::ViewConversations])->create(['label' => 'Consulta']);
    Event::fake([RealtimeSignal::class]);

    $impact = $this->actingAs($this->root)
        ->postJson(route('admin.users.role-impact', $this->seller), ['role_id' => $readOnly->id])
        ->assertOk()
        ->json();

    $this->put(route('admin.users.update', $this->seller), [
        'name' => $this->seller->name,
        'email' => $this->seller->email,
        'role_id' => $readOnly->id,
        'department_ids' => [$this->sales->id],
        'role_change_fingerprint' => $impact['fingerprint'],
    ])->assertSessionHasNoErrors();

    expect($this->conversation->refresh()->assigned_user_id)->toBe($colleague->id)
        ->and($this->seller->notifications()->pluck('kind')->all())->toContain(NotificationKind::AccessChanged->value);
    Event::assertDispatched(RealtimeSignal::class, fn (RealtimeSignal $signal): bool => $signal->event === 'access.changed'
        && $signal->channels === ["users.{$this->seller->id}"]);
});

test('revoking reply from a role releases what its holders attend, after the role is saved', function () {
    $role = Role::factory()->scope(DataScope::Departments)
        ->withPermissions([Permission::ViewConversations, Permission::ReplyConversations, Permission::AcceptConversations])
        ->create();
    $holder = User::factory()->withRole($role)->available()->inDepartments($this->sales)->create();
    $this->seller->departments()->detach();
    $colleague = User::factory()->agent()->available()->inDepartments($this->sales)->create();
    $attended = Conversation::factory()->inDepartment($this->sales)->assignedTo($holder)->create(['connection_id' => $this->connection->id]);
    $untouched = $this->conversation->refresh();

    app(SaveRole::class)->update($role, [
        'label' => $role->label,
        'description' => $role->description,
        'scope' => DataScope::Departments,
        'permissions' => [Permission::ViewConversations->value, Permission::AcceptConversations->value],
    ], $this->root);

    expect($attended->refresh()->assigned_user_id)->toBe($colleague->id)
        ->and($untouched->refresh()->assigned_user_id)->toBe($this->seller->id)
        ->and($holder->notifications()->pluck('kind')->all())->toContain(NotificationKind::AccessChanged->value);
});

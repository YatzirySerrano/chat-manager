<?php

use App\Actions\Conversations\AssignAutomatically;
use App\Actions\Settings\UpdatePlatformSettings;
use App\Enums\AuditAction;
use App\Enums\ConversationEventType;
use App\Enums\ConversationStatus;
use App\Enums\DistributionMode;
use App\Models\AuditLog;
use App\Models\Conversation;
use App\Models\Department;
use App\Models\User;
use App\Services\PlatformSettings;
use Illuminate\Support\Facades\DB;

/*
| Capacity: the maximum of conversations in attention of an agent is their
| own limit, else the department's per agent limit, else the platform's;
| the load counts every conversation they attend. Automatic distribution and
| self-service never go past it; a manual hand-over past it is an exception
| of users who can assign, confirmed and recorded. Freed capacity offers the
| waiting queue again; customers' conversations are never rejected.
*/

beforeEach(function () {
    $this->department = Department::factory()->distribution(DistributionMode::Automatic)->create(['name' => 'Soporte']);
    $this->admin = User::factory()->administrator()->create();
    $this->agent = User::factory()->agent()->available()->inDepartments($this->department)->create(['name' => 'Ana']);
});

function attending(User $agent, Department $department, int $count = 1): void
{
    Conversation::factory()->count($count)->inDepartment($department)->assignedTo($agent)->create();
}

function distribute(Conversation $conversation): ?User
{
    return DB::transaction(fn () => app(AssignAutomatically::class)->handle(Conversation::query()->lockForUpdate()->findOrFail($conversation->id)));
}

test('the agent\'s own limit takes precedence over the department\'s, which takes precedence over the platform\'s', function (?int $own, ?int $department, int $attending, bool $receives) {
    app(PlatformSettings::class)->update(['operations' => ['max_open_conversations_per_agent' => 2]]);
    $this->department->forceFill(['max_open_conversations_per_agent' => $department])->save();
    $this->agent->forceFill(['max_open_conversations' => $own])->save();
    attending($this->agent, $this->department, $attending);

    $receiver = distribute(Conversation::factory()->inDepartment($this->department)->create());

    expect($receiver?->id)->toBe($receives ? $this->agent->id : null);
})->with([
    'platform limit reached' => [null, null, 2, false],
    'department limit above the platform one' => [null, 3, 2, true],
    'department limit below the platform one' => [null, 1, 1, false],
    'own limit above the department one' => [4, 1, 3, true],
    'own limit below the platform one' => [1, null, 1, false],
]);

test('the load counts conversations of every department and the limit of the receiving one applies', function () {
    $sales = Department::factory()->create();
    $this->agent->departments()->attach($sales->id);
    $this->department->forceFill(['max_open_conversations_per_agent' => 2])->save();
    attending($this->agent, $sales, 2);

    expect(distribute(Conversation::factory()->inDepartment($this->department)->create()))->toBeNull();
});

test('accepting from the queue respects the agent\'s own limit', function () {
    $this->department->forceFill(['distribution_mode' => DistributionMode::Shared])->save();
    $this->agent->forceFill(['max_open_conversations' => 1])->save();
    attending($this->agent, $this->department);
    $conversation = Conversation::factory()->inDepartment($this->department)->create();

    $this->actingAs($this->agent)
        ->from(route('conversations.index'))
        ->post(route('conversations.accept', $conversation))
        ->assertSessionHasErrors(['conversation' => 'Ya atiendes el máximo de 1 conversaciones permitido. Cierra o libera alguna antes de aceptar otra.']);
});

test('nobody with room leaves the conversation pending in the queue, never rejected', function () {
    $this->agent->forceFill(['max_open_conversations' => 1])->save();
    attending($this->agent, $this->department);
    $conversation = Conversation::factory()->inDepartment($this->department)->create();

    expect(distribute($conversation))->toBeNull()
        ->and($conversation->refresh())
        ->status->toBe(ConversationStatus::Pending)
        ->department_id->toBe($this->department->id);
});

test('a colleague who cannot assign may not transfer to someone at their limit, only to the queue', function () {
    $this->department->forceFill(['distribution_mode' => DistributionMode::Shared])->save();
    $this->agent->forceFill(['max_open_conversations' => 1])->save();
    attending($this->agent, $this->department);
    $colleague = User::factory()->agent()->available()->inDepartments($this->department)->create();
    $conversation = Conversation::factory()->inDepartment($this->department)->assignedTo($colleague)->create();

    $this->actingAs($colleague)
        ->from(route('conversations.show', $conversation))
        ->post(route('conversations.transfer', $conversation), ['user_id' => $this->agent->id, 'confirm_over_capacity' => true, 'version' => 0])
        ->assertSessionHasErrors(['user_id' => 'Ana ya atiende el máximo de 1 conversaciones. Envíala a «Pendientes» del departamento o pide a quien asigna conversaciones que lo autorice.']);

    expect($conversation->refresh()->assigned_user_id)->toBe($colleague->id);

    $other = Department::factory()->create();

    $this->actingAs($colleague)
        ->post(route('conversations.transfer', $conversation), ['department_id' => $other->id, 'version' => 0])
        ->assertSessionHasNoErrors();

    expect($conversation->refresh())->status->toBe(ConversationStatus::Pending)->department_id->toBe($other->id);
});

test('a user who can assign transfers past the limit only after confirming, and the exception is recorded', function () {
    $this->agent->forceFill(['max_open_conversations' => 1])->save();
    attending($this->agent, $this->department);
    $conversation = Conversation::factory()->inDepartment($this->department)->create();

    $this->actingAs($this->admin)
        ->from(route('conversations.show', $conversation))
        ->post(route('conversations.transfer', $conversation), ['user_id' => $this->agent->id, 'version' => 0])
        ->assertSessionHasErrors(['user_id' => 'Ana ya atiende el máximo de 1 conversaciones. Confirma que quieres asignársela de todas formas.']);

    $this->actingAs($this->admin)
        ->post(route('conversations.transfer', $conversation), ['user_id' => $this->agent->id, 'confirm_over_capacity' => true, 'version' => 0])
        ->assertSessionHasNoErrors();

    expect($conversation->refresh()->assigned_user_id)->toBe($this->agent->id)
        ->and($conversation->events()->sole())
        ->type->toBe(ConversationEventType::Transferred)
        ->metadata->toBe(['assigned_over_limit' => true, 'limit' => 1]);
});

test('closing a conversation offers the oldest waiting one to the agent who now has room', function () {
    $this->agent->forceFill(['max_open_conversations' => 1])->save();
    $attended = Conversation::factory()->inDepartment($this->department)->assignedTo($this->agent)->create();
    $oldest = Conversation::factory()->inDepartment($this->department)->create(['created_at' => now()->subHour()]);
    $newest = Conversation::factory()->inDepartment($this->department)->create();

    $this->actingAs($this->agent)
        ->post(route('conversations.close', $attended), ['version' => 0])
        ->assertSessionHasNoErrors();

    expect($oldest->refresh())
        ->assigned_user_id->toBe($this->agent->id)
        ->and($oldest->events()->sole()->type)->toBe(ConversationEventType::AutoAssigned)
        ->and($newest->refresh()->status)->toBe(ConversationStatus::Pending);
});

test('freed capacity is not distributed from a department that does not distribute automatically', function (Closure $arrange) {
    $this->agent->forceFill(['max_open_conversations' => 1])->save();
    $attended = Conversation::factory()->inDepartment($this->department)->assignedTo($this->agent)->create();
    $waiting = Conversation::factory()->inDepartment($this->department)->create();
    $arrange($this);

    $this->actingAs($this->agent)
        ->post(route('conversations.close', $attended), ['version' => 0])
        ->assertSessionHasNoErrors();

    expect($waiting->refresh()->assigned_user_id)->toBeNull();
})->with([
    'shared department' => [fn ($test) => $test->department->forceFill(['distribution_mode' => DistributionMode::Shared])->save()],
]);

test('handing a conversation to someone else frees room for its previous responsible', function () {
    $this->agent->forceFill(['max_open_conversations' => 1])->save();
    $colleague = User::factory()->agent()->inDepartments($this->department)->create();
    $attended = Conversation::factory()->inDepartment($this->department)->assignedTo($this->agent)->create();
    $waiting = Conversation::factory()->inDepartment($this->department)->create();

    $this->actingAs($this->admin)
        ->post(route('conversations.assign', $attended), ['user_id' => $colleague->id, 'version' => 0])
        ->assertSessionHasNoErrors();

    expect($waiting->refresh()->assigned_user_id)->toBe($this->agent->id);
});

test('releasing a conversation offers its previous responsible the other waiting one, not the released one', function () {
    $this->agent->forceFill(['max_open_conversations' => 1])->save();
    $released = Conversation::factory()->inDepartment($this->department)->assignedTo($this->agent)->create();
    $waiting = Conversation::factory()->inDepartment($this->department)->create(['created_at' => now()->addMinute()]);

    $this->actingAs($this->agent)
        ->post(route('conversations.release', $released), ['version' => 0])
        ->assertSessionHasNoErrors();

    expect($waiting->refresh())
        ->assigned_user_id->toBe($this->agent->id)
        ->status->toBe(ConversationStatus::Open)
        ->and($released->refresh())
        ->status->toBe(ConversationStatus::Pending)
        ->assigned_user_id->toBeNull();
});

test('raising the platform limit distributes the waiting queue, lowering it moves nothing', function () {
    app(PlatformSettings::class)->update(['operations' => ['max_open_conversations_per_agent' => 1]]);
    $attended = Conversation::factory()->inDepartment($this->department)->assignedTo($this->agent)->create();
    $waiting = Conversation::factory()->inDepartment($this->department)->create();

    app(UpdatePlatformSettings::class)->handle(['operations' => ['max_open_conversations_per_agent' => 2]], $this->admin);

    expect($waiting->refresh()->assigned_user_id)->toBe($this->agent->id);

    app(UpdatePlatformSettings::class)->handle(['operations' => ['max_open_conversations_per_agent' => 1]], $this->admin);

    expect($attended->refresh()->assigned_user_id)->toBe($this->agent->id)
        ->and($waiting->refresh()->assigned_user_id)->toBe($this->agent->id);
});

test('an administrator sets an agent\'s own limit, audited, and raising it distributes the queue', function () {
    $this->agent->forceFill(['max_open_conversations' => 1])->save();
    attending($this->agent, $this->department);
    $waiting = Conversation::factory()->inDepartment($this->department)->create();

    $this->actingAs($this->admin)
        ->put(route('admin.users.update', $this->agent), [
            'name' => $this->agent->name,
            'email' => $this->agent->email,
            'max_open_conversations' => 3,
        ])
        ->assertSessionHasNoErrors();

    expect($this->agent->refresh()->max_open_conversations)->toBe(3)
        ->and($waiting->refresh()->assigned_user_id)->toBe($this->agent->id)
        ->and(AuditLog::query()->where('action', AuditAction::UserUpdated)->where('auditable_id', $this->agent->id)->latest('id')->first()->properties['changes'])
        ->toBe(['max_open_conversations' => ['old' => 1, 'new' => 3]]);

    $this->actingAs($this->admin)
        ->put(route('admin.users.update', $this->agent), ['name' => $this->agent->name, 'email' => $this->agent->email, 'max_open_conversations' => null])
        ->assertSessionHasNoErrors();

    expect($this->agent->refresh()->max_open_conversations)->toBeNull();
});

test('the agent\'s own limit is validated and only editable by whoever may update the account', function () {
    $this->actingAs($this->admin)
        ->put(route('admin.users.update', $this->agent), ['name' => $this->agent->name, 'email' => $this->agent->email, 'max_open_conversations' => 0])
        ->assertSessionHasErrors('max_open_conversations');

    $this->actingAs(User::factory()->agent()->inDepartments($this->department)->create())
        ->put(route('admin.users.update', $this->agent), ['name' => $this->agent->name, 'email' => $this->agent->email, 'max_open_conversations' => 9])
        ->assertForbidden();

    expect($this->agent->refresh()->max_open_conversations)->toBeNull();
});

test('a department sets its own per agent limit with its distribution, and raising it distributes the queue', function () {
    $this->department->forceFill(['max_open_conversations_per_agent' => 1])->save();
    attending($this->agent, $this->department);
    $waiting = Conversation::factory()->inDepartment($this->department)->create();

    $this->actingAs($this->admin)
        ->put(route('departments.distribution.update', $this->department), ['distribution_mode' => 'automatic', 'max_open_conversations_per_agent' => 2])
        ->assertSessionHasNoErrors();

    expect($this->department->refresh()->max_open_conversations_per_agent)->toBe(2)
        ->and($waiting->refresh()->assigned_user_id)->toBe($this->agent->id);

    $this->actingAs($this->admin)
        ->put(route('departments.distribution.update', $this->department), ['distribution_mode' => 'automatic', 'max_open_conversations_per_agent' => 500])
        ->assertSessionHasErrors('max_open_conversations_per_agent');
});

test('setting an own limit above the inherited one distributes the queue', function (Closure $raise) {
    app(PlatformSettings::class)->update(['operations' => ['max_open_conversations_per_agent' => 1]]);
    attending($this->agent, $this->department);
    $waiting = Conversation::factory()->inDepartment($this->department)->create();

    $raise($this);

    expect($waiting->refresh()->assigned_user_id)->toBe($this->agent->id);
})->with([
    'agent' => [fn ($test) => $test->actingAs($test->admin)
        ->put(route('admin.users.update', $test->agent), ['name' => $test->agent->name, 'email' => $test->agent->email, 'max_open_conversations' => 3])
        ->assertSessionHasNoErrors()],
    'department' => [fn ($test) => $test->actingAs($test->admin)
        ->put(route('departments.distribution.update', $test->department), ['distribution_mode' => 'automatic', 'max_open_conversations_per_agent' => 3])
        ->assertSessionHasNoErrors()],
]);

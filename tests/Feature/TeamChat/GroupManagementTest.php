<?php

use App\Enums\AuditAction;
use App\Enums\DataScope;
use App\Enums\Permission;
use App\Enums\TeamChat\TeamMessageKind;
use App\Enums\TeamChat\TeamParticipantRole;
use App\Models\AuditLog;
use App\Models\Department;
use App\Models\TeamChat\TeamMessage;
use App\Models\TeamChat\TeamThread;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    [$this->sales, $this->support] = Department::factory()->count(2)->create();
    $this->lead = User::factory()->supervisor()->inDepartments($this->support)->create(['name' => 'Lucía']);
    $this->ana = User::factory()->agent()->inDepartments($this->support)->create(['name' => 'Ana']);
    $this->beto = User::factory()->agent()->inDepartments($this->support)->create(['name' => 'Beto']);
});

function teamGroup(User $admin, array $members, array $attributes = []): TeamThread
{
    return TeamThread::factory()->withParticipants($members, [$admin])->create(['name' => 'Equipo', ...$attributes]);
}

test('a supervisor creates a group and becomes its administrator', function () {
    $this->actingAs($this->lead)
        ->post(route('team-chat.groups.store'), [
            'name' => '  Turno   matutino ',
            'description' => 'Coordinación del turno',
            'participant_ids' => [$this->ana->id, $this->beto->id, $this->lead->id],
        ])
        ->assertRedirect();

    $thread = TeamThread::query()->sole();

    expect($thread->name)->toBe('Turno matutino')
        ->and($thread->participationOf($this->lead)->role)->toBe(TeamParticipantRole::Admin)
        ->and($thread->participationOf($this->ana)->role)->toBe(TeamParticipantRole::Member)
        ->and($thread->activeParticipations()->count())->toBe(3)
        ->and($thread->messages()->sole()->kind)->toBe(TeamMessageKind::System);

    $audit = AuditLog::query()->where('action', AuditAction::TeamChatGroupCreated)->sole();
    expect($audit->auditable_id)->toBe($thread->id)
        ->and($audit->properties['participants'])->toBe(['Ana', 'Beto', 'Lucía']);
});

test('creating groups requires its permission and participants within reach', function () {
    $this->actingAs($this->ana)
        ->post(route('team-chat.groups.store'), ['name' => 'Mi grupo', 'participant_ids' => [$this->beto->id]])
        ->assertForbidden();

    $outsider = User::factory()->agent()->inDepartments($this->sales)->create();
    $creator = conversationUser([Permission::UseTeamChat, Permission::CreateTeamChatGroups], DataScope::Departments, [$this->support]);

    $this->actingAs($creator)
        ->post(route('team-chat.groups.store'), ['name' => 'Mi grupo', 'participant_ids' => [$this->beto->id, $outsider->id]])
        ->assertSessionHasErrors(['participant_ids' => 'Algunas personas elegidas no están disponibles para el chat interno o están fuera de tu alcance.']);

    $this->actingAs($creator)
        ->post(route('team-chat.groups.store'), ['name' => '', 'participant_ids' => []])
        ->assertSessionHasErrors(['name', 'participant_ids' => 'Elige al menos a otra persona para el grupo.']);

    expect(TeamThread::query()->count())->toBe(0);
});

test('group administrators rename the group, members cannot', function () {
    $thread = teamGroup($this->lead, [$this->ana]);

    $this->actingAs($this->ana)->put(route('team-chat.threads.update', $thread), ['name' => 'Nuevo'])->assertForbidden();

    $this->actingAs($this->lead)
        ->put(route('team-chat.threads.update', $thread), ['name' => 'Equipo', 'description' => null])
        ->assertInertiaFlash('toast.message', 'No había cambios por guardar.');

    $this->actingAs($this->lead)->put(route('team-chat.threads.update', $thread), ['name' => 'Escalamientos'])->assertRedirect();

    expect($thread->fresh()->name)->toBe('Escalamientos')
        ->and(AuditLog::query()->where('action', AuditAction::TeamChatGroupUpdated)->sole()->properties['changes']['name'])->toBe(['from' => 'Equipo', 'to' => 'Escalamientos']);

    $this->actingAs($this->ana)
        ->get(route('team-chat.threads.show', $thread))
        ->assertInertia(fn (Assert $page) => $page
            ->where('messages.data.0.system_text', 'Lucía cambió el nombre del grupo a «Escalamientos».'));
});

test('participants are added within reach and deleted accounts are refused', function () {
    $thread = teamGroup($this->lead, [$this->ana]);
    $deleted = User::factory()->agent()->inactive()->inDepartments($this->support)->create();

    $this->actingAs($this->lead)
        ->post(route('team-chat.threads.participants.store', $thread), ['user_ids' => [$deleted->id]])
        ->assertSessionHasErrors('user_ids');

    $this->actingAs($this->lead)
        ->post(route('team-chat.threads.participants.store', $thread), ['user_ids' => [$this->beto->id, $this->ana->id]])
        ->assertInertiaFlash('toast.message', 'Se agregó 1 participante.');

    expect($thread->activeParticipations()->count())->toBe(3)
        ->and(AuditLog::query()->where('action', AuditAction::TeamChatParticipantsAdded)->sole()->properties['participants'])->toBe(['Beto']);
});

test('the group size limit is enforced', function () {
    config(['team-chat.group_max_participants' => 2]);
    $thread = teamGroup($this->lead, [$this->ana]);

    $this->actingAs($this->lead)
        ->post(route('team-chat.threads.participants.store', $thread), ['user_ids' => [$this->beto->id]])
        ->assertSessionHasErrors(['user_ids' => 'Un grupo puede tener como máximo 2 participantes.']);
});

test('a removed participant can be added again and only sees new messages as unread', function () {
    $thread = teamGroup($this->lead, [$this->ana]);
    TeamMessage::factory()->in($thread, $this->lead)->create();

    $this->actingAs($this->lead)->delete(route('team-chat.threads.participants.destroy', [$thread, $this->ana]))->assertRedirect();
    expect($thread->participationOf($this->ana))->toBeNull()
        ->and(AuditLog::query()->where('action', AuditAction::TeamChatParticipantRemoved)->sole()->properties)->toBe(['participant' => 'Ana']);

    TeamMessage::factory()->in($thread, $this->lead)->create();
    $this->actingAs($this->lead)->post(route('team-chat.threads.participants.store', $thread), ['user_ids' => [$this->ana->id]]);

    expect($thread->participations()->where('user_id', $this->ana->id)->count())->toBe(1)
        ->and($thread->participationOf($this->ana)->role)->toBe(TeamParticipantRole::Member);

    $this->actingAs($this->ana)
        ->get(route('team-chat.index'))
        ->assertInertia(fn (Assert $page) => $page->where('threads.threads.0.unread', 0)->where('unreadTotal', 0));
});

test('an administrator cannot remove themselves, they leave instead', function () {
    $thread = teamGroup($this->lead, [$this->ana]);

    $this->actingAs($this->lead)
        ->delete(route('team-chat.threads.participants.destroy', [$thread, $this->lead]))
        ->assertSessionHasErrors('user');
});

test('the last administrator cannot be demoted', function () {
    $thread = teamGroup($this->lead, [$this->ana]);

    $this->actingAs($this->lead)
        ->put(route('team-chat.threads.participants.update', [$thread, $this->lead]), ['role' => 'member'])
        ->assertSessionHasErrors(['role' => 'El grupo debe conservar al menos un administrador.']);

    $this->actingAs($this->lead)->put(route('team-chat.threads.participants.update', [$thread, $this->ana]), ['role' => 'admin'])->assertRedirect();
    $this->actingAs($this->ana)->put(route('team-chat.threads.participants.update', [$thread, $this->lead]), ['role' => 'member'])->assertRedirect();

    expect($thread->participationOf($this->lead)->role)->toBe(TeamParticipantRole::Member)
        ->and(AuditLog::query()->where('action', AuditAction::TeamChatParticipantRoleChanged)->count())->toBe(2);
});

test('when the last administrator leaves the longest standing member takes over', function () {
    $thread = TeamThread::factory()->withParticipants([], [$this->lead])->create();
    $thread->participations()->create(['user_id' => $this->ana->id, 'joined_at' => now()->subDay()]);
    $thread->participations()->create(['user_id' => $this->beto->id, 'joined_at' => now()]);

    $this->actingAs($this->lead)->post(route('team-chat.threads.leave', $thread))->assertRedirect(route('team-chat.index'));

    expect($thread->participationOf($this->ana)->role)->toBe(TeamParticipantRole::Admin)
        ->and($thread->participationOf($this->beto)->role)->toBe(TeamParticipantRole::Member)
        ->and($thread->participationOf($this->lead))->toBeNull();
});

test('a group left by everyone is archived', function () {
    $thread = TeamThread::factory()->withParticipants([], [$this->lead])->create();

    $this->actingAs($this->lead)->post(route('team-chat.threads.leave', $thread));

    expect($thread->fresh()->isArchived())->toBeTrue();
});

test('an archived group keeps its history read-only until it is reactivated', function () {
    $thread = teamGroup($this->lead, [$this->ana]);
    TeamMessage::factory()->in($thread, $this->ana)->create(['body' => 'Historial']);

    $this->actingAs($this->ana)->post(route('team-chat.threads.archive', $thread))->assertForbidden();
    $this->actingAs($this->lead)->post(route('team-chat.threads.archive', $thread))->assertRedirect();

    $this->actingAs($this->ana)->postJson(route('team-chat.threads.messages.store', $thread), ['body' => 'Hola'])->assertForbidden();
    $this->actingAs($this->ana)
        ->get(route('team-chat.threads.show', $thread))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('activeThread.archived', true)->where('activeThread.can.post', false));
    $this->actingAs($this->ana)
        ->get(route('team-chat.index', ['filter' => 'archived']))
        ->assertInertia(fn (Assert $page) => $page->has('threads.threads', 1));
    $this->actingAs($this->ana)
        ->get(route('team-chat.index'))
        ->assertInertia(fn (Assert $page) => $page->has('threads.threads', 0));

    $this->actingAs($this->lead)->post(route('team-chat.threads.unarchive', $thread))->assertRedirect();
    $this->actingAs($this->ana)->postJson(route('team-chat.threads.messages.store', $thread), ['body' => 'Hola'])->assertCreated();

    expect(AuditLog::query()->whereIn('action', [AuditAction::TeamChatGroupArchived, AuditAction::TeamChatGroupUnarchived])->count())->toBe(2);
});

test('the manage groups permission administers groups the person belongs to, never others', function () {
    $manager = conversationUser([Permission::UseTeamChat, Permission::ManageTeamChatGroups], DataScope::Departments, [$this->support]);
    $member = teamGroup($this->lead, [$manager, $this->ana]);
    $foreign = teamGroup($this->lead, [$this->ana]);

    $this->actingAs($manager)->put(route('team-chat.threads.update', $member), ['name' => 'Renombrado'])->assertRedirect();
    $this->actingAs($manager)->put(route('team-chat.threads.update', $foreign), ['name' => 'Renombrado'])->assertNotFound();

    expect($member->fresh()->name)->toBe('Renombrado')
        ->and($foreign->fresh()->name)->toBe('Equipo');
});

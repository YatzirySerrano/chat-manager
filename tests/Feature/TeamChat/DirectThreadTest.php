<?php

use App\Enums\DataScope;
use App\Enums\Permission;
use App\Enums\TeamChat\TeamThreadType;
use App\Models\Department;
use App\Models\TeamChat\TeamThread;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    [$this->sales, $this->support] = Department::factory()->count(2)->create();
    $this->ana = User::factory()->agent()->inDepartments($this->support)->create(['name' => 'Ana']);
    $this->beto = User::factory()->agent()->inDepartments($this->support)->create(['name' => 'Beto']);
    $this->carla = User::factory()->agent()->inDepartments($this->sales)->create(['name' => 'Carla']);
});

test('starting a direct chat twice opens the same thread', function () {
    $this->actingAs($this->ana)->post(route('team-chat.direct.store'), ['user_id' => $this->beto->id]);
    $thread = TeamThread::query()->sole();

    $this->actingAs($this->beto)
        ->post(route('team-chat.direct.store'), ['user_id' => $this->ana->id])
        ->assertRedirect(route('team-chat.threads.show', $thread));

    expect(TeamThread::query()->count())->toBe(1)
        ->and($thread->type)->toBe(TeamThreadType::Direct)
        ->and($thread->direct_key)->toBe(TeamThread::directKeyFor($this->ana->id, $this->beto->id))
        ->and($thread->activeParticipations()->pluck('user_id')->sort()->values()->all())->toBe(collect([$this->ana->id, $this->beto->id])->sort()->values()->all());
});

test('without the cross department permission people only reach colleagues of their departments', function () {
    $this->actingAs($this->ana)
        ->post(route('team-chat.direct.store'), ['user_id' => $this->carla->id])
        ->assertSessionHasErrors(['user_id' => 'No puedes iniciar un chat con esta persona.']);

    $this->actingAs($this->ana)
        ->getJson(route('team-chat.people'))
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $this->beto->id)
        ->assertJsonPath('reaches_everyone', false);

    expect(TeamThread::query()->count())->toBe(0);
});

test('the cross department permission and the global scope reach everyone', function () {
    $crossDepartment = conversationUser([Permission::UseTeamChat, Permission::UseCrossDepartmentTeamChat], DataScope::Assigned, [$this->support]);
    $global = conversationUser([Permission::UseTeamChat], DataScope::Global);

    $this->actingAs($crossDepartment)->post(route('team-chat.direct.store'), ['user_id' => $this->carla->id])->assertRedirect();
    $this->actingAs($global)->post(route('team-chat.direct.store'), ['user_id' => $this->carla->id])->assertRedirect();

    expect(TeamThread::query()->count())->toBe(2);
});

test('people without the chat permission, deleted accounts and oneself cannot be contacted', function () {
    $withoutChat = conversationUser([Permission::ViewDashboard], DataScope::Assigned, [$this->support]);
    $deleted = User::factory()->agent()->inactive()->inDepartments($this->support)->create();

    foreach ([$withoutChat, $deleted] as $target) {
        $this->actingAs($this->ana)
            ->post(route('team-chat.direct.store'), ['user_id' => $target->id])
            ->assertSessionHasErrors('user_id');
    }

    $this->actingAs($this->ana)
        ->post(route('team-chat.direct.store'), ['user_id' => $this->ana->id])
        ->assertSessionHasErrors(['user_id' => 'No puedes iniciar un chat contigo.']);

    $this->actingAs($this->ana)
        ->getJson(route('team-chat.people', ['search' => '']))
        ->assertJsonMissing(['id' => $deleted->id])
        ->assertJsonMissing(['id' => $withoutChat->id]);

    expect(TeamThread::query()->count())->toBe(0);
});

test('a deleted partner is shown as deleted and the direct chat no longer accepts messages', function () {
    $thread = TeamThread::factory()->direct($this->ana, $this->beto)->create();
    $this->beto->forceFill(['is_active' => false])->save();

    $this->actingAs($this->ana)
        ->get(route('team-chat.threads.show', $thread))
        ->assertInertia(fn (Assert $page) => $page
            ->where('activeThread.title', 'Usuario eliminado')
            ->where('activeThread.partner.deleted', true)
            ->where('activeThread.can.post', false)
            ->where('activeThread.post_blocked_reason', 'La otra persona ya no tiene una cuenta activa.')
            ->where('threads.threads.0.title', 'Usuario eliminado')
            ->missing('activeThread.partner.email'));

    $this->actingAs($this->ana)
        ->postJson(route('team-chat.threads.messages.store', $thread), ['body' => 'Hola'])
        ->assertForbidden();
});

test('a direct chat cannot be left', function () {
    $thread = TeamThread::factory()->direct($this->ana, $this->beto)->create();

    $this->actingAs($this->ana)->post(route('team-chat.threads.leave', $thread))->assertForbidden();

    expect($thread->activeParticipations()->count())->toBe(2);
});

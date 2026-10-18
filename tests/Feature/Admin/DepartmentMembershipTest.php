<?php

use App\Actions\Departments\AddDepartmentMember;
use App\Enums\AuditAction;
use App\Enums\ConversationStatus;
use App\Models\AuditLog;
use App\Models\Conversation;
use App\Models\Department;
use App\Models\User;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;

test('administrators can add supervisors and agents to a department', function () {
    $admin = User::factory()->administrator()->create();
    $department = Department::factory()->create();
    $supervisor = User::factory()->supervisor()->create();
    $agent = User::factory()->agent()->create();

    $this->actingAs($admin)->post(route('departments.members.store', $department), ['user_id' => $supervisor->id])->assertSessionHasNoErrors();
    $this->actingAs($admin)->post(route('departments.members.store', $department), ['user_id' => $agent->id])->assertSessionHasNoErrors();

    expect($department->members()->pluck('users.id')->sort()->values()->all())
        ->toBe(collect([$supervisor->id, $agent->id])->sort()->values()->all());

    expect(AuditLog::query()->where('action', AuditAction::DepartmentMemberAdded)->count())->toBe(2);
});

test('an agent can belong to several departments', function () {
    $admin = User::factory()->administrator()->create();
    [$sales, $support] = Department::factory()->count(2)->create();
    $agent = User::factory()->agent()->inDepartments($sales)->create();

    $this->actingAs($admin)
        ->post(route('departments.members.store', $support), ['user_id' => $agent->id])
        ->assertSessionHasNoErrors();

    expect($agent->departmentIds())->toEqualCanonicalizing([$sales->id, $support->id]);
});

test('supervisors can add agents to the departments they supervise', function () {
    $department = Department::factory()->create();
    $supervisor = User::factory()->supervisor()->inDepartments($department)->create();
    $agent = User::factory()->agent()->create();

    $this->actingAs($supervisor)
        ->post(route('departments.members.store', $department), ['user_id' => $agent->id])
        ->assertSessionHasNoErrors();

    expect($agent->belongsToDepartment($department))->toBeTrue();
});

test('supervisors cannot manage members of departments they do not supervise', function () {
    $own = Department::factory()->create();
    $other = Department::factory()->create();
    $supervisor = User::factory()->supervisor()->inDepartments($own)->create();
    $agent = User::factory()->agent()->inDepartments($other)->create();
    $newAgent = User::factory()->agent()->create();

    $this->actingAs($supervisor)
        ->post(route('departments.members.store', $other), ['user_id' => $newAgent->id])
        ->assertForbidden();

    $this->actingAs($supervisor)
        ->delete(route('departments.members.destroy', [$other, $agent]))
        ->assertForbidden();

    expect($newAgent->belongsToDepartment($other))->toBeFalse()
        ->and($agent->belongsToDepartment($other))->toBeTrue();
});

test('supervisors cannot add other supervisors', function () {
    $department = Department::factory()->create();
    $supervisor = User::factory()->supervisor()->inDepartments($department)->create();
    $otherSupervisor = User::factory()->supervisor()->create();

    $this->actingAs($supervisor)
        ->post(route('departments.members.store', $department), ['user_id' => $otherSupervisor->id])
        ->assertForbidden();
});

test('supervisors only get agents as candidates and can only remove agents', function () {
    $department = Department::factory()->create();
    $supervisor = User::factory()->supervisor()->inDepartments($department)->create();
    $agent = User::factory()->agent()->inDepartments($department)->create();
    User::factory()->agent()->create(['name' => 'Candidato Agente']);
    User::factory()->supervisor()->create(['name' => 'Candidato Supervisor']);
    User::factory()->agent()->inactive()->create(['name' => 'Agente Inactivo']);

    $this->actingAs($supervisor)
        ->get(route('departments.show', $department))
        ->assertInertia(fn (Assert $page) => $page
            ->component('departments/Show')
            ->has('members', 2)
            ->has('candidates', 1)
            ->where('candidates.0.name', 'Candidato Agente')
            ->where('removableMemberIds', [$agent->id])
            ->where('can.manageMembers', true)
            ->where('can.update', false)
        );
});

test('inconsistent memberships are rejected', function (string $case) {
    $admin = User::factory()->administrator()->create();
    $department = $case === 'inactive department'
        ? Department::factory()->inactive()->create()
        : Department::factory()->create();

    $member = match ($case) {
        'inactive user' => User::factory()->agent()->inactive()->create(),
        'administrator' => User::factory()->administrator()->create(),
        'already a member' => User::factory()->agent()->inDepartments($department)->create(),
        default => User::factory()->agent()->create(),
    };

    $this->actingAs($admin)
        ->post(route('departments.members.store', $department), ['user_id' => $member->id])
        ->assertSessionHasErrors('user_id');
})->with(['inactive user', 'administrator', 'already a member', 'inactive department']);

test('removing a member returns their conversations in that department to the queue', function () {
    $admin = User::factory()->administrator()->create();
    [$sales, $support] = Department::factory()->count(2)->create();
    $agent = User::factory()->agent()->inDepartments([$sales, $support])->create();
    $salesConversation = Conversation::factory()->inDepartment($sales)->assignedTo($agent)->create();
    $supportConversation = Conversation::factory()->inDepartment($support)->assignedTo($agent)->create();

    $this->actingAs($admin)
        ->delete(route('departments.members.destroy', [$sales, $agent]))
        ->assertSessionHasNoErrors();

    expect($agent->belongsToDepartment($sales))->toBeFalse()
        ->and($salesConversation->refresh()->status)->toBe(ConversationStatus::Pending)
        ->and($salesConversation->assigned_user_id)->toBeNull()
        ->and($supportConversation->refresh()->assigned_user_id)->toBe($agent->id);

    expect(AuditLog::query()->where('action', AuditAction::DepartmentMemberRemoved)->sole()->properties)
        ->toMatchArray(['member_id' => $agent->id, 'released_conversations' => 1]);
});

test('a member added by a simultaneous request is reported instead of failing', function () {
    $admin = User::factory()->administrator()->create();
    $department = Department::factory()->create();
    $agent = User::factory()->agent()->create();

    $raced = false;
    DB::listen(function (QueryExecuted $query) use (&$raced, $department, $agent) {
        if (! $raced && str_starts_with($query->sql, 'select') && str_contains($query->sql, '`department_user`')) {
            $raced = true;
            DB::table('department_user')->insert(['department_id' => $department->id, 'user_id' => $agent->id]);
        }
    });

    expect(fn () => app(AddDepartmentMember::class)->handle($department, $agent, $admin))
        ->toThrow(ValidationException::class, 'El usuario ya pertenece a este departamento.')
        ->and($raced)->toBeTrue();
});

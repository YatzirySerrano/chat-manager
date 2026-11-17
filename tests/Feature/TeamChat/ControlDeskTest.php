<?php

use App\Enums\DataScope;
use App\Enums\Permission;
use App\Enums\PermissionGroup;
use App\Enums\TeamChat\TeamDeskStatus;
use App\Enums\TeamChat\TeamThreadType;
use App\Models\Department;
use App\Models\TeamChat\TeamMessage;
use App\Models\TeamChat\TeamThread;
use App\Models\User;
use App\Services\TeamChat\ControlDesk;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;

/*
| «Mesa de control»: requesters (team-chat.use without
| team-chat.control-desk.attend) only reach their desk thread; auditors
| (with it) accept, answer and complete requests.
*/

beforeEach(function () {
    $this->department = Department::factory()->create();
    $this->requester = conversationUser([Permission::UseTeamChat], DataScope::Assigned, [$this->department]);
    $this->requester->forceFill(['name' => 'Rosa Solicitante'])->save();
    $this->auditor = conversationUser([Permission::UseTeamChat, Permission::AttendControlDesk], DataScope::Assigned, [$this->department]);
    $this->auditor->forceFill(['name' => 'Laura Auditora'])->save();
    $this->otherAuditor = conversationUser([Permission::UseTeamChat, Permission::AttendControlDesk], DataScope::Assigned, [$this->department]);
});

/**
 * The requester's desk thread after she writes to it.
 */
function deskRequest(User $requester, string $body = 'Necesito revisar un caso'): TeamThread
{
    $thread = app(ControlDesk::class)->threadFor($requester);

    test()->actingAs($requester)
        ->postJson(route('team-chat.threads.messages.store', $thread), ['body' => $body])
        ->assertCreated();

    return $thread->fresh();
}

test('the attend permission is catalogued in the team chat group and granted to the default roles that chat', function () {
    expect(Permission::AttendControlDesk->value)->toBe('team-chat.control-desk.attend')
        ->and(Permission::AttendControlDesk->label())->toBe('Atender mesa de control')
        ->and(Permission::AttendControlDesk->group())->toBe(PermissionGroup::TeamChat)
        ->and(Permission::AttendControlDesk->isAvailable())->toBeTrue();

    $agent = User::factory()->agent()->create();
    $supervisor = User::factory()->supervisor()->create();

    expect(app(ControlDesk::class)->attends($agent))->toBeTrue()
        ->and(app(ControlDesk::class)->attends($supervisor))->toBeTrue()
        ->and(app(ControlDesk::class)->isRequester($this->requester))->toBeTrue()
        ->and(app(ControlDesk::class)->isRequester($this->auditor))->toBeFalse();
});

test('a requester only sees the «Mesa de control», created the first time she opens the chat', function () {
    // A direct chat from before she became a requester.
    $old = TeamThread::factory()->direct($this->requester, $this->auditor)->create();
    TeamMessage::factory()->for($old, 'thread')->create(['user_id' => $this->auditor->id, 'body' => 'Hola']);

    $this->actingAs($this->requester)
        ->get(route('team-chat.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('desk.role', 'requester')
            ->where('can.startDirect', false)
            ->where('can.createGroup', false)
            ->where('unreadTotal', 0)
            ->has('threads.threads', 1)
            ->where('threads.threads.0.type', 'desk')
            ->where('threads.threads.0.title', 'Mesa de control')
            ->where('threads.threads.0.desk.status', 'new'));

    $desk = TeamThread::query()->where('type', TeamThreadType::Desk)->sole();

    expect($desk->desk_requester_id)->toBe($this->requester->id)
        ->and($desk->activeParticipations()->pluck('user_id')->all())->toBe([$this->requester->id]);

    // Opening it again never creates a second one.
    $this->actingAs($this->requester)->get(route('team-chat.index'))->assertOk();
    expect(TeamThread::query()->where('type', TeamThreadType::Desk)->count())->toBe(1);

    $this->actingAs($this->requester)->get(route('team-chat.threads.show', $desk))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('activeThread.title', 'Mesa de control')->where('activeThread.can.post', true));
});

test('the backend refuses direct chats, groups and old threads to a requester, and nobody reaches her', function () {
    $old = TeamThread::factory()->direct($this->requester, $this->auditor)->create();
    $group = TeamThread::factory()->withParticipants([$this->requester, $this->auditor])->create();

    $this->actingAs($this->requester)->post(route('team-chat.direct.store'), ['user_id' => $this->auditor->id])->assertForbidden();
    $this->actingAs($this->requester)->post(route('team-chat.groups.store'), ['name' => 'Equipo', 'participant_ids' => [$this->auditor->id]])->assertForbidden();
    $this->actingAs($this->requester)->get(route('team-chat.threads.show', $old))->assertNotFound();
    $this->actingAs($this->requester)->getJson(route('team-chat.threads.messages.index', $group))->assertNotFound();
    $this->actingAs($this->requester)->postJson(route('team-chat.threads.messages.store', $old), ['body' => 'Hola'])->assertNotFound();
    $this->actingAs($this->requester)->getJson(route('team-chat.people'))->assertOk()->assertJsonCount(0, 'data');

    // Auditors cannot open a direct chat with her either (she would never see it).
    $this->actingAs($this->auditor)
        ->post(route('team-chat.direct.store'), ['user_id' => $this->requester->id])
        ->assertSessionHasErrors(['user_id' => 'No puedes iniciar un chat con esta persona.']);

    $this->actingAs($this->auditor)->getJson(route('team-chat.people'))
        ->assertOk()
        ->assertJsonMissing(['id' => $this->requester->id]);
});

test('a request goes pending, is accepted, completed and returns to the waiting list with a new message', function () {
    $thread = deskRequest($this->requester);

    expect($thread->desk_status)->toBe(TeamDeskStatus::Pending)
        ->and($thread->desk_assigned_user_id)->toBeNull()
        ->and($thread->desk_requested_at)->not->toBeNull();

    // Every auditor sees it waiting; nobody answers before accepting.
    $this->actingAs($this->auditor)
        ->get(route('team-chat.index', ['desk' => 'pending']))
        ->assertInertia(fn (Assert $page) => $page
            ->where('desk.role', 'auditor')
            ->where('deskPending', 1)
            ->where('deskInbox.tab', 'pending')
            ->where('deskInbox.threads.0.id', $thread->id)
            ->where('deskInbox.threads.0.title', 'Rosa Solicitante')
            ->has('threads.threads', 0));

    $this->actingAs($this->auditor)
        ->get(route('team-chat.threads.show', $thread))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('activeThread.can.post', false)
            ->where('activeThread.can.acceptDesk', true)
            ->where('activeThread.post_blocked_reason', 'Acepta la solicitud para responder.'));

    $this->actingAs($this->auditor)->postJson(route('team-chat.threads.messages.store', $thread), ['body' => 'Hola'])->assertForbidden();

    // Accept: hers, with the request still unread.
    $this->actingAs($this->auditor)
        ->post(route('team-chat.threads.desk.accept', $thread))
        ->assertRedirect(route('team-chat.threads.show', ['thread' => $thread, 'desk' => 'mine']));

    $thread->refresh();
    expect($thread->desk_status)->toBe(TeamDeskStatus::Open)
        ->and($thread->desk_assigned_user_id)->toBe($this->auditor->id)
        ->and($thread->desk_accepted_at)->not->toBeNull()
        ->and($thread->participationOf($this->auditor))->not->toBeNull();

    $this->actingAs($this->auditor)
        ->get(route('team-chat.index', ['desk' => 'mine']))
        ->assertInertia(fn (Assert $page) => $page
            ->where('deskInbox.counts.mine', 1)
            ->where('deskInbox.counts.pending', 0)
            ->where('deskInbox.threads.0.id', $thread->id)
            ->where('deskInbox.threads.0.unread', 1));

    // Messages of the requester keep it with the same auditor.
    $this->actingAs($this->requester)->postJson(route('team-chat.threads.messages.store', $thread), ['body' => 'Te mando el video'])->assertCreated();
    expect($thread->fresh()->desk_assigned_user_id)->toBe($this->auditor->id)
        ->and($thread->fresh()->desk_status)->toBe(TeamDeskStatus::Open);

    // Only the assignee answers; other auditors can read.
    $this->actingAs($this->auditor)->postJson(route('team-chat.threads.messages.store', $thread), ['body' => 'Lo reviso'])->assertCreated();
    $this->actingAs($this->otherAuditor)->postJson(route('team-chat.threads.messages.store', $thread), ['body' => 'Yo también'])->assertForbidden();
    $this->actingAs($this->otherAuditor)->getJson(route('team-chat.threads.messages.index', $thread))->assertOk();
    $this->actingAs($this->otherAuditor)->post(route('team-chat.threads.desk.complete', $thread))->assertSessionHasErrors(['desk']);

    // The requester never sees which auditor holds it.
    $this->actingAs($this->requester)
        ->get(route('team-chat.threads.show', $thread))
        ->assertInertia(fn (Assert $page) => $page
            ->where('activeThread.desk.status', 'open')
            ->where('activeThread.desk.assignee', null)
            ->has('activeThread.participants', 1));

    // «Solicitud completa».
    $this->actingAs($this->auditor)->post(route('team-chat.threads.desk.complete', $thread))->assertRedirect();

    $thread->refresh();
    expect($thread->desk_status)->toBe(TeamDeskStatus::Closed)
        ->and($thread->desk_completed_by_user_id)->toBe($this->auditor->id)
        ->and($thread->desk_completed_at)->not->toBeNull()
        ->and($thread->desk_assigned_user_id)->toBeNull()
        ->and($thread->participationOf($this->auditor))->toBeNull()
        ->and(TeamMessage::query()->where('team_thread_id', $thread->id)->where('kind', 'system')->pluck('metadata')->map(fn ($metadata) => $metadata['event'])->all())
        ->toBe(['desk_accepted', 'desk_completed']);

    $this->actingAs($this->auditor)->postJson(route('team-chat.threads.messages.store', $thread), ['body' => 'Otra cosa'])->assertForbidden();
    $this->actingAs($this->auditor)
        ->get(route('team-chat.index', ['desk' => 'completed']))
        ->assertInertia(fn (Assert $page) => $page->where('deskInbox.threads.0.id', $thread->id)->where('deskInbox.threads.0.desk.completed_by', 'Laura Auditora'));

    // A new message puts it back on the waiting list, unassigned.
    $this->actingAs($this->requester)->postJson(route('team-chat.threads.messages.store', $thread), ['body' => 'Una duda más'])->assertCreated();

    $thread->refresh();
    expect($thread->desk_status)->toBe(TeamDeskStatus::Pending)
        ->and($thread->desk_assigned_user_id)->toBeNull();
});

test('accepting respects the simultaneous attention limit of the auditor, counted for the desk', function () {
    $this->auditor->forceFill(['max_open_conversations' => 1])->save();

    $first = deskRequest($this->requester);
    $secondRequester = conversationUser([Permission::UseTeamChat], DataScope::Assigned, [$this->department]);
    $second = deskRequest($secondRequester);

    $this->actingAs($this->auditor)->post(route('team-chat.threads.desk.accept', $first))->assertRedirect();

    $this->actingAs($this->auditor)
        ->post(route('team-chat.threads.desk.accept', $second))
        ->assertSessionHasErrors(['desk' => ControlDesk::capacityMessage(1)]);

    expect($second->fresh()->desk_status)->toBe(TeamDeskStatus::Pending)
        ->and($second->fresh()->desk_assigned_user_id)->toBeNull();

    // Completing one frees the room.
    $this->actingAs($this->auditor)->post(route('team-chat.threads.desk.complete', $first))->assertRedirect();
    $this->actingAs($this->auditor)->post(route('team-chat.threads.desk.accept', $second))->assertRedirect();

    expect($second->fresh()->desk_assigned_user_id)->toBe($this->auditor->id);
});

test('two auditors cannot take the same request', function () {
    $thread = deskRequest($this->requester);

    $this->actingAs($this->auditor)->post(route('team-chat.threads.desk.accept', $thread))->assertRedirect();
    $this->actingAs($this->otherAuditor)
        ->post(route('team-chat.threads.desk.accept', $thread))
        ->assertSessionHasErrors(['desk' => 'Otra persona de la mesa de control ya aceptó esta solicitud.']);

    expect($thread->fresh()->desk_assigned_user_id)->toBe($this->auditor->id)
        ->and($thread->fresh()->participationOf($this->otherAuditor))->toBeNull();

    // Accepting her own request again changes nothing.
    $this->actingAs($this->auditor)->post(route('team-chat.threads.desk.accept', $thread))->assertRedirect();
    expect(TeamMessage::query()->where('team_thread_id', $thread->id)->where('kind', 'system')->count())->toBe(1);
});

test('only auditors accept requests and requesters never attend the desk', function () {
    $thread = deskRequest($this->requester);
    $outsider = conversationUser([Permission::ViewDashboard], DataScope::Assigned, [$this->department]);

    $this->actingAs($this->requester)->post(route('team-chat.threads.desk.accept', $thread))->assertNotFound();
    $this->actingAs($outsider)->post(route('team-chat.threads.desk.accept', $thread))->assertNotFound();
    $this->actingAs($outsider)->get(route('team-chat.threads.show', $thread))->assertNotFound();

    // Another requester cannot open someone else's desk thread.
    $nosy = conversationUser([Permission::UseTeamChat], DataScope::Assigned, [$this->department]);
    $this->actingAs($nosy)->get(route('team-chat.threads.show', $thread))->assertNotFound();

    expect($thread->fresh()->desk_status)->toBe(TeamDeskStatus::Pending);
});

test('«Pendientes» shows the newest request first and «Mías» the one waiting longest first', function () {
    $requesters = collect(range(1, 3))->map(fn (int $index): User => tap(
        conversationUser([Permission::UseTeamChat], DataScope::Assigned, [$this->department]),
        fn (User $user) => $user->forceFill(['name' => "Solicitante {$index}"])->save(),
    ));
    $threads = [];

    foreach ($requesters as $index => $requester) {
        Carbon::setTestNow(now()->addMinutes(10));
        $threads[$index] = deskRequest($requester->fresh());
    }

    Carbon::setTestNow();

    $this->actingAs($this->auditor)
        ->get(route('team-chat.index', ['desk' => 'pending']))
        ->assertInertia(fn (Assert $page) => $page
            ->where('deskInbox.threads.0.id', $threads[2]->id)
            ->where('deskInbox.threads.1.id', $threads[1]->id)
            ->where('deskInbox.threads.2.id', $threads[0]->id));

    // Accepted in another order: «Mías» still puts the oldest request on top.
    foreach ([1, 2, 0] as $index) {
        $this->actingAs($this->auditor)->post(route('team-chat.threads.desk.accept', $threads[$index]))->assertRedirect();
    }

    $this->actingAs($this->auditor)
        ->get(route('team-chat.index', ['desk' => 'mine']))
        ->assertInertia(fn (Assert $page) => $page
            ->where('deskInbox.threads.0.id', $threads[0]->id)
            ->where('deskInbox.threads.1.id', $threads[1]->id)
            ->where('deskInbox.threads.2.id', $threads[2]->id)
            ->where('deskInbox.capacity.load', 3));
});

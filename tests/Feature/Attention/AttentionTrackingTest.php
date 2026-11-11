<?php

use App\Enums\ConversationStatus;
use App\Enums\DataScope;
use App\Enums\Permission;
use App\Models\Conversation;
use App\Models\Department;
use App\Models\User;
use App\Services\PlatformSettings;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

/*
| «Seguimiento de atención»: what each agent within the viewer's reach is
| attending, who is waiting for an answer and since when. Authorized on the
| server; open conversations are never counted as unattended by themselves.
*/

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-02 16:00', 'UTC'));
    app(PlatformSettings::class)->update(['operations' => ['pending_block_enabled' => true, 'pending_block_after_minutes' => 10]]);

    $this->support = Department::factory()->create(['name' => 'Soporte']);
    $this->sales = Department::factory()->create(['name' => 'Ventas']);
    $this->admin = User::factory()->administrator()->create(['name' => 'Admin']);
    $this->ana = User::factory()->agent()->inDepartments($this->support)->create(['name' => 'Ana']);
    $this->beto = User::factory()->agent()->inDepartments($this->sales)->create(['name' => 'Beto']);
});

function trackedConversation(User $agent, Department $department, string $customerWrote, ?string $teamAnswered = null, ConversationStatus $status = ConversationStatus::Open): Conversation
{
    return Conversation::factory()->inDepartment($department)->assignedTo($agent)->create([
        'status' => $status,
        'last_inbound_at' => CarbonImmutable::parse($customerWrote, 'UTC'),
        'last_outbound_at' => $teamAnswered === null ? null : CarbonImmutable::parse($teamAnswered, 'UTC'),
        'assigned_at' => CarbonImmutable::parse($customerWrote, 'UTC'),
    ]);
}

test('each agent shows what they attend, who waits for their answer and since when', function () {
    $oldest = trackedConversation($this->ana, $this->support, '2026-11-02 15:00');
    trackedConversation($this->ana, $this->support, '2026-11-02 15:55');
    trackedConversation($this->ana, $this->support, '2026-11-02 14:00', '2026-11-02 14:30');
    trackedConversation($this->ana, $this->support, '2026-11-02 13:00', null, ConversationStatus::Closed);

    $this->actingAs($this->admin)->get(route('attention.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('attention/Index')
            ->has('agents', 2)
            ->where('agents.0.name', 'Ana')
            ->where('agents.0.in_attention', 3)
            ->where('agents.0.awaiting', 2)
            ->where('agents.0.waiting_customer', 1)
            ->where('agents.0.overdue', 1)
            ->where('agents.0.blocked', true)
            ->where('agents.0.oldest_waiting_since', '2026-11-02T15:00:00+00:00')
            ->where('agents.1.name', 'Beto')
            ->where('agents.1.in_attention', 0)
            ->where('agents.1.blocked', false)
            ->has('waiting', 2)
            ->where('waiting.0.id', $oldest->id)
            ->where('waiting.0.overdue', true)
            ->where('waiting.1.overdue', false)
            ->where('control.tolerance_minutes', 10));
});

test('the queue without agent is counted apart from the agents\' conversations', function () {
    Conversation::factory()->inDepartment($this->support)->create(['created_at' => CarbonImmutable::parse('2026-11-02 15:30', 'UTC'), 'last_inbound_at' => CarbonImmutable::parse('2026-11-02 15:30', 'UTC')]);

    $this->actingAs($this->admin)->get(route('attention.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('queue.count', 1)
            ->where('queue.oldest_since', '2026-11-02T15:30:00+00:00')
            ->has('waiting', 0));
});

test('a departments supervisor only follows the agents and conversations of their departments', function () {
    trackedConversation($this->ana, $this->support, '2026-11-02 15:00');
    trackedConversation($this->beto, $this->sales, '2026-11-02 15:00');
    $supervisor = conversationUser([Permission::ViewConversations, Permission::SuperviseConversations, Permission::ViewAttention], DataScope::Departments, [$this->support]);

    $this->actingAs($supervisor)->get(route('attention.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('agents', 1)
            ->where('agents.0.name', 'Ana')
            ->has('waiting', 1)
            ->where('waiting.0.agent.name', 'Ana')
            ->where('can.send', false)
            ->where('alerts', []));
});

test('the last activity comes from the open sessions, never from a guess', function () {
    config(['session.driver' => 'database']);
    DB::table('sessions')->insert([
        'id' => 'session-of-ana',
        'user_id' => $this->ana->id,
        'ip_address' => '127.0.0.1',
        'user_agent' => 'test',
        'payload' => '',
        'last_activity' => CarbonImmutable::parse('2026-11-02 15:58', 'UTC')->getTimestamp(),
    ]);

    $this->actingAs($this->admin)->get(route('attention.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('agents.0.last_activity_at', '2026-11-02T15:58:00+00:00')
            ->where('agents.1.last_activity_at', null));
});

test('people without the permission cannot open the page', function () {
    $this->actingAs($this->ana)->get(route('attention.index'))->assertForbidden();
});

test('administrators and supervisors see the tracking by default; only administrators send alerts', function () {
    expect(User::factory()->administrator()->create()->holdsPermission(Permission::ViewAttention))->toBeTrue()
        ->and(User::factory()->administrator()->create()->holdsPermission(Permission::SendAttentionAlerts))->toBeTrue()
        // Supervisors watch the SLA of their departments (docs/reglas-de-servicio.md §7).
        ->and(User::factory()->supervisor()->create()->holdsPermission(Permission::ViewAttention))->toBeTrue()
        ->and(User::factory()->supervisor()->create()->holdsPermission(Permission::SendAttentionAlerts))->toBeFalse()
        ->and($this->ana->holdsPermission(Permission::ViewAttention))->toBeFalse();
});

test('an agent blocked by a conversation outside the supervisor\'s departments still shows as blocked', function () {
    $this->ana->departments()->attach($this->sales->id);
    trackedConversation($this->ana, $this->sales, '2026-11-02 15:00');
    $supervisor = conversationUser([Permission::ViewConversations, Permission::SuperviseConversations, Permission::ViewAttention], DataScope::Departments, [$this->support]);

    $this->actingAs($supervisor)->get(route('attention.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('agents.0.name', 'Ana')
            ->where('agents.0.awaiting', 0)
            ->where('agents.0.blocked', true));
});

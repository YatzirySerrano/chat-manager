<?php

use App\Actions\Analytics\RebuildConversationMetrics;
use App\Enums\DataScope;
use App\Enums\MessageDirection;
use App\Enums\MessageStatus;
use App\Enums\Permission;
use App\Models\Connection;
use App\Models\Conversation;
use App\Models\Department;
use App\Models\Message;
use App\Models\Role;
use App\Models\User;
use App\Services\DashboardMetrics;
use Illuminate\Auth\Access\AuthorizationException;
use Inertia\Inertia;
use Inertia\Testing\AssertableInertia as Assert;

test('guests are redirected to the login page', function () {
    $response = $this->get(route('dashboard'));
    $response->assertRedirect(route('login'));
});

test('authenticated users can visit the dashboard', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $response = $this->get(route('dashboard'));
    $response->assertOk();
});

test('conversation metrics are unavailable until a WhatsApp number is connected', function () {
    $admin = User::factory()->superAdministrator()->create();
    Department::factory()->count(2)->create();

    $this->actingAs($admin)
        ->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page
            ->component('Dashboard')
            ->missing('overview')
            ->loadDeferredProps(fn (Assert $reload) => $reload
                ->where('overview.messaging_connected', false)
                ->where('overview.conversations.active', null)
                ->where('overview.average_first_response_seconds', null)
                // Availability is declared by each person, so it is real even without messaging.
                ->where('overview.agents.available', 0)
                ->where('overview.departments.active', 2)
            )
        );
});

test('conversation metrics only count the conversations the user can see', function () {
    $department = Department::factory()->create();
    $agent = User::factory()->agent()->inDepartments($department)->create();
    $account = Connection::factory()->operational()->create();
    Conversation::factory()->for($account, 'channelConnection')->inDepartment($department)->assignedTo($agent)->create();
    Conversation::factory()->for($account, 'channelConnection')->inDepartment($department)->create();
    Conversation::factory()->for($account, 'channelConnection')->assignedTo($agent)->closed()->create(['closed_at' => now()]);

    $this->actingAs($agent)
        ->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page
            ->loadDeferredProps(fn (Assert $reload) => $reload
                ->where('overview.scope', 'own')
                ->where('overview.messaging_connected', true)
                ->where('overview.conversations.active', 1)
                // The queued conversation is one the agent may accept, so it is visible (increment B).
                ->where('overview.conversations.pending', 1)
                ->where('overview.conversations.closed_today', 1)
                ->where('overview.departments', null)
            )
        );
});

test('without dashboard.view no organization metric is computed nor sent', function (array $permissions) {
    $user = User::factory()
        ->withRole(Role::factory()->scope(DataScope::Global)->withPermissions($permissions)->create())
        ->create();
    Department::factory()->count(3)->create();
    Conversation::factory()->for(Connection::factory()->operational(), 'channelConnection')->create();

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Dashboard')
            ->where('canViewMetrics', false)
            ->where('overview', null)
            ->missing('deferredProps'));

    $this->actingAs($user)
        ->get(route('dashboard'), [
            'X-Inertia' => 'true',
            'X-Inertia-Version' => (string) Inertia::getVersion(),
            'X-Inertia-Partial-Component' => 'Dashboard',
            'X-Inertia-Partial-Data' => 'overview',
        ])
        ->assertOk()
        ->assertJsonPath('props.overview', null);

    expect(fn () => app(DashboardMetrics::class)->overviewFor($user))->toThrow(AuthorizationException::class);
})->with([
    'no permission at all' => [[]],
    'other global permissions' => [[Permission::ViewDepartments, Permission::ViewConversations, Permission::ViewUsers]],
]);

test('the dashboard figures never exceed the permissions and departments of the viewer', function () {
    $own = Department::factory()->create();
    $other = Department::factory()->create();
    $connection = Connection::factory()->operational()->create();
    Conversation::factory()->for($connection, 'channelConnection')->inDepartment($own)->create();
    Conversation::factory()->for($connection, 'channelConnection')->inDepartment($other)->count(2)->create();
    User::factory()->agent()->inDepartments($other)->count(3)->create();
    $teammate = User::factory()->agent()->inDepartments($own)->create();

    $supervisor = User::factory()->supervisor()->inDepartments($own)->create();
    $withoutConversations = User::factory()
        ->withRole(Role::factory()->scope(DataScope::Departments)->withPermissions([Permission::ViewDashboard, Permission::ViewDepartments])->create())
        ->inDepartments($own)
        ->create();

    $this->actingAs($supervisor)->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page->loadDeferredProps(fn (Assert $reload) => $reload
            ->where('overview.scope', 'departments')
            ->where('overview.conversations.pending', 1)
            ->where('overview.departments.total', 1)
            ->where('overview.agents.registered', 2)));

    $this->actingAs($withoutConversations)->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page->loadDeferredProps(fn (Assert $reload) => $reload
            ->where('overview.conversations', ['active' => null, 'pending' => null, 'closed_today' => null])
            ->where('overview.average_first_response_seconds', null)
            ->where('overview.departments.total', 1)));

    expect($teammate->departmentIds())->toBe([$own->id]);
});

test('available agents come from the declared availability within the viewer reach', function () {
    $own = Department::factory()->create();
    $other = Department::factory()->create();
    User::factory()->agent()->available()->inDepartments($own)->count(2)->create();
    User::factory()->agent()->inDepartments($own)->create();
    User::factory()->agent()->available()->inDepartments($other)->create();
    $supervisor = User::factory()->supervisor()->inDepartments($own)->create();

    $this->actingAs($supervisor)->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page->loadDeferredProps(fn (Assert $reload) => $reload
            ->where('overview.agents.available', 2)
            ->where('overview.agents.registered', 4)));
});

test('the dashboard filters narrow the live figures and ignore unreachable values', function () {
    $own = Department::factory()->create();
    $other = Department::factory()->create();
    $connection = Connection::factory()->operational()->create();
    $agent = User::factory()->agent()->inDepartments($own)->create();
    Conversation::factory()->for($connection, 'channelConnection')->inDepartment($own)->assignedTo($agent)->create();
    Conversation::factory()->for($connection, 'channelConnection')->inDepartment($own)->create();
    Conversation::factory()->for($connection, 'channelConnection')->inDepartment($other)->create();
    $admin = User::factory()->administrator()->create();
    $supervisor = User::factory()->supervisor()->inDepartments($own)->create();

    $this->actingAs($admin)->get(route('dashboard', ['period' => '7d', 'department' => $own->id, 'agent' => $agent->id]))
        ->assertInertia(fn (Assert $page) => $page
            ->where('filters', ['period' => '7d', 'department' => $own->id, 'agent' => $agent->id])
            ->where('period.preset', '7d')
            ->loadDeferredProps(fn (Assert $reload) => $reload
                ->where('overview.conversations.active', 1)
                ->where('overview.conversations.pending', 0)
                ->has('overview.trend', 7)));

    $this->actingAs($supervisor)->get(route('dashboard', ['department' => $other->id, 'period' => '90d']))
        ->assertInertia(fn (Assert $page) => $page
            ->where('filters', [])
            ->where('period.preset', '30d')
            ->loadDeferredProps(fn (Assert $reload) => $reload->where('overview.conversations.pending', 1)));
});

test('the average first response comes from human replies accepted by the provider', function () {
    $department = Department::factory()->create();
    $agent = User::factory()->agent()->inDepartments($department)->create();
    $connection = Connection::factory()->operational()->create();
    $conversation = Conversation::factory()->for($connection, 'channelConnection')->inDepartment($department)->assignedTo($agent)->create(['created_at' => now()->subMinutes(10)]);
    Message::factory()->for($conversation)->create([
        'direction' => MessageDirection::Outbound,
        'status' => MessageStatus::Sent,
        'sender_user_id' => $agent->id,
        'created_at' => now()->subMinutes(7),
    ]);
    app(RebuildConversationMetrics::class)->handle();

    $this->actingAs($agent)->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page->loadDeferredProps(fn (Assert $reload) => $reload
            ->where('overview.average_first_response_seconds', 180)
            ->where('overview.first_response_samples', 1)
            ->where('overview.received.value', 1)));
});

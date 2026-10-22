<?php

use App\Enums\DataScope;
use App\Enums\Permission;
use App\Models\Connection;
use App\Models\Conversation;
use App\Models\Department;
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
                ->where('overview.agents.available', null)
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

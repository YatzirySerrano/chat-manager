<?php

use App\Models\Conversation;
use App\Models\Department;
use App\Models\User;
use App\Models\WhatsappAccount;
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
    $admin = User::factory()->administrator()->create();
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
    $account = WhatsappAccount::factory()->create();
    Conversation::factory()->for($account)->inDepartment($department)->assignedTo($agent)->create();
    Conversation::factory()->for($account)->inDepartment($department)->create();
    Conversation::factory()->for($account)->assignedTo($agent)->closed()->create(['closed_at' => now()]);

    $this->actingAs($agent)
        ->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page
            ->loadDeferredProps(fn (Assert $reload) => $reload
                ->where('overview.scope', 'own')
                ->where('overview.messaging_connected', true)
                ->where('overview.conversations.active', 1)
                ->where('overview.conversations.pending', 0)
                ->where('overview.conversations.closed_today', 1)
                ->where('overview.departments', null)
            )
        );
});

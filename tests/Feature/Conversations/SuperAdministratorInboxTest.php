<?php

use App\Models\Conversation;
use App\Models\Department;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

test('the Superadministrador sees every conversation, also the ones agents attend, and filters them by department', function () {
    $admin = User::factory()->superAdministrator()->create();
    $agent = User::factory()->create();
    [$sales, $support] = Department::factory()->count(2)->create();
    $attended = Conversation::factory()->inDepartment($sales)->assignedTo($agent)->create();
    $other = Conversation::factory()->inDepartment($support)->assignedTo($agent)->create();
    $closed = Conversation::factory()->inDepartment($sales)->closed()->create();
    $unrouted = Conversation::factory()->create(['department_id' => null]);

    $ids = fn (array $query): array => collect($this->actingAs($admin)->get(route('conversations.index', $query))
        ->viewData('page')['props']['conversations']['data'] ?? [])->pluck('id')->sort()->values()->all();

    $this->actingAs($admin)->get(route('conversations.index'))
        ->assertInertia(fn (Assert $page) => $page->where('filters.view', 'all'));

    expect($ids(['status' => 'all']))->toEqualCanonicalizing([$attended->id, $other->id, $closed->id, $unrouted->id])
        ->and($ids(['status' => 'all', 'department' => (string) $sales->id]))->toEqualCanonicalizing([$attended->id, $closed->id])
        ->and($ids(['status' => 'all', 'department' => 'none']))->toEqualCanonicalizing([$unrouted->id]);
});

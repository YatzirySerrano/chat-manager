<?php

use App\Enums\AgentAvailability;
use App\Enums\DataScope;
use App\Enums\Permission;
use App\Models\Department;
use App\Models\Role;
use App\Models\User;
use App\Services\WebChat\WebChatAvailability;
use Tests\Support\WebChatFixtures;

/*
| Whether the widget shows the team online (DESIGN decision 2): the
| connection operates, the schedule allows it and the default department has
| at least one active member who could attend. No manual availability nor
| presence is involved.
*/

beforeEach(function () {
    $this->department = Department::factory()->create();
    $this->connection = WebChatFixtures::connection(['offline_text' => 'Volvemos pronto.'], ['default_department_id' => $this->department->id]);
    $this->availability = fn (): array => app(WebChatAvailability::class)->for($this->connection->refresh()->webChatWidget()->firstOrFail());
});

test('without anyone who can attend the default department the widget is offline with its offline text', function () {
    expect(($this->availability)())->toBe(['online' => false, 'message' => 'Volvemos pronto.']);
});

test('an active member able to attend puts the widget online, whatever their legacy availability', function () {
    User::factory()->agent()->inDepartments($this->department)->create(['availability' => AgentAvailability::Unavailable]);

    expect(($this->availability)())->toBe(['online' => true, 'message' => null]);
});

test('members who cannot attend do not count', function (Closure $makeMember) {
    $makeMember($this);

    expect(($this->availability)()['online'])->toBeFalse();
})->with([
    'deleted account' => [fn ($test) => User::factory()->agent()->inactive()->inDepartments($test->department)->create()],
    'inactive role' => [fn ($test) => User::factory()->withRole(Role::factory()->inactive()->withPermissions([Permission::ViewConversations, Permission::ReplyConversations])->create())->inDepartments($test->department)->create()],
    'cannot reply' => [fn ($test) => conversationUser([Permission::ViewConversations], DataScope::Departments, [$test->department])],
    'member of another department' => [fn ($test) => User::factory()->agent()->inDepartments(Department::factory()->create())->create()],
]);

test('an inactive default department or a paused connection is offline even with members', function (Closure $change) {
    User::factory()->agent()->inDepartments($this->department)->create();
    $change($this);

    expect(($this->availability)()['online'])->toBeFalse();
})->with([
    'inactive department' => [fn ($test) => $test->department->forceFill(['is_active' => false])->save()],
    'paused connection' => [fn ($test) => $test->connection->forceFill(['is_active' => false])->save()],
]);

test('without a default department only someone with global reach can attend', function () {
    $this->connection->forceFill(['default_department_id' => null])->save();
    User::factory()->agent()->inDepartments($this->department)->create();

    expect(($this->availability)()['online'])->toBeFalse();

    User::factory()->administrator()->create();

    expect(($this->availability)()['online'])->toBeTrue();
});

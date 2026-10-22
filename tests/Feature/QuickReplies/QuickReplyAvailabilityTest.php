<?php

use App\Models\Department;
use App\Models\QuickReply;
use App\Models\User;

test('members can use active global replies and those shared with their departments', function () {
    [$sales, $support] = Department::factory()->count(2)->create();
    $agent = User::factory()->agent()->inDepartments($support)->create();
    $global = QuickReply::factory()->create();
    $shared = QuickReply::factory()->forDepartments($support, $sales)->create();
    QuickReply::factory()->forDepartments($sales)->create();
    QuickReply::factory()->inactive()->create();
    QuickReply::factory()->inactive()->forDepartments($support)->create();

    expect(QuickReply::query()->usableBy($agent)->pluck('id')->all())->toEqualCanonicalizing([$global->id, $shared->id])
        ->and(QuickReply::query()->visibleTo($agent)->count())->toBe(4);
});

test('global users can use every active reply because they attend every department', function () {
    $admin = User::factory()->superAdministrator()->create();
    QuickReply::factory()->create();
    QuickReply::factory()->forDepartments(Department::factory()->create())->create();
    QuickReply::factory()->inactive()->create();

    expect(QuickReply::query()->usableBy($admin)->count())->toBe(2)
        ->and(QuickReply::query()->visibleTo($admin)->count())->toBe(3);
});

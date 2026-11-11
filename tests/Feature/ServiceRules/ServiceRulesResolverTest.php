<?php

use App\Actions\Departments\UpdateDepartmentServiceRules;
use App\Models\Conversation;
use App\Models\ConversationCategory;
use App\Models\Department;
use App\Models\User;
use App\Services\PlatformSettings;
use App\Services\ServiceRules;
use Illuminate\Support\Facades\DB;

/*
| Hierarchy of the service rules: category > department > platform, with the
| level each value comes from.
*/

test('without any configuration every rule comes from the platform defaults', function () {
    $rules = app(ServiceRules::class)->resolve(null, null);

    expect($rules->firstResponseSeconds())->toBeNull()
        ->and($rules->responseSeconds())->toBeNull()
        ->and($rules->resolutionSeconds())->toBeNull()
        ->and($rules->warningPercent())->toBe(80)
        ->and($rules->businessHoursOnly())->toBeTrue()
        ->and($rules->confirmationRequired())->toBeFalse()
        ->and($rules->minWaitSeconds())->toBe(0)
        ->and($rules->chronologicalOrder())->toBeFalse()
        ->and(array_unique(array_column($rules->toArray(), 'source')))->toBe(['platform'])
        ->and(array_keys($rules->toArray()))->toBe(array_keys(ServiceRules::KEYS));
});

test('a category overrides its department, which overrides the platform', function () {
    app(PlatformSettings::class)->update([
        'sla' => ['first_response_minutes' => 30, 'response_minutes' => 20, 'resolution_minutes' => 600],
        'queue' => ['min_wait_minutes' => 5],
    ]);
    $department = Department::factory()->create(['service_rules' => [
        'sla.first_response_minutes' => 15,
        'sla.response_minutes' => 10,
        'intake.confirmation_required' => true,
    ]]);
    $category = ConversationCategory::factory()->withServiceRules([
        'sla.first_response_minutes' => 5,
        'intake.confirmation_required' => false,
    ])->create();

    $rules = app(ServiceRules::class)->resolve($department, $category);

    expect($rules->firstResponseSeconds())->toBe(300)
        ->and($rules->source('sla.first_response_minutes'))->toBe('category')
        ->and($rules->responseSeconds())->toBe(600)
        ->and($rules->source('sla.response_minutes'))->toBe('department')
        ->and($rules->resolutionSeconds())->toBe(36000)
        ->and($rules->source('sla.resolution_minutes'))->toBe('platform')
        ->and($rules->confirmationRequired())->toBeFalse()
        ->and($rules->source('intake.confirmation_required'))->toBe('category')
        ->and($rules->minWaitSeconds())->toBe(300)
        ->and($rules->source('queue.min_wait_minutes'))->toBe('platform');

    $departmentOnly = app(ServiceRules::class)->resolve($department, null);

    expect($departmentOnly->firstResponseSeconds())->toBe(900)
        ->and($departmentOnly->confirmationRequired())->toBeTrue()
        ->and($departmentOnly->source('intake.confirmation_required'))->toBe('department');
});

test('stored overrides that the level may not set, or that are invalid, are ignored', function () {
    $department = Department::factory()->create(['service_rules' => [
        'sla.business_hours_only' => false,
        'queue.chronological_order' => true,
        'sla.warning_percent' => 10,
        'queue.min_wait_minutes' => 'pronto',
        'unknown.rule' => 1,
    ]]);
    $category = ConversationCategory::factory()->withServiceRules([
        'sla.business_hours_only' => true,
        'queue.chronological_order' => false,
        'sla.first_response_minutes' => 0,
    ])->create();

    $rules = app(ServiceRules::class)->resolve($department, $category);

    // Only the department may decide these two: the category values are ignored.
    expect($rules->businessHoursOnly())->toBeFalse()
        ->and($rules->source('sla.business_hours_only'))->toBe('department')
        ->and($rules->chronologicalOrder())->toBeTrue()
        ->and($rules->source('queue.chronological_order'))->toBe('department')
        // Out of range or of the wrong type: they inherit.
        ->and($rules->warningPercent())->toBe(80)
        ->and($rules->minWaitSeconds())->toBe(0)
        ->and($rules->firstResponseSeconds())->toBeNull()
        ->and($rules->toArray())->not->toHaveKey('unknown.rule');
});

test('the rules of a deleted category stop applying', function () {
    $category = ConversationCategory::factory()->inactive()->withServiceRules(['queue.min_wait_minutes' => 30])->create();
    $conversation = Conversation::factory()->create(['category_id' => $category->id]);

    expect(app(ServiceRules::class)->resolve(null, $category)->minWaitSeconds())->toBe(0)
        ->and(app(ServiceRules::class)->forConversation($conversation)->minWaitSeconds())->toBe(0);
});

test('the rules of a page of conversations are resolved without a query per row', function () {
    $departments = Department::factory()->count(3)->create(['service_rules' => ['queue.min_wait_minutes' => 7]]);
    $categories = ConversationCategory::factory()->count(3)->withServiceRules(['queue.min_wait_minutes' => 9])->create();
    $conversations = collect(range(0, 11))->map(fn (int $index) => Conversation::factory()->create([
        'department_id' => $departments[$index % 3]->id,
        'category_id' => $index % 2 === 0 ? $categories[$index % 3]->id : null,
    ]));
    $rules = app(ServiceRules::class);
    $rules->flush();
    app(PlatformSettings::class)->all();

    DB::enableQueryLog();
    $waits = $conversations->map(fn (Conversation $conversation): int => $rules->forConversation($conversation)->minWaitSeconds());
    $queries = count(DB::getQueryLog());
    DB::disableQueryLog();

    expect($queries)->toBeLessThanOrEqual(2)
        ->and($waits->unique()->sort()->values()->all())->toBe([420, 540]);
});

test('a change of rules applies to the next decision of the same request', function () {
    $admin = User::factory()->superAdministrator()->create();
    $department = Department::factory()->create();
    $conversation = Conversation::factory()->inDepartment($department)->create();
    $rules = app(ServiceRules::class);

    expect($rules->forConversation($conversation)->minWaitSeconds())->toBe(0);

    app(UpdateDepartmentServiceRules::class)->handle($department, ['queue.min_wait_minutes' => 3], $admin);

    expect($rules->forConversation($conversation)->minWaitSeconds())->toBe(180);

    app(PlatformSettings::class)->update(['sla' => ['warning_percent' => 60]]);

    expect($rules->forConversation($conversation)->warningPercent())->toBe(60);
});

test('the description of a level tells the own value, the inherited one and where each comes from', function () {
    app(PlatformSettings::class)->update(['sla' => ['first_response_minutes' => 30]]);
    $department = Department::factory()->create(['service_rules' => ['sla.first_response_minutes' => 15]]);
    $category = ConversationCategory::factory()->withServiceRules(['queue.min_wait_minutes' => 2])->create();
    $rules = app(ServiceRules::class);

    $platform = $rules->describe(null, null);
    $ofDepartment = $rules->describe($department, null);
    $ofCategory = $rules->describe($department, $category);

    expect($platform['sla.first_response_minutes'])->toMatchArray([
        'value' => 30, 'source' => 'platform', 'inherited_value' => null, 'inherited_source' => null,
        'unit' => 'minutos', 'min' => 1, 'max' => 43200, 'overridable' => true,
    ])
        ->and($ofDepartment['sla.first_response_minutes'])->toMatchArray([
            'value' => 15, 'source' => 'department', 'inherited_value' => 30, 'inherited_source' => 'platform',
        ])
        ->and($ofDepartment['sla.warning_percent'])->toMatchArray([
            'value' => 80, 'source' => 'platform', 'inherited_value' => 80, 'inherited_source' => 'platform',
        ])
        ->and($ofCategory['sla.first_response_minutes'])->toMatchArray([
            'value' => 15, 'source' => 'department', 'inherited_value' => 15, 'inherited_source' => 'department',
        ])
        ->and($ofCategory['queue.min_wait_minutes'])->toMatchArray([
            'value' => 2, 'source' => 'category', 'inherited_value' => 0, 'inherited_source' => 'platform',
        ])
        ->and($ofCategory['queue.chronological_order']['overridable'])->toBeFalse()
        ->and($ofDepartment['queue.chronological_order']['overridable'])->toBeTrue();
});

test('the validation rules of a level only accept the rules it may override', function (string $level, array $input, bool $passes) {
    $validator = validator(['rules' => $input], app(ServiceRules::class)->validationRules($level, 'rules.'));

    expect($validator->passes())->toBe($passes);
})->with([
    'department: every overridable rule' => ['department', ['sla' => ['first_response_minutes' => 1, 'resolution_minutes' => 525600, 'warning_percent' => 95, 'business_hours_only' => false], 'intake' => ['confirmation_required' => true], 'queue' => ['min_wait_minutes' => 1440, 'chronological_order' => true]], true],
    'department: nulls inherit' => ['department', ['sla' => ['first_response_minutes' => null], 'queue' => null], true],
    'category: its overridable rules' => ['category', ['sla' => ['response_minutes' => 43200, 'warning_percent' => 50], 'queue' => ['min_wait_minutes' => 0]], true],
    'category: business hours are decided by the department' => ['category', ['sla' => ['business_hours_only' => false]], false],
    'category: the order is decided by the department' => ['category', ['queue' => ['chronological_order' => true]], false],
    'department: unknown rule' => ['department', ['sla' => ['made_up' => 1]], false],
    'department: unknown group' => ['department', ['sla_alerts' => ['agent_warning' => false]], false],
    'first response below the minimum' => ['department', ['sla' => ['first_response_minutes' => 0]], false],
    'first response above the maximum' => ['department', ['sla' => ['first_response_minutes' => 43201]], false],
    'resolution above the maximum' => ['category', ['sla' => ['resolution_minutes' => 525601]], false],
    'warning below the minimum' => ['department', ['sla' => ['warning_percent' => 49]], false],
    'warning above the maximum' => ['category', ['sla' => ['warning_percent' => 96]], false],
    'wait above the maximum' => ['department', ['queue' => ['min_wait_minutes' => 1441]], false],
    'wait is not a number' => ['category', ['queue' => ['min_wait_minutes' => 'mucho']], false],
    'confirmation is not a boolean' => ['department', ['intake' => ['confirmation_required' => 'tal vez']], false],
]);

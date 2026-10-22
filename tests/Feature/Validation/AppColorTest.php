<?php

use App\Enums\AuditAction;
use App\Enums\ColorSwatch;
use App\Models\AuditLog;
use App\Models\Department;
use App\Models\Tag;
use App\Models\User;
use App\Support\AppColor;
use Inertia\Testing\AssertableInertia as Assert;

test('tags accept palette keys and normalized hex colors', function (string $input, string $stored) {
    $admin = User::factory()->superAdministrator()->create();

    $this->actingAs($admin)
        ->post(route('tags.store'), ['name' => 'VIP', 'color' => $input])
        ->assertSessionHasNoErrors();

    expect(Tag::query()->sole()->color->value)->toBe($stored);
})->with([
    'palette key' => ['emerald', 'emerald'],
    'lowercase hex' => ['#1a2b3c', '#1a2b3c'],
    'uppercase hex' => ['#1A2B3C', '#1a2b3c'],
]);

test('tags reject colors that are neither palette keys nor six digit hex', function (mixed $color) {
    $admin = User::factory()->superAdministrator()->create();

    $this->actingAs($admin)
        ->post(route('tags.store'), ['name' => 'VIP', 'color' => $color])
        ->assertSessionHasErrors([
            'color' => 'El color no es válido. Elige un color de la paleta o un color personalizado con el formato #rrggbb.',
        ]);

    expect(Tag::query()->count())->toBe(0);
})->with([
    'color name' => ['red'],
    'shorthand hex' => ['#fff'],
    'css url' => ['url(x)'],
    'non hex digit' => ['#12345g'],
    'hex without hash' => ['1a2b3c'],
    'hex with alpha' => ['#1a2b3c4d'],
    'rgb function' => ['rgb(0, 0, 0)'],
    'capitalized palette key' => ['Blue'],
    'array' => [['#1a2b3c']],
]);

test('departments reject invalid colors', function () {
    $admin = User::factory()->superAdministrator()->create();

    $this->actingAs($admin)
        ->post(route('departments.store'), ['name' => 'Ventas', 'color' => '#fff'])
        ->assertSessionHasErrors('color');

    expect(Department::query()->count())->toBe(0);
});

test('administrators can create a department with a custom color', function () {
    $admin = User::factory()->superAdministrator()->create();

    $this->actingAs($admin)
        ->post(route('departments.store'), ['name' => 'Ventas', 'color' => '#FF8800'])
        ->assertSessionHasNoErrors();

    $department = Department::query()->sole();

    expect($department->color->value)->toBe('#ff8800')
        ->and($department->color->isCustom())->toBeTrue();
});

test('updating a department to a custom color is audited with the normalized value', function () {
    $admin = User::factory()->superAdministrator()->create();
    $department = Department::factory()->create(['name' => 'Ventas', 'color' => ColorSwatch::Blue]);

    $this->actingAs($admin)
        ->put(route('departments.update', $department), ['name' => 'Ventas', 'color' => '#00AA55'])
        ->assertSessionHasNoErrors();

    expect($department->refresh()->color->value)->toBe('#00aa55');

    expect(AuditLog::query()->where('action', AuditAction::DepartmentUpdated)->sole()->properties['changes']['color'])
        ->toBe(['old' => 'blue', 'new' => '#00aa55']);
});

test('a custom department color can be changed back to a palette key', function () {
    $admin = User::factory()->superAdministrator()->create();
    $department = Department::factory()->create(['name' => 'Ventas', 'color' => '#00aa55']);

    $this->actingAs($admin)
        ->put(route('departments.update', $department), ['name' => 'Ventas', 'color' => 'violet'])
        ->assertSessionHasNoErrors();

    expect($department->refresh()->color->swatch())->toBe(ColorSwatch::Violet);
});

test('updating a tag to a custom color is audited with the normalized value', function () {
    $admin = User::factory()->superAdministrator()->create();
    $tag = Tag::factory()->create(['name' => 'VIP', 'color' => 'rose']);

    $this->actingAs($admin)
        ->put(route('tags.update', $tag), ['name' => 'VIP', 'color' => '#ABCDEF'])
        ->assertSessionHasNoErrors();

    expect($tag->refresh()->color->value)->toBe('#abcdef');

    expect(AuditLog::query()->where('action', AuditAction::TagUpdated)->sole()->properties['changes']['color'])
        ->toBe(['old' => 'rose', 'new' => '#abcdef']);
});

test('resources expose the stored color string', function () {
    $admin = User::factory()->superAdministrator()->create();
    Tag::factory()->create(['name' => 'VIP', 'color' => '#abcdef']);

    $this->actingAs($admin)
        ->get(route('tags.index'))
        ->assertInertia(fn (Assert $page) => $page->where('tags.data.0.color', '#abcdef'));
});

test('models refuse to store an invalid color', function () {
    expect(fn () => Tag::factory()->create(['color' => 'red']))
        ->toThrow(InvalidArgumentException::class);

    expect(AppColor::tryFrom('#ABCDEF')?->value)->toBe('#abcdef');
});

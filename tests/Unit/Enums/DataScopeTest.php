<?php

use App\Enums\DataScope;

test('the three scopes keep their names and explain which information they reach', function (DataScope $scope, string $label) {
    expect($scope->label())->toBe($label)
        ->and($scope->description())->not->toContain('Actúa')
        ->and($scope->example())->not->toBe('')
        ->and($scope->icon())->toMatch('/^[a-z0-9]+(-[a-z0-9]+)*$/');
})->with([
    'global' => [DataScope::Global, 'Toda la organización'],
    'departments' => [DataScope::Departments, 'Sus departamentos'],
    'assigned' => [DataScope::Assigned, 'Solo lo asignado'],
]);

test('the example of the assigned scope matches its visibility: own conversations plus the pending ones it may accept', function () {
    expect(DataScope::Assigned->example())
        ->toContain('atiende')
        ->toContain('pendientes');
});

test('the options carry the example and the icon of each scope', function () {
    expect(DataScope::options())->each->toHaveKeys(['value', 'label', 'description', 'example', 'icon']);
});

<?php

use App\Enums\AuditAction;
use App\Enums\ConversationStatus;
use App\Enums\DataScope;
use App\Enums\InboundMessageOutcome;
use App\Enums\Permission;
use App\Models\AuditLog;
use App\Models\Connection;
use App\Models\Contact;
use App\Models\Department;
use App\Models\Role;
use App\Models\User;
use App\Services\Conversations\ConversationRouter;
use Inertia\Testing\AssertableInertia as Assert;

/*
| «Departamento» on a contact: its new conversations arrive there whenever
| the department is active and the connection may route to it.
*/

/**
 * A known contact (it wrote once through the connection) whose first
 * conversation is already closed for good, so its next message opens a new one.
 */
function knownContactOn(Connection $connection, ?Department $department): Contact
{
    $first = receiveWhatsapp($connection);
    $first->conversation->forceFill(['status' => ConversationStatus::Closed, 'closed_at' => now()->subHour()])->save();

    $contact = $first->registration->contact;
    $contact->forceFill(['department_id' => $department?->id])->save();

    return $contact;
}

function contactDepartmentEditor(DataScope $scope = DataScope::Global): User
{
    $role = Role::factory()->scope($scope)
        ->withPermissions([Permission::ViewContacts, Permission::CreateContacts, Permission::UpdateContacts])
        ->create();

    return User::factory()->withRole($role)->create();
}

test('a new conversation of the contact arrives at the contact department first', function () {
    $connectionDepartment = Department::factory()->create();
    $contactDepartment = Department::factory()->create();
    $connection = Connection::factory()->create(['default_department_id' => $connectionDepartment->id, 'reopen_window_minutes' => null]);
    knownContactOn($connection, $contactDepartment);

    $receipt = receiveWhatsapp($connection);

    expect($receipt->outcome)->toBe(InboundMessageOutcome::Created)
        ->and($receipt->conversation->department_id)->toBe($contactDepartment->id)
        ->and($receipt->conversation->events()->sole()->metadata)->toBe(['source' => 'inbound', 'routed_by' => 'contact']);
});

test('the contact department counts when it is among the connection authorized departments', function () {
    $contactDepartment = Department::factory()->create();
    $connection = Connection::factory()->create();
    $connection->departments()->attach([$contactDepartment->id, Department::factory()->create()->id]);
    $contact = Contact::factory()->create(['department_id' => $contactDepartment->id]);

    $route = app(ConversationRouter::class)->route($connection, $contact);

    expect($route['department']?->id)->toBe($contactDepartment->id)
        ->and($route['routed_by'])->toBe('contact');
});

test('an inactive or unauthorized contact department falls back to the usual chain', function (Closure $setup, string $routedBy) {
    $connectionDepartment = Department::factory()->create();
    $connection = Connection::factory()->create(['default_department_id' => $connectionDepartment->id, 'reopen_window_minutes' => null]);
    $contactDepartment = $setup($connection, $connectionDepartment);
    knownContactOn($connection, $contactDepartment);

    $receipt = receiveWhatsapp($connection);

    expect($receipt->conversation->department_id)->toBe($connectionDepartment->id)
        ->and($receipt->conversation->events()->sole()->metadata['routed_by'])->toBe($routedBy);
})->with([
    'inactive department' => [fn (Connection $connection) => Department::factory()->inactive()->create(), 'connection_default'],
    'not authorized for the connection' => [function (Connection $connection, Department $connectionDepartment) {
        $connection->departments()->attach($connectionDepartment->id);

        return Department::factory()->create();
    }, 'connection_default'],
    'no department chosen' => [fn () => null, 'connection_default'],
]);

test('an appended conversation keeps its department when the contact department changes', function () {
    $connectionDepartment = Department::factory()->create();
    $connection = Connection::factory()->create(['default_department_id' => $connectionDepartment->id]);
    $first = receiveWhatsapp($connection);
    $first->registration->contact->forceFill(['department_id' => Department::factory()->create()->id])->save();

    $next = receiveWhatsapp($connection);

    expect($next->outcome)->toBe(InboundMessageOutcome::Appended)
        ->and($next->conversation->department_id)->toBe($connectionDepartment->id);
});

test('creating a contact saves its department', function () {
    $department = Department::factory()->create();

    $this->actingAs(contactDepartmentEditor())
        ->post(route('contacts.store'), ['name' => 'Lucía', 'phone' => '5512345678', 'department_id' => $department->id])
        ->assertSessionHasNoErrors();

    expect(Contact::query()->sole()->department_id)->toBe($department->id)
        ->and(AuditLog::query()->where('action', AuditAction::ContactCreated)->sole()->properties['department_id'])->toBe($department->id);
});

test('editing a contact saves, changes and clears its department', function () {
    $editor = contactDepartmentEditor();
    $contact = Contact::factory()->create(['name' => 'Juan', 'phone_e164' => '+525512345678']);
    $sales = Department::factory()->create();

    $this->actingAs($editor)
        ->put(route('contacts.update', $contact), ['name' => 'Juan', 'phone' => '+525512345678', 'department_id' => $sales->id])
        ->assertSessionHasNoErrors();

    expect($contact->refresh()->department_id)->toBe($sales->id)
        ->and(AuditLog::query()->where('action', AuditAction::ContactUpdated)->sole()->properties['changes']['department_id'])
        ->toBe(['old' => null, 'new' => $sales->id]);

    $this->actingAs($editor)
        ->put(route('contacts.update', $contact), ['name' => 'Juan', 'phone' => '+525512345678', 'department_id' => null])
        ->assertSessionHasNoErrors();

    expect($contact->refresh()->department_id)->toBeNull();
});

test('a form that does not send the department keeps it', function () {
    $department = Department::factory()->create();
    $contact = Contact::factory()->create(['name' => 'Juan', 'phone_e164' => '+525512345678', 'department_id' => $department->id]);

    $this->actingAs(contactDepartmentEditor())
        ->put(route('contacts.update', $contact), ['name' => 'Juan Pérez', 'phone' => '+525512345678'])
        ->assertSessionHasNoErrors();

    expect($contact->refresh())->name->toBe('Juan Pérez')->department_id->toBe($department->id);
});

test('the department must exist, be active and be within the actor scope', function (Closure $department) {
    $editor = contactDepartmentEditor(DataScope::Departments);
    $own = Department::factory()->create();
    $editor->departments()->attach($own->id);

    $this->actingAs($editor)
        ->post(route('contacts.store'), ['name' => 'Lucía', 'phone' => '5512345678', 'department_id' => $department()])
        ->assertSessionHasErrors(['department_id' => 'Elige un departamento activo de la lista.']);

    expect(Contact::query()->count())->toBe(0);
})->with([
    'unknown id' => [fn () => 999999],
    'inactive' => [fn () => Department::factory()->inactive()->create()->id],
    'outside the scope' => [fn () => Department::factory()->create()->id],
]);

test('saving other changes keeps a department that is no longer selectable', function () {
    $inactive = Department::factory()->inactive()->create();
    $contact = Contact::factory()->create(['name' => 'Juan', 'phone_e164' => '+525512345678', 'department_id' => $inactive->id]);

    $this->actingAs(contactDepartmentEditor())
        ->put(route('contacts.update', $contact), ['name' => 'Juan Pérez', 'phone' => '+525512345678', 'department_id' => $inactive->id])
        ->assertSessionHasNoErrors();

    expect($contact->refresh())->name->toBe('Juan Pérez')->department_id->toBe($inactive->id);
});

test('the contact pages offer the active departments and show the chosen one', function () {
    $sales = Department::factory()->create(['name' => 'Ventas']);
    Department::factory()->inactive()->create(['name' => 'Archivo']);
    $contact = Contact::factory()->create(['department_id' => $sales->id]);
    $editor = contactDepartmentEditor();

    $this->actingAs($editor)
        ->get(route('contacts.show', $contact))
        ->assertInertia(fn (Assert $page) => $page
            ->where('contact.department_id', $sales->id)
            ->where('contact.department.name', 'Ventas')
            ->where('departments', fn ($departments) => collect($departments)->pluck('name')->all() === ['Ventas'])
        );

    $this->actingAs($editor)
        ->get(route('contacts.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('contacts.data.0.department.name', 'Ventas')
            ->has('departments', 1)
        );
});

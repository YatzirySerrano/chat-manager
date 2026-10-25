<?php

use App\Enums\AuditAction;
use App\Enums\DataScope;
use App\Enums\Permission;
use App\Models\AuditLog;
use App\Models\Connection;
use App\Models\Role;
use App\Models\User;
use App\Reports\Definitions\AuditReport;
use App\Reports\ReportContext;
use App\Services\AuditLogger;
use App\Support\Audit\AuditPresenter;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * An entry stored as-is (bypassing the logger), like older rows.
 *
 * @param  array<string, mixed>  $properties
 */
function storedAuditEntry(AuditAction $action, array $properties, array $attributes = []): AuditLog
{
    return AuditLog::query()->create([
        'actor_name' => 'Ana López',
        'action' => $action,
        'properties' => $properties,
        'restricted' => false,
        'created_at' => CarbonImmutable::parse('2026-10-24 18:30:00', 'UTC'),
        ...$attributes,
    ]);
}

function auditViewer(array $permissions): User
{
    return User::factory()->withRole(Role::factory()->scope(DataScope::Global)->withPermissions($permissions)->create())->create();
}

test('a credentials verification reads as a plain sentence with translated Meta values', function () {
    $connection = Connection::factory()->create(['name' => 'Ventas', 'display_phone_number' => '+52 55 1234 5678']);
    $log = app(AuditLogger::class)->record(AuditAction::ConnectionCredentialsVerified, $connection, [
        'verification_result' => 'verified',
        'verified_name' => 'Tienda Centro',
        'phone_status' => 'CONNECTED',
        'quality_rating' => 'GREEN',
    ], User::factory()->administrator()->create(['name' => 'Ana López']));

    $presentation = app(AuditPresenter::class)->present($log);

    expect($presentation['summary'])->toStartWith('Credenciales de la conexión verificadas: Conexión «Ventas (+52 55 1234 5678)». Lo realizó Ana López el ')
        ->and($presentation['subject'])->toMatchArray(['type' => 'connection', 'type_label' => 'Conexión', 'label' => 'Ventas (+52 55 1234 5678)'])
        ->and($presentation['result'])->toBe(['tone' => 'success', 'label' => 'Correcto'])
        ->and($presentation['details'])->toBe([
            ['key' => 'verified_name', 'label' => 'Nombre verificado por Meta', 'value' => 'Tienda Centro'],
            ['key' => 'phone_status', 'label' => 'Estado del número', 'value' => 'Conectado'],
            ['key' => 'quality_rating', 'label' => 'Calidad del número', 'value' => 'Alta'],
        ])
        ->and($presentation['technical'])->toBeNull();
});

test('a rejected verification shows the reason without Meta technical text, which stays in the technical section', function () {
    $log = storedAuditEntry(AuditAction::ConnectionCredentialsFailed, [
        'verification_result' => 'rejected',
        'meta_code' => 190,
        'http_status' => 401,
        'reason' => 'El token de acceso es inválido o expiró. (código 190) Detalle de Meta: Invalid OAuth access token.',
    ]);

    $presentation = app(AuditPresenter::class)->present($log, auditViewer([Permission::ViewAuditLog, Permission::ViewSystem]));

    expect($presentation['result'])->toBe(['tone' => 'danger', 'label' => 'Rechazado'])
        ->and($presentation['error'])->toBe('El token de acceso es inválido o expiró.')
        ->and(collect($presentation['details'])->pluck('key')->all())->toBe(['meta_code', 'http_status'])
        ->and($presentation['technical']['error'])->toBe('Código 190 · Invalid OAuth access token.');
});

test('changes are shown before and after whatever shape was stored', function () {
    $log = storedAuditEntry(AuditAction::WebChatWidgetUpdated, ['changes' => [
        'title' => ['old' => 'Hola', 'new' => 'Bienvenido'],
        'allowed_origins' => ['added' => ['https://nuevo.mx'], 'removed' => ['https://viejo.mx']],
        'avatar' => 'removed',
        'launcher_enabled' => ['old' => false, 'new' => true],
    ]]);

    expect(app(AuditPresenter::class)->present($log)['changes'])->toBe([
        ['field' => 'title', 'label' => 'Título', 'before' => 'Hola', 'after' => 'Bienvenido'],
        ['field' => 'allowed_origins', 'label' => 'Sitios permitidos', 'before' => 'Se retiró: https://viejo.mx', 'after' => 'Se agregó: https://nuevo.mx'],
        ['field' => 'avatar', 'label' => 'Imagen', 'before' => 'Tenía uno', 'after' => 'Eliminado'],
        ['field' => 'launcher_enabled', 'label' => 'Botón flotante activo', 'before' => 'No', 'after' => 'Sí'],
    ]);
});

test('top-level added and removed lists become one change with readable permission names', function () {
    $log = storedAuditEntry(AuditAction::RolePermissionsChanged, [
        'added' => [Permission::ViewAuditLog->value],
        'removed' => [],
        'users_affected' => 3,
    ]);

    $presentation = app(AuditPresenter::class)->present($log);

    expect($presentation['changes'])->toBe([
        ['field' => 'permissions', 'label' => 'Permisos', 'before' => '—', 'after' => 'Se agregó: '.Permission::ViewAuditLog->label()],
    ])->and($presentation['details'])->toBe([
        ['key' => 'users_affected', 'label' => 'Usuarios afectados', 'value' => '3'],
    ]);
});

test('dotted setting paths get readable labels and unknown keys a humanized fallback', function () {
    $log = storedAuditEntry(AuditAction::DepartmentHoursUpdated, [
        'changes' => ['business_hours.days.monday.start' => ['old' => '09:00', 'new' => '10:00']],
        'brand_new_flag' => true,
    ]);

    $presentation = app(AuditPresenter::class)->present($log);

    expect($presentation['changes'][0]['label'])->toBe('Horario de atención › Lunes › apertura')
        ->and($presentation['details'])->toBe([['key' => 'brand_new_flag', 'label' => 'Brand new flag', 'value' => 'Sí']]);
});

test('phase five entries read in Spanish: saved contacts, supervised personal items and retired on-hold', function () {
    $present = fn (AuditAction $action, array $properties): array => collect(app(AuditPresenter::class)->present(storedAuditEntry($action, $properties))['details'])
        ->mapWithKeys(fn (array $detail): array => [$detail['label'] => $detail['value']])
        ->all();

    expect($present(AuditAction::ContactSaved, ['via' => 'conversation', 'details_changed' => true]))
        ->toBe(['Guardado desde' => 'Desde la conversación', 'Datos corregidos al guardar' => 'Sí'])
        ->and($present(AuditAction::ContactSaved, ['via' => 'import']))->toBe(['Guardado desde' => 'Importación'])
        ->and($present(AuditAction::SupervisionPersonalItemDeleted, ['owner_user_id' => 7, 'item_type' => 'contact_alias', 'item_id' => 3]))->toBe(['Tipo de elemento' => 'Alias de contacto'])
        ->and($present(AuditAction::SupervisionPersonalItemUpdated, ['item_type' => 'quick_reply', 'title' => 'Saludo']))
        ->toBe(['Tipo de elemento' => 'Respuesta rápida personal', 'Título' => 'Saludo'])
        ->and($present(AuditAction::ConversationOnHoldRetired, ['previous_status' => 'on_hold', 'new_status' => 'pending']))
        ->toBe(['Estado anterior' => 'En espera (anterior)', 'Estado nuevo' => 'Pendiente'])
        ->and($present(AuditAction::MessageRetriedWithDuplicateRisk, ['previous_error' => 'sender_not_allowed']))
        ->toBe(['Error anterior' => 'Quien escribió ya no podía responder la conversación']);
});

test('secrets are never presented, not even from entries stored before redaction', function () {
    $log = storedAuditEntry(AuditAction::ConnectionUpdated, [
        'access_token' => 'EAAG-secret',
        'changes' => ['name' => ['old' => 'A', 'new' => 'B'], 'app_secret' => ['old' => 'x', 'new' => 'y']],
    ]);

    $presentation = app(AuditPresenter::class)->present($log, auditViewer([Permission::ViewAuditLog, Permission::ExportAuditLog]));

    expect(json_encode($presentation))->not->toContain('EAAG-secret')
        ->not->toContain('app_secret')
        ->and($presentation['technical']['properties'])->toBe(['changes' => ['name' => ['old' => 'A', 'new' => 'B']]]);
});

test('technical data only reaches viewers who operate the platform or export the audit', function (array $permissions, bool $sees) {
    app(AuditLogger::class)->record(AuditAction::SystemJobRetried, null, ['uuid' => 'abc-123', 'job' => 'App\\Jobs\\SendWebPush', 'queue' => 'default']);

    $this->actingAs(auditViewer($permissions))
        ->get(route('admin.audit.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->missing('logs.data.0.properties')
            ->where('logs.data.0.technical', fn ($technical) => $sees ? $technical['properties']['uuid'] === 'abc-123' : $technical === null)
            ->where('logs.data.0.details', fn ($details) => collect($details)->pluck('key')->contains('uuid') === $sees));
})->with([
    'audit.view only' => [[Permission::ViewAuditLog], false],
    'with system.view' => [[Permission::ViewAuditLog, Permission::ViewSystem], true],
    'with audit.export' => [[Permission::ViewAuditLog, Permission::ExportAuditLog], true],
]);

test('the subject links to its page only when the viewer may open it', function () {
    $connection = Connection::factory()->create();
    app(AuditLogger::class)->record(AuditAction::ConnectionActivated, $connection);

    $this->actingAs(User::factory()->superAdministrator()->create())
        ->get(route('admin.audit.index'))
        ->assertInertia(fn (Assert $page) => $page->where('logs.data.0.subject.url', route('connections.show', $connection)));

    $this->actingAs(auditViewer([Permission::ViewAuditLog]))
        ->get(route('admin.audit.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('logs.data.0.subject.url', null)
            ->where('logs.data.0.subject.type_label', 'Conexión'));
});

test('a role change reads as one before and after change', function () {
    $log = storedAuditEntry(AuditAction::UserRoleChanged, ['from' => 'Agente', 'to' => 'Supervisor', 'released_conversations' => 2]);

    $presentation = app(AuditPresenter::class)->present($log);

    expect($presentation['changes'])->toBe([['field' => 'role', 'label' => 'Rol', 'before' => 'Agente', 'after' => 'Supervisor']])
        ->and($presentation['details'])->toBe([['key' => 'released_conversations', 'label' => 'Conversaciones liberadas', 'value' => '2']]);
});

test('the audit export names the entity type and summarizes in plain Spanish', function () {
    $admin = User::factory()->superAdministrator()->create();
    $connection = Connection::factory()->create(['name' => 'Ventas', 'display_phone_number' => null]);
    $log = app(AuditLogger::class)->record(AuditAction::ConnectionCredentialsVerified, $connection, [
        'verification_result' => 'verified',
        'quality_rating' => 'YELLOW',
    ], $admin);
    $context = new ReportContext($admin, [], CarbonImmutable::now(), 'Chat Manager', 'America/Mexico_City');

    $row = app(AuditReport::class)->map($log, $context);

    expect($row['subject'])->toBe('Conexión: Ventas')
        ->and($row['details'])->toBe('Resultado: Correcto · Calidad del número: Media');
});

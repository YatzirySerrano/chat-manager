<?php

use App\Enums\DataScope;
use App\Enums\Permission;
use App\Models\AttentionAlert;
use App\Models\AttentionAlertRecipient;
use App\Models\Connection;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\ConversationCategory;
use App\Models\DataTransfer;
use App\Models\Department;
use App\Models\HistoryImportFile;
use App\Models\HistoryImportRule;
use App\Models\Role;
use App\Models\TeamChat\TeamMessage;
use App\Models\TeamChat\TeamThread;
use App\Models\User;
use App\Services\ServiceRules;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Feature\Templates\TemplateFixtures;

require_once __DIR__.'/../HistoryImport/HistoryImportSupport.php';

/*
| Companion of RouteAuthorizationSweepTest with real records. That sweep
| proves that a user WITHOUT the permission is refused; this file proves
| that a user who HOLDS the permission but whose departments scope covers
| only department «Quetzal» never reaches department «Zorzal»: lists and
| searches do not leak its rows (the in-scope «Quetzal» rows are asserted
| present so the absence is meaningful), records answer 403/404 and stay
| unchanged. Every out-of-scope fixture carries the word «Zorzal» and every
| in-scope one the word «Quetzal».
|
| Global by design (only the permission gate is asserted): request
| categories (categories.manage requires the global scope, see
| ConversationCategoryPolicy), the history importer and the archived
| history (history-import.* require the global scope and the importer is
| reserved to the root authority, see HistoryImportPolicy).
*/

beforeEach(function () {
    $this->zorzal = Department::factory()->create(['name' => 'Depto Zorzal']);
    $this->quetzal = Department::factory()->create(['name' => 'Depto Quetzal']);
    $this->root = User::factory()->superAdministrator()->create(['name' => 'Raíz Sweep']);
    $this->zorzalAgent = User::factory()->agent()->inDepartments($this->zorzal)->create(['name' => 'Agente Zorzal']);
    $this->quetzalAgent = User::factory()->agent()->inDepartments($this->quetzal)->create(['name' => 'Agente Quetzal']);

    $this->zorzalConnection = Connection::factory()->operational()->create(['name' => 'Línea Zorzal']);
    $this->zorzalConnection->departments()->attach($this->zorzal);
    $this->quetzalConnection = Connection::factory()->operational()->create(['name' => 'Línea Quetzal']);
    $this->quetzalConnection->departments()->attach($this->quetzal);

    // Both customers wrote last and wait for their agent's answer.
    $this->zorzalConversation = Conversation::factory()->inDepartment($this->zorzal)->assignedTo($this->zorzalAgent)->create([
        'connection_id' => $this->zorzalConnection->id,
        'contact_id' => Contact::factory()->create(['name' => 'Cliente Zorzal'])->id,
        'last_inbound_at' => now()->subMinutes(30),
    ]);
    $this->quetzalConversation = Conversation::factory()->inDepartment($this->quetzal)->assignedTo($this->quetzalAgent)->create([
        'connection_id' => $this->quetzalConnection->id,
        'contact_id' => Contact::factory()->create(['name' => 'Cliente Quetzal'])->id,
        'last_inbound_at' => now()->subMinutes(30),
    ]);

    // A supervisor-like role scoped to «Quetzal» holding every view permission swept here.
    $this->viewer = scopeSweepUser([
        Permission::ViewDashboard,
        Permission::ViewConversations,
        Permission::ReplyConversations,
        Permission::ViewContacts,
        Permission::ViewReports,
        Permission::ExportReports,
        Permission::ViewAttention,
        Permission::SendAttentionAlerts,
        Permission::ViewTemplates,
        Permission::UseTeamChat,
        Permission::UseCrossDepartmentTeamChat,
        Permission::ViewDepartments,
        Permission::UpdateDepartments,
    ], DataScope::Departments, [$this->quetzal]);
});

/**
 * @param  list<Permission>  $permissions
 * @param  list<Department>  $departments
 */
function scopeSweepUser(array $permissions, DataScope $scope, array $departments = []): User
{
    $role = Role::factory()->scope($scope)->withPermissions($permissions)->create();

    return User::factory()->withRole($role)->inDepartments($departments)->create(['name' => 'Supervisor Quetzal']);
}

/**
 * Every prop of an Inertia page as the viewer receives it, deferred props
 * included (fetched with a partial reload, as the client does), as JSON.
 */
function scopeSweepPage(User $viewer, string $url): string
{
    $page = test()->actingAs($viewer)->get($url)->assertOk()->viewData('page');
    $props = $page['props'];
    $deferred = collect($page['deferredProps'] ?? [])->flatten()->all();

    if ($deferred !== []) {
        $partial = test()->actingAs($viewer)->get($url, [
            'X-Inertia' => 'true',
            'X-Inertia-Version' => (string) ($page['version'] ?? ''),
            'X-Inertia-Partial-Component' => $page['component'],
            'X-Inertia-Partial-Data' => implode(',', $deferred),
        ])->assertOk();

        $props = [...$props, ...$partial->json('props')];
    }

    return json_encode($props, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
}

test('department-scoped lists never show rows of another department', function (string $route, array $query, string $inScope) {
    $payload = scopeSweepPage($this->viewer, route($route, $query));

    expect($payload)->toContain($inScope)->not->toContain('Zorzal');
})->with([
    // «Todas»: the general view leaves out what a colleague already attends.
    'conversations inbox' => ['conversations.index', ['view' => 'all'], 'Cliente Quetzal'],
    'contacts directory' => ['contacts.index', [], 'Cliente Quetzal'],
    'dashboard' => ['dashboard', [], 'Agente Quetzal'],
    'reports' => ['reports.index', [], 'Agente Quetzal'],
    'attention follow-up' => ['attention.index', [], 'Cliente Quetzal'],
]);

test('the templates list only shows templates of connections of the viewer departments', function () {
    TemplateFixtures::stored($this->zorzalConnection, ['name' => 'aviso_zorzal']);
    TemplateFixtures::stored($this->quetzalConnection, ['name' => 'aviso_quetzal']);

    expect(scopeSweepPage($this->viewer, route('templates.index')))->toContain('aviso_quetzal')->not->toContain('Zorzal')->not->toContain('zorzal');
});

test('the prepared files list and the recent exports only show the viewer own exports', function () {
    DataTransfer::factory()->completed()->create(['user_id' => $this->zorzalAgent->id, 'file_name' => 'etiquetas-zorzal.xlsx']);
    DataTransfer::factory()->completed()->create(['user_id' => $this->viewer->id, 'file_name' => 'etiquetas-quetzal.xlsx']);
    // Queued exports are listed as «recent» without a download check.
    $foreign = DataTransfer::factory()->create(['user_id' => $this->zorzalAgent->id]);
    $own = DataTransfer::factory()->create(['user_id' => $this->viewer->id]);

    expect(scopeSweepPage($this->viewer, route('exports.index')))->toContain('etiquetas-quetzal.xlsx')->not->toContain('zorzal');

    $recent = $this->actingAs($this->viewer)->getJson(route('exports.recent', ['report' => 'tags']))->assertOk();
    expect(collect($recent->json('data'))->pluck('id')->all())->toContain($own->id)->not->toContain($foreign->id);
});

test('the team chat search never matches threads the viewer does not take part in', function () {
    $foreign = TeamThread::factory()->withParticipants([$this->zorzalAgent], [$this->root])->create(['name' => 'Grupo Zorzal']);
    TeamMessage::factory()->in($foreign, $this->zorzalAgent)->create(['body' => 'Factura Zorzal pendiente']);
    $own = TeamThread::factory()->withParticipants([$this->viewer, $this->quetzalAgent])->create(['name' => 'Grupo Quetzal']);
    TeamMessage::factory()->in($own, $this->quetzalAgent)->create(['body' => 'Factura Quetzal pendiente']);

    $response = $this->actingAs($this->viewer)->getJson(route('team-chat.search', ['q' => 'Factura']))->assertOk();

    expect($response->getContent())->toContain('Factura Quetzal')->not->toContain('Zorzal');
});

test('an alert holder of another department neither consults nor targets what is outside their reach', function () {
    $foreignAlert = AttentionAlert::factory()->create(['sender_id' => $this->root->id, 'title' => 'Alerta Zorzal']);
    $ownAlert = AttentionAlert::factory()->create(['sender_id' => $this->viewer->id, 'title' => 'Alerta Quetzal']);

    expect(scopeSweepPage($this->viewer, route('attention.index')))->toContain('Alerta Quetzal')->not->toContain('Zorzal');

    $this->actingAs($this->viewer)->getJson(route('attention.alerts.show', $foreignAlert))->assertNotFound();
    $this->actingAs($this->viewer)->getJson(route('attention.alerts.show', $ownAlert))->assertOk();

    $payload = [
        'client_key' => (string) Str::uuid(),
        'audience' => 'individual',
        'title' => 'Clientes esperando',
        'body' => 'Revisa tus conversaciones pendientes.',
        'priority' => 'important',
        'color' => 'amber',
        'sound' => 'priority',
    ];

    $this->actingAs($this->viewer)
        ->post(route('attention.alerts.store'), [...$payload, 'recipient_ids' => [$this->zorzalAgent->id]])
        ->assertSessionHasErrors('recipient_ids');
    expect(AttentionAlertRecipient::query()->where('user_id', $this->zorzalAgent->id)->exists())->toBeFalse();

    $this->actingAs($this->viewer)
        ->post(route('attention.alerts.store'), [...$payload, 'client_key' => (string) Str::uuid(), 'recipient_ids' => [$this->quetzalAgent->id]])
        ->assertSessionHasNoErrors();
    expect(AttentionAlertRecipient::query()->where('user_id', $this->quetzalAgent->id)->exists())->toBeTrue();
});

test('nobody acknowledges an attention alert row of someone else, not even an alert holder', function () {
    $alert = AttentionAlert::factory()->create(['sender_id' => $this->root->id, 'recipients_count' => 1]);
    $row = new AttentionAlertRecipient;
    $row->forceFill(['attention_alert_id' => $alert->id, 'user_id' => $this->zorzalAgent->id])->save();

    $this->actingAs($this->viewer)->postJson(route('my-alerts.acknowledge', $row->id))->assertNotFound();
    $this->actingAs($this->quetzalAgent)->postJson(route('my-alerts.acknowledge', $row->id))->assertNotFound();

    expect($row->refresh()->acknowledged_at)->toBeNull();

    $this->actingAs($this->zorzalAgent)->postJson(route('my-alerts.acknowledge', $row->id))->assertOk();
    expect($row->refresh()->acknowledged_at)->not->toBeNull();
});

test('a department editor of another department cannot change its service rules', function () {
    // Phase-two behavior (FEATURE_PHASE_TWO); rules resolved before follow it too.
    config(['features.phase_two' => true]);
    app(ServiceRules::class)->flush();

    $payload = ['rules' => ['queue' => ['min_wait_minutes' => 7]]];

    $this->actingAs($this->viewer)->put(route('departments.service-rules.update', $this->zorzal), $payload)->assertNotFound();
    expect($this->zorzal->refresh()->service_rules)->toBeNull();

    $this->actingAs($this->viewer)->put(route('departments.service-rules.update', $this->quetzal), $payload)->assertSessionHasNoErrors();
    expect($this->quetzal->refresh()->service_rules)->toBe(['queue.min_wait_minutes' => 7]);
});

/*
| Request categories are a global catalog: categories.manage needs the
| global scope, so a departments-scoped holder is refused by the permission
| itself (403), never by ownership.
*/
test('a departments-scoped holder of categories.manage manages no category', function () {
    // Phase-two behavior (FEATURE_PHASE_TWO); rules resolved before follow it too.
    config(['features.phase_two' => true]);
    app(ServiceRules::class)->flush();

    $category = ConversationCategory::factory()->create(['name' => 'Categoría Zorzal']);
    $holder = scopeSweepUser([Permission::ManageCategories], DataScope::Departments, [$this->quetzal]);

    $this->actingAs($holder)->get(route('categories.index'))->assertForbidden();
    $this->actingAs($holder)->put(route('categories.update', $category), ['name' => 'Renombrada'])->assertForbidden();
    $this->actingAs($holder)->put(route('categories.rules.update', $category), ['rules' => []])->assertForbidden();
    $this->actingAs($holder)->delete(route('categories.destroy', $category))->assertForbidden();

    expect($category->refresh())->name->toBe('Categoría Zorzal')->isDeleted()->toBeFalse();

    $this->actingAs(scopeSweepUser([Permission::ManageCategories], DataScope::Global))->get(route('categories.index'))->assertOk();
});

/*
| The archived Whaticket history is organization-wide: history-import.archive-view
| needs the global scope, so a departments-scoped holder gets 403.
*/
test('a departments-scoped holder of the archive permission does not consult the archived history', function () {
    $holder = scopeSweepUser([Permission::ViewArchivedHistory], DataScope::Departments, [$this->quetzal]);

    $this->actingAs($holder)->get(route('admin.history.index'))->assertForbidden();
    $this->actingAs($holder)->getJson(route('admin.history.conversations.index'))->assertForbidden();
});

test('real files, inventory rows and rules of a history batch are refused for lack of permission, not for their type, and never through another batch', function () {
    Storage::fake('local');
    $batch = historyStandardBatch($this->root);
    $this->actingAs($this->root)->postJson(route('admin.history-import.rules.store', $batch), ['name' => 'Regla Zorzal', 'criteria' => (object) [], 'decision' => 'archive'])->assertCreated();
    $file = HistoryImportFile::query()->where('data_transfer_id', $batch->id)->firstOrFail();
    $row = historyRow($batch, 'ticket:1001');
    $rule = HistoryImportRule::query()->where('data_transfer_id', $batch->id)->sole();
    $rowState = $row->only(['decision', 'save_contact']);

    $classifyRoutes = [
        fn (User $user) => $this->actingAs($user)->deleteJson(route('admin.history-import.files.destroy', [$batch, $file])),
        fn (User $user) => $this->actingAs($user)->postJson(route('admin.history-import.files.retry', [$batch, $file])),
        fn (User $user) => $this->actingAs($user)->putJson(route('admin.history-import.files.mapping', [$batch, $file]), ['columns' => []]),
        fn (User $user) => $this->actingAs($user)->patchJson(route('admin.history-import.inventory.update', [$batch, $row]), ['decision' => 'exclude']),
        fn (User $user) => $this->actingAs($user)->deleteJson(route('admin.history-import.rules.destroy', [$batch, $rule])),
    ];
    $viewRoutes = [
        fn (User $user) => $this->actingAs($user)->getJson(route('admin.history-import.inventory.show', [$batch, $row])),
        fn (User $user) => $this->actingAs($user)->getJson(route('admin.history-import.rules.index', $batch)),
    ];

    // A global administrator without the importer permissions: the batch does not exist for them.
    $administrator = User::factory()->administrator()->create();
    foreach ([...$classifyRoutes, ...$viewRoutes] as $call) {
        $call($administrator)->assertNotFound();
    }

    // A holder of history-import.view only (root authority) sees the same records but changes nothing.
    $viewer = scopeSweepUser([Permission::ViewHistoryImports], DataScope::Global);
    $viewer->roles()->firstOrFail()->forceFill(['is_root' => true])->save();
    $viewer->refresh();
    foreach ($viewRoutes as $call) {
        $call($viewer)->assertOk();
    }
    foreach ($classifyRoutes as $call) {
        $call($viewer)->assertForbidden();
    }

    // The root itself cannot reach a file or rule of this batch through another batch.
    $other = historyBatch($this->root);
    $this->actingAs($this->root)->deleteJson(route('admin.history-import.files.destroy', [$other, $file]))->assertNotFound();
    $this->actingAs($this->root)->postJson(route('admin.history-import.files.retry', [$other, $file]))->assertNotFound();
    $this->actingAs($this->root)->deleteJson(route('admin.history-import.rules.destroy', [$other, $rule]))->assertNotFound();

    expect(HistoryImportFile::query()->whereKey($file->id)->exists())->toBeTrue()
        ->and(HistoryImportRule::query()->whereKey($rule->id)->exists())->toBeTrue()
        ->and($row->refresh()->only(['decision', 'save_contact']))->toBe($rowState);
});

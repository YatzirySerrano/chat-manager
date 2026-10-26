<?php

use App\Actions\Connections\ConnectionListQuery;
use App\Enums\DataScope;
use App\Enums\DefaultRole;
use App\Enums\Permission;
use App\Models\Connection;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\DataTransfer;
use App\Models\Department;
use App\Models\HistoryImportFile;
use App\Models\User;
use Illuminate\Support\Facades\Storage;

require_once __DIR__.'/HistoryImportSupport.php';

/*
| Permissions: importing is reserved to the root authority
| (history-import.view / classify / execute); consulting the archived
| history (history-import.archive-view) is organization-wide and the
| Administrador has it by default. Ordinary agents and supervisors never
| reach any of it. A batch is invisible (404) to whoever cannot see it.
*/

beforeEach(function () {
    Storage::fake('local');
    $this->root = User::factory()->superAdministrator()->create();
});

test('only the root authority holds the import permissions by default; the archive also belongs to the Administrador', function () {
    $administrator = User::factory()->administrator()->create();
    $supervisor = User::factory()->supervisor()->create();
    $agent = User::factory()->agent()->create();

    foreach ([Permission::ViewHistoryImports, Permission::ClassifyHistoryImports, Permission::ExecuteHistoryImports] as $permission) {
        expect($this->root->holdsPermission($permission))->toBeTrue()
            ->and($administrator->holdsPermission($permission))->toBeFalse()
            ->and($supervisor->holdsPermission($permission))->toBeFalse()
            ->and($agent->holdsPermission($permission))->toBeFalse()
            ->and($permission->isReservedToRoot())->toBeTrue()
            ->and($permission->isSensitive())->toBeTrue()
            ->and($permission->requiresGlobalScope())->toBeTrue();
    }

    expect($administrator->holdsPermission(Permission::ViewArchivedHistory))->toBeTrue()
        ->and($supervisor->holdsPermission(Permission::ViewArchivedHistory))->toBeFalse()
        ->and($agent->holdsPermission(Permission::ViewArchivedHistory))->toBeFalse()
        ->and(DefaultRole::Agent->permissions())->not->toContain(Permission::ViewArchivedHistory)
        ->and(Permission::ViewArchivedHistory->group()->value)->toBe('history-import');
});

test('people without the permissions get 403 on the screens and 404 on a batch', function () {
    $batch = historyBatch($this->root);
    $administrator = User::factory()->administrator()->create();
    $agent = User::factory()->agent()->create();

    foreach ([$administrator, $agent] as $user) {
        $this->actingAs($user)->get(route('admin.history-import.index'))->assertForbidden();
        $this->actingAs($user)->post(route('admin.history-import.store'), [])->assertForbidden();
        $this->actingAs($user)->get(route('admin.history-import.show', $batch))->assertNotFound();
        $this->actingAs($user)->getJson(route('admin.history-import.inventory.index', $batch))->assertNotFound();
        $this->actingAs($user)->getJson(route('admin.history-import.summary', $batch))->assertNotFound();
        $this->actingAs($user)->postJson(route('admin.history-import.inventory.bulk', $batch), ['decision' => 'exclude', 'filters' => (object) []])->assertNotFound();
        $this->actingAs($user)->post(route('admin.history-import.files.store', $batch), ['kind' => 'messages', 'file' => historyFixtureUpload('messages.csv')])->assertNotFound();
        $this->actingAs($user)->postJson(route('admin.history-import.execute', $batch), ['confirmation_token' => str_repeat('a', 64), 'confirm' => true])->assertNotFound();
        $this->actingAs($user)->get(route('admin.history-import.report', $batch))->assertNotFound();
    }

    $this->actingAs($agent)->get(route('admin.history.index'))->assertForbidden();
    $this->actingAs($administrator)->get(route('admin.history.index'))->assertOk();

    expect(HistoryImportFile::query()->count())->toBe(0);
});

test('an unauthorized upload is refused and stores nothing', function () {
    $batch = historyBatch($this->root);
    $viewer = conversationUser([Permission::ViewHistoryImports], DataScope::Global);
    $viewer->roles()->first()?->forceFill(['is_root' => true])->save();
    $viewer->refresh();

    // View without classify: the batch is visible, uploading is not allowed.
    $this->actingAs($viewer)->get(route('admin.history-import.show', $batch))->assertOk();
    $this->actingAs($viewer)->post(route('admin.history-import.files.store', $batch), ['kind' => 'messages', 'file' => historyFixtureUpload('messages.csv')])->assertForbidden();
    $this->actingAs($viewer)->postJson(route('admin.history-import.execute', $batch), ['confirmation_token' => str_repeat('a', 64), 'confirm' => true])->assertForbidden();

    expect(HistoryImportFile::query()->count())->toBe(0)
        ->and(Storage::disk('local')->allFiles())->toBe([]);
});

test('a batch of one administrator is not visible to a user without the permission, nor anything else that is not a history batch', function () {
    $batch = historyStandardBatch($this->root);
    $outsider = conversationUser([Permission::ViewConversations, Permission::ViewConversationHistory], DataScope::Global);
    $export = DataTransfer::factory()->create(['user_id' => $this->root->id]);

    $this->actingAs($outsider)->getJson(route('admin.history-import.inventory.index', $batch))->assertNotFound();
    $this->actingAs($outsider)->getJson(route('admin.history-import.inventory.show', [$batch, historyRow($batch, 'ticket:1001')]))->assertNotFound();
    $this->actingAs($this->root)->get(route('admin.history-import.show', $export))->assertNotFound();

    // A row of the batch cannot be reached through another batch.
    $other = historyBatch($this->root);
    $this->actingAs($this->root)->getJson(route('admin.history-import.inventory.show', [$other, historyRow($batch, 'ticket:1001')]))->assertNotFound();
});

test('archived history is only consultable with the permission, and only imported conversations', function () {
    $batch = historyStandardBatch($this->root);
    historyMapPlaceholders($this->root, $batch);
    historyDecide($this->root, $batch, 'archive');
    historyDecide($this->root, $batch, 'exclude', ['empty' => true]);
    historyConfirm($this->root, $batch);

    $archived = Conversation::query()->imported()->firstOrFail();
    $live = Conversation::factory()->create();
    $agent = User::factory()->agent()->create();
    $supervisor = User::factory()->supervisor()->create();

    $this->actingAs($agent)->get(route('admin.history.conversations.show', $archived))->assertNotFound();
    $this->actingAs($supervisor)->getJson(route('admin.history.conversations.index'))->assertForbidden();
    $this->actingAs($this->root)->get(route('admin.history.conversations.show', $live))->assertNotFound();
    $this->actingAs($this->root)->get(route('admin.history.conversations.show', $archived))->assertOk();
});

test('archived history never widens the contact directory of a department or an agent', function () {
    $sales = Department::factory()->create();
    $member = conversationUser([Permission::ViewContacts], DataScope::Departments, [$sales]);
    $agent = conversationUser([Permission::ViewContacts], DataScope::Assigned);

    $batch = historyStandardBatch($this->root);
    historyMapPlaceholders($this->root, $batch);
    $this->actingAs($this->root)->putJson(route('admin.history-import.mappings', $batch), [
        'departments' => ['Ventas' => $sales->id],
        'agents' => ['Agente Ficticio A' => $agent->id],
    ])->assertOk();
    historyDecide($this->root, $batch, 'archive');
    historyDecide($this->root, $batch, 'exclude', ['empty' => true]);
    historyConfirm($this->root, $batch);

    $archived = Conversation::query()->imported()->where('department_id', $sales->id)->firstOrFail();

    expect(Contact::query()->visibleTo($member)->whereKey($archived->contact_id)->exists())->toBeFalse()
        ->and(Contact::query()->visibleTo($agent)->whereKey($archived->contact_id)->exists())->toBeFalse();
});

test('the archive never shows a protected (root) account to an administrator', function () {
    $administrator = User::factory()->administrator()->create();
    $batch = historyStandardBatch($this->root);
    historyMapPlaceholders($this->root, $batch);
    $this->actingAs($this->root)->putJson(route('admin.history-import.mappings', $batch), ['agents' => ['Agente Ficticio A' => $this->root->id]])->assertOk();
    historyDecide($this->root, $batch, 'archive');
    historyDecide($this->root, $batch, 'exclude', ['empty' => true]);
    historyConfirm($this->root, $batch);
    config(['inertia.testing.ensure_pages_exist' => false]);

    $list = $this->actingAs($administrator)->getJson(route('admin.history.conversations.index', ['destination' => 'all']))->assertOk();
    $agents = collect($list->json('data'))->pluck('agent')->filter();

    expect($agents->pluck('name')->all())->not->toContain($this->root->name)
        ->and($agents->pluck('id')->all())->not->toContain($this->root->id)
        ->and($this->actingAs($administrator)->getJson(route('admin.history.conversations.index', ['destination' => 'all', 'agent_id' => $this->root->id]))->json('meta.total'))->toBe(0);

    $this->actingAs($administrator)->get(route('admin.history.index'))
        ->assertInertia(fn ($page) => $page->where('options.agents', fn ($agents) => collect($agents)->pluck('id')->doesntContain($this->root->id)));
});

test('the contact history endpoint answers the same 404 for a missing contact and for a viewer without the permission', function () {
    $agent = User::factory()->agent()->create();
    $contact = Contact::factory()->create();

    $existing = $this->actingAs($agent)->getJson(route('admin.history.contacts.show', $contact->id))->assertNotFound();
    $missing = $this->actingAs($agent)->getJson(route('admin.history.contacts.show', $contact->id + 1000))->assertNotFound();

    expect($existing->getContent())->toBe($missing->getContent());
    $this->actingAs($this->root)->getJson(route('admin.history.contacts.show', $contact->id + 1000))->assertNotFound();
});

test('imported conversations are not counted as operational activity of a connection', function () {
    $connection = Connection::factory()->create();
    Conversation::factory()->create(['connection_id' => $connection->id]);
    Conversation::factory()->closed()->create(['connection_id' => $connection->id])->forceFill(['origin' => 'whaticket'])->save();

    $query = app(ConnectionListQuery::class);
    $listed = $query->handle($this->root, $query->filters($this->root, []))->whereKey($connection->id)->first();

    expect($listed->conversations_count)->toBe(1);
});

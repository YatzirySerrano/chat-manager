<?php

use App\Enums\DataScope;
use App\Enums\DataTransferStatus;
use App\Enums\DataTransferType;
use App\Enums\Permission;
use App\Http\Middleware\HandleInertiaRequests;
use App\Models\DataTransfer;
use App\Models\Role;
use App\Models\Tag;
use App\Models\User;
use App\Notifications\DataTransferFinishedNotification;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;

/*
| Fase 5.2: the «Exportaciones» module left the navigation. Large files are
| prepared in the background and downloaded from the notification (or from
| the recent files of the export menu); the download route keeps checking
| owner, permission and expiration.
*/

function deliveryExporter(): User
{
    $role = Role::factory()->scope(DataScope::Global)->withPermissions([Permission::ViewTags, Permission::ExportReports])->create();

    return User::factory()->withRole($role)->create();
}

/**
 * The export notification of the transfer as the owner's bell presents it.
 *
 * @return array<string, mixed>
 */
function presentedExportNotification(User $owner, DataTransfer $transfer): array
{
    $owner->notify(new DataTransferFinishedNotification($transfer->id, DataTransferType::Export, $transfer->status->value));

    return test()->actingAs($owner)->getJson(route('notifications.index'))->assertOk()->json('data.0');
}

/**
 * Headers of an Inertia visit made from an open page.
 *
 * @return array<string, string>
 */
function inertiaVisitHeaders(): array
{
    return [
        'X-Inertia' => 'true',
        'X-Inertia-Version' => (string) app(HandleInertiaRequests::class)->version(request()),
        'X-Requested-With' => 'XMLHttpRequest',
        'Referer' => route('tags.index'),
    ];
}

test('a queued export tells the user it is being prepared and that the notification will bring the file', function () {
    config(['exports.formats.xlsx.sync_rows' => 1]);
    Storage::fake('local');
    Tag::factory()->count(2)->create();

    $this->actingAs(deliveryExporter())
        ->from(route('tags.index'))
        ->post(route('exports.generate', ['tags', 'xlsx']))
        ->assertRedirect(route('tags.index'))
        ->assertInertiaFlash('toast', [
            'type' => 'info',
            'message' => 'Estamos preparando el archivo de Etiquetas. Te avisaremos cuando esté listo; podrás descargarlo desde la notificación.',
        ]);
});

test('the notification of a ready export opens its download', function () {
    $owner = deliveryExporter();
    $transfer = DataTransfer::factory()->for($owner)->completed()->create();

    expect(presentedExportNotification($owner, $transfer))->toMatchArray([
        'title' => 'Tu exportación está lista',
        'body' => 'Descárgala desde esta notificación antes de que expire.',
        'url' => route('exports.transfers.download', $transfer),
        'accessible' => true,
    ]);
});

test('the notification of an expired or failed export has nothing to open and says what to do', function () {
    $owner = deliveryExporter();
    $expired = DataTransfer::factory()->for($owner)->pastExpiration()->create();
    $failed = DataTransfer::factory()->for($owner)->failed()->create();

    expect(presentedExportNotification($owner, $expired))->toMatchArray([
        'body' => 'El archivo ya expiró. Vuelve a exportarlo desde el módulo.',
        'url' => null,
    ]);

    $owner->notifications()->delete();

    expect(presentedExportNotification($owner, $failed))->toMatchArray([
        'title' => 'No se pudo generar tu exportación',
        'url' => null,
    ]);
});

test('opening a ready download from the application leaves Inertia for a full browser download', function () {
    Storage::fake('local');
    $owner = deliveryExporter();
    $transfer = DataTransfer::factory()->for($owner)->completed('exports/listo.xlsx')->create();
    Storage::disk('local')->put('exports/listo.xlsx', 'contenido');

    $this->actingAs($owner)
        ->get(route('exports.transfers.download', $transfer), inertiaVisitHeaders())
        ->assertStatus(409)
        ->assertHeader('X-Inertia-Location', route('exports.transfers.download', $transfer));

    $this->actingAs($owner)
        ->get(route('exports.transfers.download', $transfer))
        ->assertOk()
        ->assertDownload($transfer->file_name);
});

test('an expired download opened from the application explains it on the same page', function () {
    $owner = deliveryExporter();
    $expired = DataTransfer::factory()->for($owner)->pastExpiration()->create();

    $this->actingAs($owner)
        ->get(route('exports.transfers.download', $expired), inertiaVisitHeaders())
        ->assertRedirect(route('tags.index'))
        ->assertInertiaFlash('toast', ['type' => 'error', 'message' => 'Este archivo ya no está disponible. Genera la exportación de nuevo.']);

    $this->actingAs($owner)->get(route('exports.transfers.download', $expired))->assertStatus(410);
});

test('the download of another user is not found, also from the application', function () {
    $transfer = DataTransfer::factory()->for(deliveryExporter())->completed()->create();
    $intruder = deliveryExporter();

    $this->actingAs($intruder)->get(route('exports.transfers.download', $transfer))->assertNotFound();
    $this->actingAs($intruder)->get(route('exports.transfers.download', $transfer), inertiaVisitHeaders())->assertNotFound();
});

test('the export menu lists only the user own files of that module still being prepared or downloadable', function () {
    $owner = deliveryExporter();
    $ready = DataTransfer::factory()->for($owner)->completed()->create(['created_at' => now()->subMinutes(2)]);
    $queued = DataTransfer::factory()->for($owner)->create(['status' => DataTransferStatus::Processing]);
    DataTransfer::factory()->for($owner)->pastExpiration()->create();
    DataTransfer::factory()->for($owner)->failed()->create();
    DataTransfer::factory()->for($owner)->completed()->create(['report' => 'contacts']);
    DataTransfer::factory()->for(deliveryExporter())->completed()->create();

    $response = $this->actingAs($owner)->getJson(route('exports.recent', ['report' => 'tags']))->assertOk();

    expect($response->json('data.*.id'))->toBe([$queued->id, $ready->id])
        ->and($response->json('data.1.download_url'))->toBe(route('exports.transfers.download', $ready))
        ->and($response->json('data.0.download_url'))->toBeNull()
        ->and($response->json('data.1.report_title'))->toBe('Etiquetas');
});

test('the recent files leave out the ones the user may no longer download and require an export permission', function () {
    $role = Role::factory()->scope(DataScope::Global)->withPermissions([Permission::ViewAuditLog, Permission::ExportAuditLog, Permission::ExportReports])->create();
    $owner = User::factory()->withRole($role)->create();
    DataTransfer::factory()->for($owner)->completed()->create(['report' => 'audit']);
    $role->revokePermissionTo(Permission::ExportAuditLog->value);

    $this->actingAs(User::query()->findOrFail($owner->id))->getJson(route('exports.recent', ['report' => 'audit']))
        ->assertOk()
        ->assertJsonCount(0, 'data');

    $this->actingAs(User::factory()->supervisor()->create())->getJson(route('exports.recent', ['report' => 'tags']))->assertForbidden();
    $this->actingAs($owner)->getJson(route('exports.recent'))->assertUnprocessable();
});

test('the old exports address keeps working as the page of prepared files', function () {
    $owner = deliveryExporter();
    $transfer = DataTransfer::factory()->for($owner)->completed()->create();

    $this->actingAs($owner)
        ->get('/exports')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('exports/Index')
            ->where('transfers.data.0.download_url', route('exports.transfers.download', $transfer)));
});

test('prepared files and their history are kept until the prune expires them', function () {
    Storage::fake('local');
    $owner = deliveryExporter();
    $expired = DataTransfer::factory()->for($owner)->pastExpiration()->create();

    $this->artisan('exports:prune')->assertSuccessful();

    expect($expired->refresh()->status)->toBe(DataTransferStatus::Expired);
    $this->actingAs($owner)->get(route('exports.index'))
        ->assertInertia(fn (Assert $page) => $page->where('transfers.data.0.id', $expired->id)->where('transfers.data.0.download_url', null));
});

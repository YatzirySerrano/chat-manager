<?php

use App\Actions\Contacts\UpdateContact;
use App\Enums\AuditAction;
use App\Enums\ContactStatus;
use App\Enums\DataTransferStatus;
use App\Models\AuditLog;
use App\Models\Contact;
use App\Models\ContactNote;
use App\Models\Conversation;
use App\Models\DataTransfer;
use App\Models\ImportBatchRecord;
use App\Models\Tag;
use App\Models\User;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Testing\AssertableInertia as Assert;

require_once __DIR__.'/MigrationSupport.php';

beforeEach(function () {
    Storage::fake('local');
});

function rollbackImport(User $user, DataTransfer $transfer): void
{
    test()->actingAs($user)->post(route('contacts.import.rollback', $transfer), ['confirmation' => true])->assertSessionHasNoErrors();
}

test('every contact an import creates or completes is linked to its batch', function () {
    $root = User::factory()->superAdministrator()->create();
    $tag = Tag::factory()->create(['name' => 'Mayorista']);
    $existing = Contact::factory()->create(['phone_e164' => '+525511112222', 'email' => null]);

    $transfer = migrationRun($root, "Nombre,Teléfono,Correo,Etiquetas\n".
        "Ana,5512345678,ana@ejemplo.com,\n".
        "Existente,5511112222,nuevo@ejemplo.com,Mayorista\n", 'fill_empty');

    $records = ImportBatchRecord::query()->where('data_transfer_id', $transfer->id)->orderBy('line')->get();
    $ana = Contact::query()->where('email', 'ana@ejemplo.com')->sole();

    expect($records)->toHaveCount(2)
        ->and($records[0]->only(['record_type', 'record_id', 'line', 'action']))->toBe(['record_type' => 'contact', 'record_id' => $ana->id, 'line' => 2, 'action' => 'created'])
        ->and($records[0]->applied_values['phone_e164'])->toBe('+525512345678')
        ->and($records[1]->only(['record_id', 'action', 'previous_values', 'applied_values', 'added_tag_ids']))->toBe([
            'record_id' => $existing->id,
            'action' => 'filled',
            'previous_values' => ['email' => null],
            'applied_values' => ['email' => 'nuevo@ejemplo.com'],
            'added_tag_ids' => [$tag->id],
        ]);
});

test('re-importing the same file links nothing new to the second batch', function () {
    $root = User::factory()->superAdministrator()->create();
    $content = "Nombre,Teléfono\nAna,5512345678\nLuis,5587654321\n";

    $first = migrationRun($root, $content);
    $second = migrationRun($root, $content);

    expect(Contact::query()->count())->toBe(2)
        ->and(ImportBatchRecord::query()->where('data_transfer_id', $first->id)->count())->toBe(2)
        ->and(ImportBatchRecord::query()->where('data_transfer_id', $second->id)->count())->toBe(0)
        ->and($second->summary['created'])->toBe(0);
});

test('a rollback is previewed, then archives only untouched created contacts and restores unchanged filled fields', function () {
    $root = User::factory()->superAdministrator()->create();
    $tag = Tag::factory()->create(['name' => 'Mayorista']);
    $filledUnchanged = Contact::factory()->create(['name' => 'Completo', 'phone_e164' => '+525511112222', 'email' => null]);
    $filledChanged = Contact::factory()->create(['name' => 'Cambiado', 'phone_e164' => '+525533334444', 'email' => null]);

    $transfer = migrationRun($root, "Nombre,Teléfono,Correo,Etiquetas\n".
        "Intacto,5512345678,,\n".
        "Con conversación,5523456789,,\n".
        "Editado,5534567890,,\n".
        "Con nota,5545678901,,\n".
        "Completo,5511112222,completo@ejemplo.com,Mayorista\n".
        "Cambiado,5533334444,cambiado@ejemplo.com,\n", 'fill_empty');

    $untouched = Contact::query()->where('phone_e164', '+525512345678')->sole();
    $withConversation = Contact::query()->where('phone_e164', '+525523456789')->sole();
    $edited = Contact::query()->where('phone_e164', '+525534567890')->sole();
    $withNote = Contact::query()->where('phone_e164', '+525545678901')->sole();

    // Later activity after the import.
    Conversation::factory()->create(['contact_id' => $withConversation->id]);
    app(UpdateContact::class)->handle($edited, ['name' => 'Editado a mano', 'phone_e164' => $edited->phone_e164, 'email' => null, 'custom_fields' => null], $root);
    ContactNote::factory()->create(['contact_id' => $withNote->id, 'author_user_id' => $root->id]);
    $filledChanged->forceFill(['email' => 'otro@ejemplo.com'])->save();

    $this->actingAs($root)->get(route('contacts.import.show', $transfer))
        ->assertInertia(fn (Assert $page) => $page
            ->where('transfer.rollback.available', true)
            ->missing('rollbackPreview'));

    $writes = databaseWritesDuring(fn () => $this->actingAs($root)
        ->get(route('contacts.import.show', $transfer), [
            'X-Inertia' => 'true',
            'X-Inertia-Version' => (string) Inertia::getVersion(),
            'X-Inertia-Partial-Component' => 'contacts/Import',
            'X-Inertia-Partial-Data' => 'rollbackPreview',
        ])
        ->assertJsonPath('props.rollbackPreview.summary', [
            'records' => 6,
            'contacts_archived' => 1,
            'contacts_kept' => 3,
            'fields_restored' => 1,
            'fields_kept' => 1,
            'tags_removed' => 1,
            'notes_kept' => 0,
            'reverted' => 2,
            'partial' => 0,
            'kept' => 4,
        ])
        ->assertJsonPath('props.rollbackPreview.kept.0.reason', 'Tiene conversaciones.'));

    expect($writes)->toBe([])
        ->and($untouched->refresh()->status)->toBe(ContactStatus::Active);

    rollbackImport($root, $transfer);

    expect($untouched->refresh()->status)->toBe(ContactStatus::Archived)
        ->and($withConversation->refresh()->status)->toBe(ContactStatus::Active)
        ->and($edited->refresh()->status)->toBe(ContactStatus::Active)
        ->and($withNote->refresh()->status)->toBe(ContactStatus::Active)
        ->and($filledUnchanged->refresh()->email)->toBeNull()
        ->and($filledUnchanged->tags()->count())->toBe(0)
        ->and($filledChanged->refresh()->email)->toBe('otro@ejemplo.com')
        ->and($transfer->refresh()->options['rollback']['status'])->toBe('completed')
        ->and($transfer->options['rollback']['summary']['contacts_archived'])->toBe(1)
        ->and(ImportBatchRecord::query()->where('record_id', $edited->id)->sole()->rollback_reason)->toBe('Se modificó después de la importación.')
        ->and(ImportBatchRecord::query()->where('record_id', $filledChanged->id)->sole()->only(['rollback_outcome', 'rollback_reason']))->toBe([
            'rollback_outcome' => 'kept',
            'rollback_reason' => 'El correo electrónico cambió después de la importación.',
        ]);

    $this->actingAs($root)->get(route('contacts.import.show', $transfer))
        ->assertInertia(fn (Assert $page) => $page
            ->where('transfer.rollback.available', false)
            ->where('transfer.rollback.unavailable_reason', 'Esta importación ya se revirtió.')
            ->has('rollbackKept', 4));

    // A second rollback is refused.
    $this->actingAs($root)->post(route('contacts.import.rollback', $transfer), ['confirmation' => true])
        ->assertSessionHasErrors(['rollback' => 'Esta importación ya se revirtió.']);
});

test('a contact edited directly in the database after the import is not rolled back', function () {
    $root = User::factory()->superAdministrator()->create();
    $transfer = migrationRun($root, "Nombre,Teléfono\nAna,5512345678\n");
    $ana = Contact::query()->sole();

    // A change without audit entry (e.g. a profile name from WhatsApp) still counts.
    Contact::query()->whereKey($ana->id)->update(['profile_name' => 'Ana WhatsApp']);

    rollbackImport($root, $transfer);

    expect($ana->refresh()->status)->toBe(ContactStatus::Active)
        ->and(ImportBatchRecord::query()->sole()->rollback_reason)->toBe('Sus datos o etiquetas cambiaron después de la importación.');
});

test('the rollback needs explicit confirmation and is audited as restricted', function () {
    $root = User::factory()->superAdministrator()->create();
    $transfer = migrationRun($root, "Nombre,Teléfono\nAna,5512345678\n");

    $this->actingAs($root)->post(route('contacts.import.rollback', $transfer))
        ->assertSessionHasErrors(['confirmation' => 'Confirma que revisaste la vista previa de la reversión.']);

    expect(Contact::query()->sole()->status)->toBe(ContactStatus::Active);

    rollbackImport($root, $transfer);

    $entries = AuditLog::query()->whereIn('action', [AuditAction::ImportRollbackRequested, AuditAction::ImportRolledBack])->orderBy('id')->get();

    expect($entries->pluck('action')->all())->toBe([AuditAction::ImportRollbackRequested, AuditAction::ImportRolledBack])
        ->and($entries->every(fn (AuditLog $log): bool => $log->restricted))->toBeTrue()
        ->and($entries[1]->properties)->toMatchArray(['transfer_id' => $transfer->id, 'contacts_archived' => 1])
        ->and(AuditLog::query()->where('action', AuditAction::ContactDeleted)->count())->toBe(1);
});

test('only the owner superadministrator can preview or roll back a batch', function () {
    $owner = User::factory()->superAdministrator()->create();
    $transfer = migrationRun($owner, "Nombre,Teléfono\nAna,5512345678\n");

    $this->actingAs(User::factory()->superAdministrator()->create())
        ->post(route('contacts.import.rollback', $transfer), ['confirmation' => true])
        ->assertNotFound();

    foreach ([User::factory()->administrator()->create(), User::factory()->agent()->create()] as $user) {
        $this->actingAs($user)->post(route('contacts.import.rollback', $transfer), ['confirmation' => true])->assertNotFound();
    }

    expect(Contact::query()->sole()->status)->toBe(ContactStatus::Active)
        ->and($transfer->refresh()->options)->not->toHaveKey('rollback');
});

test('batches without traceability, outside the window or never processed cannot be rolled back', function () {
    $root = User::factory()->superAdministrator()->create();
    $transfer = migrationRun($root, "Nombre,Teléfono\nAna,5512345678\n");

    $legacy = $transfer->replicate();
    $legacy->options = collect($transfer->options)->except('traceability')->all();
    $legacy->save();

    $this->actingAs($root)->post(route('contacts.import.rollback', $legacy), ['confirmation' => true])
        ->assertSessionHasErrors(['rollback' => 'Esta importación se hizo antes de la trazabilidad por lotes; no se puede revertir automáticamente.']);

    $transfer->forceFill(['finished_at' => now()->subDays(31)])->save();

    $this->actingAs($root)->post(route('contacts.import.rollback', $transfer), ['confirmation' => true])
        ->assertSessionHasErrors(['rollback' => 'Solo se pueden revertir importaciones de los últimos 30 días.']);

    $draft = migrationUpload($root, migrationCsv("Nombre,Teléfono\nLuis,5587654321\n"));

    $this->actingAs($root)->post(route('contacts.import.rollback', $draft), ['confirmation' => true])
        ->assertSessionHasErrors(['rollback' => 'Solo se puede revertir una importación terminada.']);

    expect(Contact::query()->sole()->status)->toBe(ContactStatus::Active);
});

test('re-importing a rolled back file explains that the contact was archived by the rollback', function () {
    $root = User::factory()->superAdministrator()->create();
    $content = "Nombre,Teléfono\nAna,5512345678\n";
    $first = migrationRun($root, $content);

    rollbackImport($root, $first);

    $second = migrationRun($root, $content);
    $ana = Contact::query()->sole();

    expect($second->summary)->toBe(['created' => 0, 'updated' => 0, 'skipped' => 1, 'failed' => 0])
        ->and($ana->status)->toBe(ContactStatus::Archived);

    $this->actingAs($root)->get(route('contacts.import.create'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('recent.1.rollback.status', 'completed')
            ->where('recent.0.rollback.unavailable_reason', 'Esta importación no creó ni completó contactos; no hay nada que revertir.'));
});

test('a filled phone is kept when its conversation received messages after the import', function () {
    $root = User::factory()->superAdministrator()->create();
    $contact = Contact::factory()->create(['name' => 'Con historial', 'phone_e164' => null, 'email' => 'historial@ejemplo.com']);
    $conversation = Conversation::factory()->create([
        'contact_id' => $contact->id,
        'created_at' => now()->subDays(3),
        'last_message_at' => now()->subDays(3),
    ]);

    $transfer = migrationRun($root, "Nombre,Teléfono,Correo\nCon historial,5512345678,historial@ejemplo.com\n", 'fill_empty');

    expect($contact->refresh()->phone_e164)->toBe('+525512345678');

    $this->travel(5)->minutes();
    $conversation->forceFill(['last_message_at' => now()])->save();

    rollbackImport($root, $transfer);

    expect($contact->refresh()->phone_e164)->toBe('+525512345678')
        ->and(ImportBatchRecord::query()->sole()->only(['rollback_outcome', 'rollback_reason']))->toBe([
            'rollback_outcome' => 'kept',
            'rollback_reason' => 'El teléfono ya se usó en conversaciones de WhatsApp.',
        ]);
});

test('a filled field is not reverted when the contact would be left without phone and email', function () {
    $root = User::factory()->superAdministrator()->create();
    $contact = Contact::factory()->create(['name' => 'Solo correo', 'phone_e164' => null, 'email' => 'solo@ejemplo.com']);

    $transfer = migrationRun($root, "Nombre,Teléfono,Correo\nSolo correo,5512345678,solo@ejemplo.com\n", 'fill_empty');

    // The e-mail is removed later; the filled phone is now its only contact data.
    $contact->refresh()->forceFill(['email' => null])->save();

    rollbackImport($root, $transfer);

    expect($contact->refresh()->phone_e164)->toBe('+525512345678')
        ->and(ImportBatchRecord::query()->sole()->rollback_reason)->toBe('Sin los campos completados el contacto quedaría sin teléfono ni correo.');
});

test('an import that failed after processing part of its rows can be rolled back', function () {
    $root = User::factory()->superAdministrator()->create();
    $transfer = migrationRun($root, "Nombre,Teléfono\nAna,5512345678\n");

    // State left by ImportContacts::failed() when the job stops midway.
    $transfer->forceFill(['status' => DataTransferStatus::Failed, 'summary' => null, 'error' => 'No se pudo completar la importación.'])->save();

    $this->actingAs($root)->get(route('contacts.import.show', $transfer))
        ->assertInertia(fn (Assert $page) => $page->where('transfer.rollback.available', true));

    rollbackImport($root, $transfer);

    expect(Contact::query()->sole()->status)->toBe(ContactStatus::Archived);
});

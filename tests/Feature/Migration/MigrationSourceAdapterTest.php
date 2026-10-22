<?php

use App\Enums\DataTransferType;
use App\Models\Contact;
use App\Models\DataTransfer;
use App\Models\User;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;

require_once __DIR__.'/MigrationSupport.php';

beforeEach(function () {
    Storage::fake('local');
});

const WHATICKET_HEADERS = 'id,name,number,profilePicUrl,email,isGroup,createdAt';

test('a whaticket contacts export is detected, mapped and normalized before validation', function () {
    $root = User::factory()->superAdministrator()->create();

    $transfer = migrationUpload($root, migrationCsv(WHATICKET_HEADERS."\n".
        "1,Ana Ruiz,5215512345678@c.us,,ana@ejemplo.com,false,2024-01-01\n".
        "2,Grupo ventas,120363042@g.us,,,false,2024-01-01\n".
        "3,Familia,5215587654321,,,true,2024-01-01\n".
        "4,Luis,5587654321,,,0,2024-01-01\n"), 'whaticket-contacts');

    expect($transfer->options['source'])->toBe('whaticket-contacts')
        ->and($transfer->options['mapping'])->toBe(['name' => 1, 'phone' => 2, 'email' => 4, 'tags' => null, 'notes' => null]);

    $this->actingAs($root)->get(route('contacts.import.show', $transfer))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('source.current', 'whaticket-contacts')
            ->where('source.detection.0.key', 'whaticket-contacts')
            ->where('source.detection.0.signals', ['profilePicUrl', 'isGroup', 'createdAt'])
            ->where('transfer.source.validated', false)
            ->where('preview.summary.valid', 2)
            ->where('preview.summary.errors', 2)
            ->where('preview.rows.0.phone', '+525512345678')
            ->where('preview.rows.0.warnings', [
                'Se quitó el sufijo de WhatsApp del número.',
                'Número sin «+»: se interpretó con lada internacional, como lo guarda WhatsApp.',
            ])
            ->where('preview.rows.1.errors.0', 'El número corresponde a un grupo de WhatsApp («@g.us»): no se importa.')
            ->where('preview.rows.2.errors', ['Es un grupo de WhatsApp, no un contacto: no se importa.'])
            ->where('preview.rows.3.phone', '+525587654321'));

    $this->actingAs($root)->post(route('contacts.import.confirm', $transfer))->assertSessionHasNoErrors();

    expect(Contact::query()->orderBy('id')->pluck('phone_e164')->all())->toBe(['+525512345678', '+525587654321'])
        ->and($transfer->refresh()->summary)->toBe(['created' => 2, 'updated' => 0, 'skipped' => 0, 'failed' => 2]);
});

test('the generic source keeps the original behaviour and wins detection for ordinary spreadsheets', function () {
    $root = User::factory()->superAdministrator()->create();

    $transfer = migrationUpload($root, migrationCsv("Nombre,Teléfono,Correo\nAna,5215512345678,ana@ejemplo.com\n"));

    expect($transfer->options['source'])->toBe('generic')
        ->and($transfer->options['mapping'])->toBe(['name' => 0, 'phone' => 1, 'email' => 2, 'tags' => null, 'notes' => null]);

    // Without the adapter a 13-digit number without "+" is not guessed.
    $this->actingAs($root)->get(route('contacts.import.show', $transfer))
        ->assertInertia(fn (Assert $page) => $page
            ->where('source.detection.0.key', 'generic')
            ->where('preview.summary.errors', 1));
});

test('the source can be changed with the mapping and only registered sources are accepted', function () {
    $root = User::factory()->superAdministrator()->create();

    $this->actingAs($root)->post(route('contacts.import.store'), [
        'file' => migrationCsv("name,number\nAna,5215512345678\n"),
        'source' => 'whaticket-tickets',
    ])->assertSessionHasErrors(['source' => 'Elige un origen de la lista.']);

    expect(DataTransfer::query()->where('type', DataTransferType::Import)->count())->toBe(0);

    $transfer = migrationUpload($root, migrationCsv("name,number\nAna,5215512345678\n"));

    $this->actingAs($root)->put(route('contacts.import.update', $transfer), [
        'mapping' => ['name' => 0, 'phone' => 1],
        'decision' => 'skip',
        'source' => 'sql',
    ])->assertSessionHasErrors('source');

    $this->actingAs($root)->put(route('contacts.import.update', $transfer), [
        'mapping' => ['name' => 0, 'phone' => 1],
        'decision' => 'skip',
        'source' => 'whaticket-contacts',
    ])->assertSessionHasNoErrors();

    $this->actingAs($root)->get(route('contacts.import.show', $transfer))
        ->assertInertia(fn (Assert $page) => $page
            ->where('source.current', 'whaticket-contacts')
            ->where('preview.summary.valid', 1));
});

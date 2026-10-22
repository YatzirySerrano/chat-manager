<?php

use App\Enums\DataTransferStatus;
use App\Enums\DataTransferType;
use App\Events\Broadcasts\RealtimeSignal;
use App\Models\DataTransfer;
use App\Models\User;
use App\Notifications\NotificationKind;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;

/*
| An import confirmed from the wizard runs its queued job (inline here),
| and its end reaches the owner exactly once: one in-app notification and
| one real-time signal on their personal channel, nobody else.
*/

test('a finished import notifies and signals its owner exactly once', function () {
    Storage::fake('local');
    config(['broadcasting.default' => 'log']);
    $root = User::factory()->superAdministrator()->create();
    $otherRoot = User::factory()->superAdministrator()->create();

    $path = tempnam(sys_get_temp_dir(), 'import');
    file_put_contents($path, "Nombre completo,Celular,Correo\nAna Ruiz,5512345678,ana@ejemplo.com\n");

    $this->actingAs($root)->post(route('contacts.import.store'), [
        'file' => new UploadedFile($path, 'contactos.csv', null, null, true),
    ])->assertSessionHasNoErrors();
    $transfer = DataTransfer::query()->where('type', DataTransferType::Import)->sole();

    $this->put(route('contacts.import.update', $transfer), [
        'mapping' => $transfer->options['mapping'],
        'decision' => 'skip',
    ])->assertSessionHasNoErrors();

    Event::fake([RealtimeSignal::class]);
    $this->post(route('contacts.import.confirm', $transfer))->assertSessionHasNoErrors();

    expect($transfer->refresh()->status)->toBe(DataTransferStatus::Completed)
        ->and($root->notifications()->where('kind', NotificationKind::ImportFinished->value)->count())->toBe(1)
        ->and($otherRoot->notifications()->count())->toBe(0);

    $transferSignals = collect(Event::dispatched(RealtimeSignal::class))
        ->map(fn (array $arguments): RealtimeSignal => $arguments[0])
        ->filter(fn (RealtimeSignal $signal): bool => $signal->event === 'transfer.changed');

    expect($transferSignals)->toHaveCount(1)
        ->and($transferSignals->first()->channels)->toBe(["users.{$root->id}"]);
});

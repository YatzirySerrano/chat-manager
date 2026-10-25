<?php

use App\Enums\AttachmentStatus;
use App\Enums\ConversationStatus;
use App\Enums\CustomerWindowStatus;
use App\Enums\InboundMessageOutcome;
use App\Enums\MessageStatus;
use App\Models\Connection;
use App\Models\Conversation;
use App\Models\Department;
use App\Models\Message;
use App\Models\User;
use App\Services\Conversations\CustomerServiceWindow;
use App\Support\ManualValidation;
use App\Support\ManualValidationCleanup;
use App\Support\ManualValidationSimulator;
use Database\Seeders\Local\ManualValidationSeeder;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\Support\WhatsAppFixtures;

/*
| SIMULACIÓN: every branch of chat-manager:simulate-inbound. The command
| refuses this environment (see ManualValidationScenarioTest), so the
| simulator it delegates to is exercised on the scenario built directly.
*/

beforeEach(function () {
    Http::preventStrayRequests();
    User::factory()->superAdministrator()->create();
    $this->credentials = storage_path('framework/testing/demo-b-simulator-credentials.txt');
    @unlink($this->credentials);
    $this->scenario = (new ManualValidationSeeder)->build($this->credentials);
    $this->simulator = app(ManualValidationSimulator::class);
    $this->sales = $this->simulator->line('ventas');
});

afterEach(fn () => @unlink($this->credentials));

test('a new fictitious customer opens a conversation in sales and an existing one adds to theirs', function () {
    $new = $this->simulator->inbound($this->sales, '5215500000150', 'Quiero una cotización', ['name' => 'Ana Prueba']);
    $existing = $this->simulator->inbound($this->sales, 'laura', 'Sigo interesada');

    expect($new)
        ->outcome->toBe(InboundMessageOutcome::Created)
        ->new_contact->toBeTrue()
        ->name->toBe(ManualValidation::PREFIX.'Ana Prueba')
        ->and($new['conversation']->department?->slug)->toBe(ManualValidation::DEPARTMENTS['sales'])
        ->and($existing)
        ->outcome->toBe(InboundMessageOutcome::Appended)
        ->new_contact->toBeFalse()
        ->and($existing['conversation']->id)->toBe($this->scenario['conversations']['pending']);
    Http::assertNothingSent();
});

test('only fictitious numbers and the demo lines can be simulated', function () {
    $real = Connection::factory()->create();

    expect(fn () => $this->simulator->inbound($this->sales, '5215512345678', 'Hola'))->toThrow(RuntimeException::class, 'Solo se pueden simular números ficticios')
        ->and(fn () => $this->simulator->inbound($real, 'laura', 'Hola'))->toThrow(RuntimeException::class, 'solo actúa sobre las líneas demo')
        ->and(fn () => $this->simulator->inbound($this->sales, 'laura', 'Hola', ['type' => 'gif']))->toThrow(RuntimeException::class, 'Tipo desconocido')
        ->and(fn () => $this->simulator->line('marketing'))->toThrow(RuntimeException::class, 'Departamento desconocido')
        ->and($real->conversations()->exists())->toBeFalse();
});

test('a message addressed to support needs the support line, created once on request', function () {
    expect(fn () => $this->simulator->line('soporte'))->toThrow(RuntimeException::class, '--create-support-line');

    $line = $this->simulator->line('soporte', createSupportLine: true);
    $again = $this->simulator->line('soporte', createSupportLine: true);
    $result = $this->simulator->inbound($line, 'mario', 'Mi pedido llegó dañado');

    expect($again->is($line))->toBeTrue()
        ->and(Connection::query()->whereIn('phone_number_id', ManualValidation::LINES)->count())->toBe(2)
        ->and($result['outcome'])->toBe(InboundMessageOutcome::Created)
        ->and($result['conversation']->connection_id)->toBe($line->id)
        ->and($result['conversation']->department?->slug)->toBe(ManualValidation::DEPARTMENTS['support']);

    $plan = app(ManualValidationCleanup::class)->execute();

    expect($plan['blockers'])->toBe([])
        ->and(Connection::query()->count())->toBe(0)
        ->and(Conversation::query()->count())->toBe(0);
});

test('a media message keeps a local sample file or shows the attachment as unavailable', function () {
    Storage::fake('local');
    $sample = tempnam(sys_get_temp_dir(), 'sample');
    file_put_contents($sample, "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF");

    $withFile = $this->simulator->inbound($this->sales, 'laura', 'Mi comprobante', ['type' => 'document', 'file' => $sample]);
    $withoutFile = $this->simulator->inbound($this->sales, 'laura', 'Foto', ['type' => 'image']);
    unlink($sample);

    expect($withFile['warning'])->toBeNull()
        ->and($withFile['message']->attachments()->sole()->status)->toBe(AttachmentStatus::Available)
        ->and($withoutFile['message']->attachments()->sole()->status)->toBe(AttachmentStatus::Unavailable)
        ->and(fn () => $this->simulator->inbound($this->sales, 'laura', 'x', ['type' => 'image', 'file' => '/nonexistent/file.png']))->toThrow(RuntimeException::class, 'no existe');
});

test('delivering the last message again is a harmless duplicate', function () {
    $first = $this->simulator->inbound($this->sales, 'laura', 'Hola otra vez');
    $count = Message::query()->count();

    $duplicate = $this->simulator->inbound($this->sales, 'laura', 'Hola otra vez', ['duplicate' => true]);

    expect($duplicate['outcome'])->toBe(InboundMessageOutcome::Duplicate)
        ->and($duplicate['message']->id)->toBe($first['message']->id)
        ->and(Message::query()->count())->toBe($count)
        ->and(fn () => $this->simulator->inbound($this->sales, '5215500000160', 'x', ['duplicate' => true]))->toThrow(RuntimeException::class, 'Todavía no hay un mensaje simulado');
});

test('a customer writing again shortly after the closing reopens the conversation', function () {
    $this->travel(5)->minutes();

    $result = $this->simulator->inbound($this->sales, 'sofia', 'Una duda más sobre la factura');

    expect($result['outcome'])->toBe(InboundMessageOutcome::Reopened)
        ->and($result['conversation']->id)->toBe($this->scenario['conversations']['closed'])
        ->and($result['conversation']->status)->not->toBe(ConversationStatus::Closed);
});

test('delivered and read statuses are recorded even out of order and duplicated', function () {
    $conversation = Conversation::query()->findOrFail($this->scenario['conversations']['attending']);
    $message = WhatsAppFixtures::outbound($conversation, ['status' => 'accepted', 'whatsapp_message_id' => 'wamid.DEMO-B.out.1']);

    $result = $this->simulator->status($message->id, 'delivered', outOfOrder: true, duplicate: true);

    expect($result['sequence'])->toBe(['read', 'delivered', 'delivered'])
        ->and($result['message'])
        ->status->toBe(MessageStatus::Read)
        ->delivered_at->not->toBeNull();
});

test('a failed status carries the chosen Meta error, including the expired window one', function () {
    $conversation = Conversation::query()->findOrFail($this->scenario['conversations']['attending']);
    $message = WhatsAppFixtures::outbound($conversation, ['status' => 'accepted', 'whatsapp_message_id' => 'wamid.DEMO-B.out.2']);
    $real = WhatsAppFixtures::outbound(Conversation::factory()->create(), ['status' => 'accepted', 'whatsapp_message_id' => 'wamid.real.out']);

    $result = $this->simulator->status($message->id, 'failed', errorCode: 131047);

    expect($result['message'])
        ->status->toBe(MessageStatus::Failed)
        ->error_code->toBe('meta_131047')
        ->error_message->toContain('24 horas')
        ->and(fn () => $this->simulator->status($message->id, 'failed', errorCode: 999))->toThrow(RuntimeException::class, 'Código de error no simulable')
        ->and(fn () => $this->simulator->status($real->id, 'delivered'))->toThrow(RuntimeException::class, 'no es un mensaje saliente de una línea demo')
        ->and($real->refresh()->status)->toBe(MessageStatus::Accepted);
});

test('the 24-hour window of a demo conversation can be closed by moving its last customer message back', function () {
    $window = app(CustomerServiceWindow::class);

    $result = $this->simulator->expireWindow($this->sales, 'laura');
    $pending = Conversation::query()->findOrFail($this->scenario['conversations']['pending']);

    expect($result['conversation']->id)->toBe($pending->id)
        ->and($window->for($pending)->status)->toBe(CustomerWindowStatus::Expired)
        ->and($pending->messages()->where('direction', 'inbound')->sole()->sent_at->lessThan(now()->subDay()))->toBeTrue();

    $this->simulator->inbound($this->sales, 'laura', 'Primero');
    $this->simulator->inbound($this->sales, 'laura', 'Segundo');

    expect(fn () => $this->simulator->expireWindow($this->sales, 'laura'))->toThrow(RuntimeException::class, 'cambiaría el orden del historial')
        ->and(fn () => $this->simulator->expireWindow($this->sales, '5215500000170'))->toThrow(RuntimeException::class, 'todavía no tiene conversaciones');

    $alternative = $this->simulator->inbound($this->sales, '5215500000160', 'Hola', ['minutes_ago' => 1500]);

    expect($alternative['outcome'])->toBe(InboundMessageOutcome::Created)
        ->and($window->for($alternative['conversation']->refresh())->status)->toBe(CustomerWindowStatus::Expired);
});

test('a demo department renamed from the interface is still recognized and never duplicated', function () {
    $support = Department::query()->where('slug', ManualValidation::DEPARTMENTS['support'])->sole();
    $support->forceFill(['name' => 'Soporte', 'slug' => 'soporte'])->save();
    $real = Department::factory()->create(['name' => 'Soporte real']);

    (new ManualValidationSeeder)->build($this->credentials);

    expect(ManualValidation::department('support')?->is($support))->toBeTrue()
        ->and(Department::query()->count())->toBe(3)
        ->and(app(ManualValidationCleanup::class)->plan()['remove']['departments'])->toEqualCanonicalizing([
            ManualValidation::department('sales')?->id,
            $support->id,
        ])
        ->and(ManualValidation::departmentIds())->not->toContain($real->id);
});

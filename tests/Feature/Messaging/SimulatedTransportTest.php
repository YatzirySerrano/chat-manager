<?php

use App\Actions\Messaging\ProcessWebhookPayload;
use App\Enums\AttachmentStatus;
use App\Enums\MessageStatus;
use App\Jobs\DeliverOutboundMessage;
use App\Models\Connection;
use App\Models\Conversation;
use App\Models\Department;
use App\Models\Message;
use App\Models\User;
use App\Services\Meta\MediaStorage;
use App\Services\Meta\SimulatedWebhook;
use App\Services\Meta\UnavailableWhatsAppTransport;
use App\Services\Meta\WhatsAppTransport;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\Support\WhatsAppFixtures;

/*
| SIMULACIÓN: the local-only transport and simulated webhooks go through the
| same processing as Meta and are labeled as simulated.
*/

beforeEach(function () {
    Http::preventStrayRequests();
    $department = Department::factory()->create();
    $agent = User::factory()->agent()->inDepartments($department)->create();
    $this->connection = Connection::factory()->create(['business_account_id' => 'DEMO-WABA']);
    $this->conversation = Conversation::factory()->inDepartment($department)->assignedTo($agent)->create(['connection_id' => $this->connection->id, 'last_inbound_at' => now()->subHour()]);
});

test('the simulated transport labels the message and reports statuses through the webhook processing', function () {
    config(['whatsapp.transport' => 'simulated', 'whatsapp.simulation.status_progression' => true, 'whatsapp.simulation.simulate_read' => true]);
    $message = WhatsAppFixtures::outbound($this->conversation);

    app()->call([new DeliverOutboundMessage($message->id), 'handle']);

    expect($message->refresh())
        ->whatsapp_message_id->toStartWith('sim.')
        ->status->toBe(MessageStatus::Read)
        ->delivered_at->not->toBeNull()
        ->and($message->metadata['simulated'])->toBeTrue()
        ->and($this->connection->refresh()->last_outbound_at)->toBeNull();
    Http::assertNothingSent();
});

test('the simulated transport never runs in production', function () {
    config(['whatsapp.transport' => 'simulated']);
    app()->detectEnvironment(fn () => 'production');

    expect(app(WhatsAppTransport::class))->toBeInstanceOf(UnavailableWhatsAppTransport::class);
});

test('simulated media without a file is shown as unavailable instead of calling Meta', function () {
    $payload = SimulatedWebhook::message((string) $this->connection->phone_number_id, 'DEMO-WABA', '5215500000101', 'Cliente', 'wamid.sim.media', 'image', 'Foto', now()->getTimestamp());

    app(ProcessWebhookPayload::class)->handle($this->connection, $payload);

    expect(Message::query()->where('whatsapp_message_id', 'wamid.sim.media')->sole()->attachments()->sole())
        ->status->toBe(AttachmentStatus::Unavailable)
        ->error_code->toBe('simulated_media');
});

test('a local sample file goes through the same validation as a real download', function () {
    Storage::fake('local');
    $payload = SimulatedWebhook::message((string) $this->connection->phone_number_id, 'DEMO-WABA', '5215500000101', 'Cliente', 'wamid.sim.file', 'document', 'Contrato', now()->getTimestamp(), fileName: 'contrato.pdf');
    app(ProcessWebhookPayload::class)->handle($this->connection, $payload);
    $attachment = Message::query()->where('whatsapp_message_id', 'wamid.sim.file')->sole()->attachments()->sole();
    $sample = tempnam(sys_get_temp_dir(), 'sample');
    file_put_contents($sample, "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF");

    SimulatedWebhook::attachLocalFile($attachment->load('message'), $sample, app(MediaStorage::class));
    unlink($sample);

    expect($attachment->refresh())
        ->status->toBe(AttachmentStatus::Available)
        ->mime_type->toBe('application/pdf')
        ->file_name->toBe('contrato.pdf');
});

test('simulated statuses can arrive duplicated and out of order', function () {
    $message = WhatsAppFixtures::outbound($this->conversation, ['status' => 'accepted', 'whatsapp_message_id' => 'wamid.sim.out']);
    $process = fn (string $status, int $at) => app(ProcessWebhookPayload::class)->handle($this->connection, SimulatedWebhook::status((string) $this->connection->phone_number_id, 'DEMO-WABA', 'wamid.sim.out', $status, $at));

    $process('read', now()->getTimestamp());
    $process('delivered', now()->subMinute()->getTimestamp());
    $process('delivered', now()->subMinute()->getTimestamp());

    expect($message->refresh())
        ->status->toBe(MessageStatus::Read)
        ->delivered_at->getTimestamp()->toBe(now()->subMinute()->getTimestamp());
});

<?php

use App\Enums\AttachmentStatus;
use App\Enums\MessageDirection;
use App\Enums\MessageType;
use App\Jobs\DownloadInboundMedia;
use App\Models\Message;
use App\Models\MessageAttachment;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\WebChatFixtures;
use Tests\Support\WebChatVisitor;
use Tests\TestCase;

/*
| Files a web chat visitor sends from the widget (POST session/files): they
| enter the same inbound pipeline as a text, are typed by their bytes, are
| stored in the contact's folder on the media disk like WhatsApp media,
| are idempotent by client id, and respect the widget's toggle.
*/

beforeEach(function () {
    Storage::fake((string) config('filesystems.media_disk', 'local'));
    Queue::fake();
    $this->connection = WebChatFixtures::connection();
    $this->token = WebChatVisitor::token($this, $this->connection);
});

const VISITOR_PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';

/**
 * @return TestResponse<Response>
 */
function sendVisitorFile(TestCase $test, string $token, UploadedFile $file, ?string $clientId = null, array $extra = []): TestResponse
{
    return $test->withToken($token)->post(route('api.v1.widget.session.files.store'), [
        'client_message_id' => $clientId ?? (string) Str::uuid(),
        'file' => $file,
        ...$extra,
    ], ['Accept' => 'application/json']);
}

function visitorPng(string $name = 'foto.png'): UploadedFile
{
    return UploadedFile::fake()->createWithContent($name, base64_decode(VISITOR_PNG));
}

test('an image is stored in the contact folder and recorded as an inbound image', function () {
    $response = sendVisitorFile($this, $this->token, visitorPng())->assertCreated();

    $message = Message::query()->sole();
    $attachment = MessageAttachment::query()->sole();
    $disk = (string) config('filesystems.media_disk', 'local');

    expect($message->direction)->toBe(MessageDirection::Inbound)
        ->and($message->type)->toBe(MessageType::Image)
        ->and($attachment->status)->toBe(AttachmentStatus::Available)
        ->and($attachment->mime_type)->toBe('image/png')
        ->and($attachment->path)->toStartWith('Chat web/')->toContain('Foto recibida')
        ->and(Storage::disk($disk)->exists((string) $attachment->path))->toBeTrue()
        ->and($response->json('data.attachments.0.url'))->toBeString();

    Queue::assertNotPushed(DownloadInboundMedia::class);
});

test('retrying the same client id stores the file once', function () {
    $id = (string) Str::uuid();

    sendVisitorFile($this, $this->token, visitorPng(), $id)->assertCreated();
    sendVisitorFile($this, $this->token, visitorPng(), $id)->assertOk();

    expect(MessageAttachment::query()->count())->toBe(1)
        ->and(Storage::disk((string) config('filesystems.media_disk', 'local'))->allFiles('Chat web'))->toHaveCount(1);
});

test('the type comes from the bytes, never from the name', function () {
    $html = UploadedFile::fake()->createWithContent('factura.pdf', '<!doctype html><html><script>alert(1)</script></html>');

    sendVisitorFile($this, $this->token, $html)->assertUnprocessable()->assertJsonValidationErrors(['file']);

    expect(Message::query()->count())->toBe(0);
});

test('a document carries its caption and original name', function () {
    $pdf = UploadedFile::fake()->createWithContent('cotización junio.pdf', "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF\n");

    sendVisitorFile($this, $this->token, $pdf, extra: ['body' => 'Mi cotización'])->assertCreated()->assertJsonPath('data.body', 'Mi cotización');

    expect(Message::query()->sole()->type)->toBe(MessageType::Document)
        ->and(MessageAttachment::query()->sole()->path)->toContain('Documento recibido - cotizacion junio');
});

test('the visitor sees and opens their own file', function () {
    $url = sendVisitorFile($this, $this->token, visitorPng())->assertCreated()->json('data.attachments.0.url');

    $this->get($url)->assertOk()->assertHeader('Content-Type', 'image/png');

    expect(collect(WebChatVisitor::messages($this, $this->token)->json('data'))->pluck('attachments')->flatten(1))->toHaveCount(1);
});

test('a widget that does not accept files refuses them', function () {
    $this->connection->webChatWidget->forceFill(['allow_visitor_files' => false])->save();

    sendVisitorFile($this, $this->token, visitorPng())->assertStatus(409)->assertJsonPath('code', 'files_disabled');

    expect(Message::query()->count())->toBe(0);
});

test('files have their own rate limit', function () {
    config(['webchat.visitor_files.per_session_per_minute' => 2]);

    sendVisitorFile($this, $this->token, visitorPng())->assertCreated();
    sendVisitorFile($this, $this->token, visitorPng())->assertCreated();
    sendVisitorFile($this, $this->token, visitorPng())->assertTooManyRequests();
});

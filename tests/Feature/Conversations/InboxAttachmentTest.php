<?php

use App\Enums\MessageDirection;
use App\Enums\MessageType;
use App\Events\Messaging\MessageRecorded;
use App\Models\Conversation;
use App\Models\Department;
use App\Models\Message;
use App\Models\MessageAttachment;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;

/*
| Files in replies: the real content type is read from the bytes, each type
| has its WhatsApp limit, files land on the private media disk and are only
| served through the authorized route, with headers that stop the browser
| from guessing or executing them.
*/

beforeEach(function () {
    Queue::fake();
    Storage::fake('local');
    config(['filesystems.media_disk' => 'local']);

    $this->department = Department::factory()->create();
    $this->agent = User::factory()->agent()->available()->inDepartments($this->department)->create();
    $this->colleague = User::factory()->agent()->available()->inDepartments($this->department)->create();
    $this->conversation = Conversation::factory()->inDepartment($this->department)->assignedTo($this->agent)->create();
});

/**
 * A minimal but genuine PDF document.
 */
function pdfUpload(string $name = 'cotizacion.pdf'): UploadedFile
{
    return UploadedFile::fake()->createWithContent($name, "%PDF-1.4\n1 0 obj << /Type /Catalog >> endobj\ntrailer << /Root 1 0 R >>\n%%EOF\n");
}

/**
 * Bytes of a minimal zip archive holding one empty entry.
 */
function zipBytes(string $entry): string
{
    $path = tempnam(sys_get_temp_dir(), 'zip');
    $zip = new ZipArchive;
    $zip->open($path, ZipArchive::OVERWRITE);
    $zip->addFromString($entry, 'contenido');
    $zip->close();
    $bytes = (string) file_get_contents($path);
    unlink($path);

    return $bytes;
}

function sendFile(object $test, UploadedFile $file, array $extra = []): TestResponse
{
    return $test->actingAs($test->agent)->post(route('conversations.messages.store', $test->conversation), [
        'client_reference' => (string) Str::uuid(),
        'attachment' => $file,
        ...$extra,
    ], ['Accept' => 'application/json']);
}

test('an image is stored privately and sent as an image message with its caption', function () {
    Event::fake([MessageRecorded::class]);

    $response = sendFile($this, UploadedFile::fake()->image('foto.png', 40, 40), ['body' => 'Así quedó'])
        ->assertStatus(202)
        ->assertJsonPath('data.type', 'image')
        ->assertJsonPath('data.body', 'Así quedó')
        ->assertJsonPath('data.attachments.0.mime_type', 'image/png')
        ->assertJsonPath('data.attachments.0.file_name', 'foto.png');

    $attachment = MessageAttachment::query()->sole();

    expect($attachment->path)->toStartWith('media/'.now()->format('Y/m').'/')
        ->and($attachment->path)->not->toContain('foto')
        ->and($attachment->sha256)->toHaveLength(64)
        ->and($response->json('data.attachments.0.url'))->toBe(route('conversations.messages.attachments.show', [$this->conversation, $attachment->message_id, $attachment]));
    Storage::disk('local')->assertExists($attachment->path);
    Event::assertDispatched(MessageRecorded::class, fn (MessageRecorded $event) => $event->messageId === $attachment->message_id && $event->direction === 'outbound');
});

test('a PDF is sent as a document', function () {
    sendFile($this, pdfUpload())
        ->assertStatus(202)
        ->assertJsonPath('data.type', 'document')
        ->assertJsonPath('data.attachments.0.mime_type', 'application/pdf');
});

test('a file is judged by its content, not by its name', function (Closure $file, string $error) {
    sendFile($this, $file())
        ->assertStatus(422)
        ->assertJsonValidationErrors(['attachment' => $error]);

    expect(Message::query()->count())->toBe(0)
        ->and(Storage::disk('local')->allFiles())->toBe([]);
})->with([
    'executable renamed as PDF' => [fn () => UploadedFile::fake()->createWithContent('factura.pdf', "MZ\x90\x00\x03\x00\x00\x00\x04\x00\x00\x00\xff\xff\x00\x00".str_repeat("\x00", 64).'This program cannot be run in DOS mode.'), 'no se puede enviar'],
    'HTML page renamed as image' => [fn () => UploadedFile::fake()->createWithContent('foto.jpg', '<!DOCTYPE html><html><script>alert(1)</script></html>'), 'no se puede enviar'],
    'zip archive WhatsApp would reject' => [fn () => UploadedFile::fake()->createWithContent('respaldo.zip', zipBytes('notas.txt')), 'no se puede enviar'],
    'zip archive renamed as PDF' => [fn () => UploadedFile::fake()->createWithContent('contrato.pdf', zipBytes('notas.txt')), 'no se puede enviar'],
]);

test('an Office file reported as a generic zip is sent with its Office type', function () {
    $file = UploadedFile::fake()->createWithContent('contrato.docx', zipBytes('contenido.xml'));

    expect((new finfo(FILEINFO_MIME_TYPE))->file($file->getRealPath()))->toBe('application/zip');

    sendFile($this, $file)->assertStatus(202)->assertJsonPath('data.type', 'document');

    expect(Message::query()->sole()->attachments()->sole()->mime_type)
        ->toBe('application/vnd.openxmlformats-officedocument.wordprocessingml.document');
});

test('each type keeps the WhatsApp size limit', function () {
    config(['whatsapp.media.types.image.max_bytes' => 1024]);

    sendFile($this, UploadedFile::fake()->image('grande.png', 400, 400)->size(5))
        ->assertStatus(422)
        ->assertJsonValidationErrors(['attachment' => 'El archivo pesa más de 1 KB']);
});

test('audio cannot carry a caption', function () {
    $audio = UploadedFile::fake()->createWithContent('nota.mp3', "ID3\x03\x00\x00\x00\x00\x00\x00".str_repeat("\xff\xfb\x90\x64".str_repeat("\x00", 413), 4));

    sendFile($this, $audio, ['body' => 'Escucha esto'])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['body']);
});

test('sending twice with the same reference stores one message and one file', function () {
    $reference = (string) Str::uuid();

    foreach ([1, 2] as $attempt) {
        $this->actingAs($this->agent)->post(route('conversations.messages.store', $this->conversation), [
            'client_reference' => $reference,
            'attachment' => pdfUpload(),
        ], ['Accept' => 'application/json'])->assertStatus(202);
    }

    expect(Message::query()->count())->toBe(1)
        ->and(MessageAttachment::query()->count())->toBe(1)
        ->and(Storage::disk('local')->allFiles())->toHaveCount(1);
});

test('only the responsible agent can attach files', function () {
    $this->actingAs($this->colleague)->post(route('conversations.messages.store', $this->conversation), [
        'client_reference' => (string) Str::uuid(),
        'attachment' => pdfUpload(),
    ], ['Accept' => 'application/json'])->assertNotFound();

    expect(Storage::disk('local')->allFiles())->toBe([]);
});

test('a reply may quote a message of the same conversation only', function () {
    $quoted = Message::factory()->create(['conversation_id' => $this->conversation->id]);
    $foreign = Message::factory()->create();

    $this->actingAs($this->agent)->postJson(route('conversations.messages.store', $this->conversation), [
        'client_reference' => (string) Str::uuid(),
        'body' => 'Sobre esto',
        'reply_to_message_id' => $quoted->id,
    ])->assertStatus(202)->assertJsonPath('data.reply_to.id', $quoted->id);

    $this->actingAs($this->agent)->postJson(route('conversations.messages.store', $this->conversation), [
        'client_reference' => (string) Str::uuid(),
        'body' => 'Intento',
        'reply_to_message_id' => $foreign->id,
    ])->assertStatus(422)->assertJsonValidationErrors(['reply_to_message_id']);
});

/**
 * An attachment stored on the private disk for a message of the conversation.
 */
function storedAttachment(Conversation $conversation, string $mime, string $name, string $content): MessageAttachment
{
    $message = Message::factory()->create([
        'conversation_id' => $conversation->id,
        'direction' => MessageDirection::Inbound,
        'type' => MessageType::Document,
    ]);
    $path = 'media/2026/10/'.Str::uuid();
    Storage::disk('local')->put($path, $content);

    $attachment = new MessageAttachment(['disk' => 'local', 'path' => $path, 'mime_type' => $mime, 'file_name' => $name, 'size_bytes' => strlen($content)]);
    $attachment->forceFill(['message_id' => $message->id])->save();

    return $attachment;
}

test('files open inline only when safe and always with protective headers', function () {
    $image = storedAttachment($this->conversation, 'image/png', 'foto.png', 'png-bytes');
    $page = storedAttachment($this->conversation, 'text/html', 'pagina.html', '<script>alert(1)</script>');

    $inline = $this->actingAs($this->agent)
        ->get(route('conversations.messages.attachments.show', [$this->conversation, $image->message_id, $image]))
        ->assertOk()
        ->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeader('Content-Type', 'image/png');
    expect($inline->headers->get('Content-Disposition'))->toStartWith('inline')
        ->and($inline->headers->get('Content-Security-Policy'))->toContain('sandbox');

    $forced = $this->actingAs($this->agent)
        ->get(route('conversations.messages.attachments.show', [$this->conversation, $image->message_id, $image, 'download' => 1]))
        ->assertOk()
        ->assertHeader('Content-Type', 'application/octet-stream');
    expect($forced->headers->get('Content-Disposition'))->toStartWith('attachment');

    $html = $this->actingAs($this->agent)
        ->get(route('conversations.messages.attachments.show', [$this->conversation, $page->message_id, $page]))
        ->assertOk()
        ->assertHeader('Content-Type', 'application/octet-stream');
    expect($html->headers->get('Content-Disposition'))->toStartWith('attachment');
});

test('attachments follow the visibility of their conversation and their own ids', function () {
    $attachment = storedAttachment($this->conversation, 'application/pdf', 'contrato.pdf', '%PDF-1.4');
    $otherConversation = Conversation::factory()->inDepartment($this->department)->assignedTo($this->agent)->create();

    $this->actingAs($this->colleague)
        ->get(route('conversations.messages.attachments.show', [$this->conversation, $attachment->message_id, $attachment]))
        ->assertNotFound();

    // Mixing ids of a visible conversation with another one's file.
    $this->actingAs($this->agent)
        ->get(route('conversations.messages.attachments.show', [$otherConversation, $attachment->message_id, $attachment]))
        ->assertNotFound();

    $otherMessage = Message::factory()->create(['conversation_id' => $this->conversation->id]);
    $this->actingAs($this->agent)
        ->get(route('conversations.messages.attachments.show', [$this->conversation, $otherMessage, $attachment]))
        ->assertNotFound();
});

test('a missing or not yet downloaded file answers not found', function () {
    $attachment = storedAttachment($this->conversation, 'application/pdf', 'contrato.pdf', '%PDF-1.4');
    Storage::disk('local')->delete($attachment->path);

    $this->actingAs($this->agent)
        ->get(route('conversations.messages.attachments.show', [$this->conversation, $attachment->message_id, $attachment]))
        ->assertNotFound();

    $attachment->forceFill(['path' => null])->save();

    $this->actingAs($this->agent)
        ->get(route('conversations.messages.attachments.show', [$this->conversation, $attachment->message_id, $attachment]))
        ->assertNotFound();
});

test('the composer receives the same limits the server enforces', function () {
    config(['whatsapp.media.types.video.max_bytes' => 2048]);

    $this->actingAs($this->agent)
        ->get(route('conversations.index', ['c' => $this->conversation->id]))
        ->assertInertia(fn (Assert $page) => $page
            ->where('composer.attachments', fn ($rules) => collect($rules)->firstWhere('type', 'video')['max_bytes'] === 2048
                && in_array('application/pdf', collect($rules)->firstWhere('type', 'document')['mimes'], true)));
});

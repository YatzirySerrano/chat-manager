<?php

use App\Enums\AttachmentDeliveryMethod;
use App\Enums\AuditAction;
use App\Enums\ConnectionChannel;
use App\Enums\ConnectionProvider;
use App\Enums\MessageStatus;
use App\Jobs\DeliverOutboundMessage;
use App\Models\AuditLog;
use App\Models\Connection;
use App\Models\ContactIdentity;
use App\Models\Conversation;
use App\Models\Department;
use App\Models\Message;
use App\Models\MessageAttachment;
use App\Models\User;
use App\Support\Attachments\AttachmentDownloadLink;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

/*
| Per channel attachment policy (ChannelAttachmentPolicy): each channel's
| documented types and sizes, judged by the real content and a matching
| extension; ZIP archives, which WhatsApp cannot carry, travel as a signed
| expiring download link served by the public attachments.link route.
*/

beforeEach(function () {
    Queue::fake();
    Storage::fake('local');
    config(['filesystems.media_disk' => 'local']);
    $this->travelTo(CarbonImmutable::parse('2026-10-20 15:00:00', 'UTC'));

    $this->department = Department::factory()->create();
    $this->agent = User::factory()->agent()->inDepartments($this->department)->create();
});

function policyConversation(object $test, ConnectionChannel $channel = ConnectionChannel::Whatsapp): Conversation
{
    $connection = match ($channel) {
        ConnectionChannel::Whatsapp => Connection::factory()->verified()->create(['phone_number_id' => '106540352242922', 'access_token' => 'EAAGpolicyToken']),
        ConnectionChannel::WebChat => Connection::factory()->webChat()->create(),
        ConnectionChannel::Messenger, ConnectionChannel::Instagram => Connection::factory()->unconfigured()->create([
            'channel' => $channel,
            'provider' => $channel === ConnectionChannel::Messenger ? ConnectionProvider::MetaMessenger : ConnectionProvider::MetaInstagram,
            'external_account_id' => '17841400000000000',
            'access_token' => 'EAAGpageToken',
        ]),
    };

    return Conversation::factory()->inDepartment($test->department)->assignedTo($test->agent)->create([
        'connection_id' => $connection->id,
        'last_inbound_at' => now()->subHour(),
    ]);
}

function postAttachment(object $test, Conversation $conversation, UploadedFile $file, array $extra = []): TestResponse
{
    return $test->actingAs($test->agent)->post(route('conversations.messages.store', $conversation), [
        'client_reference' => (string) Str::uuid(),
        'attachment' => $file,
        ...$extra,
    ], ['Accept' => 'application/json']);
}

function archiveBytes(): string
{
    $path = tempnam(sys_get_temp_dir(), 'zip');
    $zip = new ZipArchive;
    $zip->open($path, ZipArchive::OVERWRITE);
    $zip->addFromString('notas.txt', 'contenido');
    $zip->close();
    $bytes = (string) file_get_contents($path);
    unlink($path);

    return $bytes;
}

/**
 * The first page of an Ogg stream whose identification header is the given packet.
 */
function oggPage(string $packet): string
{
    return "OggS\x00\x02".str_repeat("\x00", 8)."\x01\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x01".chr(strlen($packet)).$packet;
}

dataset('whatsapp native files', [
    'JPEG image' => [fn () => UploadedFile::fake()->image('foto.jpg', 20, 20), 'image'],
    'PDF document' => [fn () => UploadedFile::fake()->createWithContent('cotizacion.pdf', "%PDF-1.4\n%%EOF\n"), 'document'],
    'text document' => [fn () => UploadedFile::fake()->createWithContent('notas.txt', "hola mundo\n"), 'document'],
    'MP3 audio' => [fn () => UploadedFile::fake()->createWithContent('nota.mp3', "ID3\x03\x00\x00\x00\x00\x00\x00".str_repeat("\xff\xfb\x90\x64".str_repeat("\x00", 413), 4)), 'audio'],
    'M4A audio' => [fn () => UploadedFile::fake()->createWithContent('nota.m4a', "\x00\x00\x00\x18ftypM4A \x00\x00\x00\x00M4A isom".str_repeat("\x00", 32)), 'audio'],
    'OGG Opus mono audio' => [fn () => UploadedFile::fake()->createWithContent('voz.ogg', oggPage("OpusHead\x01\x01\x38\x01\x80\xbb\x00\x00\x00\x00\x00")), 'audio'],
    'MP4 video' => [fn () => UploadedFile::fake()->createWithContent('video.mp4', "\x00\x00\x00\x18ftypmp42\x00\x00\x00\x00mp42isom".str_repeat("\x00", 32)), 'video'],
    '3GPP video' => [fn () => UploadedFile::fake()->createWithContent('video.3gp', "\x00\x00\x00\x14ftyp3gp4\x00\x00\x00\x003gp4".str_repeat("\x00", 32)), 'video'],
]);

test('WhatsApp accepts each documented type natively', function (Closure $file, string $type) {
    postAttachment($this, policyConversation($this), $file())
        ->assertStatus(202)
        ->assertJsonPath('data.type', $type)
        ->assertJsonPath('data.attachments.0.delivery_method', 'native');
})->with('whatsapp native files');

test('WhatsApp refuses files over the limit of their type', function (Closure $file, string $error) {
    postAttachment($this, policyConversation($this), $file())
        ->assertStatus(422)
        ->assertJsonValidationErrors(['attachment' => $error]);

    expect(Message::query()->count())->toBe(0);
})->with([
    'image over 5 MB' => [fn () => UploadedFile::fake()->image('foto.png', 20, 20)->size(5 * 1024 + 1), 'Esta imagen supera el tamaño permitido para WhatsApp (5 MB)'],
    'video over 16 MB' => [fn () => UploadedFile::fake()->createWithContent('video.mp4', "\x00\x00\x00\x18ftypmp42\x00\x00\x00\x00mp42isom".str_repeat("\x00", 32))->size(16 * 1024 + 1), 'Este video supera el tamaño permitido para WhatsApp (16 MB)'],
    'document over 100 MB' => [fn () => UploadedFile::fake()->createWithContent('manual.pdf', "%PDF-1.4\n%%EOF\n")->size(100 * 1024 + 1), 'El archivo pesa más de 100 MB'],
]);

test('a file whose extension does not match its content is refused', function () {
    postAttachment($this, policyConversation($this), UploadedFile::fake()->image('factura.pdf', 20, 20))
        ->assertStatus(422)
        ->assertJsonValidationErrors(['attachment' => 'no corresponde a su contenido real']);
});

test('WhatsApp refuses Ogg audio that is not Opus', function () {
    $vorbis = UploadedFile::fake()->createWithContent('cancion.ogg', oggPage("\x01vorbis\x00\x00\x00\x00\x01\x44\xac\x00\x00".str_repeat("\x00", 14)));

    postAttachment($this, policyConversation($this), $vorbis)
        ->assertStatus(422)
        ->assertJsonValidationErrors(['attachment' => 'Opus en mono']);
});

test('executables and other unsupported types are refused with the accepted list', function () {
    postAttachment($this, policyConversation($this), UploadedFile::fake()->createWithContent('instalador.exe', "MZ\x90\x00\x03\x00\x00\x00\x04\x00\x00\x00\xff\xff\x00\x00".str_repeat("\x00", 64)))
        ->assertStatus(422)
        ->assertJsonValidationErrors(['attachment' => 'Este tipo de archivo no se puede enviar por WhatsApp. Puedes adjuntar imágenes (JPG, JPEG, PNG)']);
});

test('a ZIP to WhatsApp is stored privately and delivered as a signed expiring link', function () {
    config(['whatsapp.transport' => 'meta', 'services.meta.graph_version' => 'v26.0']);
    Http::preventStrayRequests();
    Http::fake(['graph.facebook.com/v26.0/106540352242922/messages' => Http::response(['messages' => [['id' => 'wamid.link.1']]])]);
    $conversation = policyConversation($this);
    ContactIdentity::factory()->create(['contact_id' => $conversation->contact_id, 'external_id' => '5215511112222']);

    postAttachment($this, $conversation, UploadedFile::fake()->createWithContent('respaldo.zip', archiveBytes()), ['body' => 'Te comparto el respaldo'])
        ->assertStatus(202)
        ->assertJsonPath('data.type', 'document')
        ->assertJsonPath('data.attachments.0.delivery_method', 'link')
        ->assertJsonPath('data.attachments.0.link_expires_at', now()->addDays(7)->toIso8601String());

    $attachment = MessageAttachment::query()->sole();
    $message = $attachment->message;

    expect($attachment->delivery_method)->toBe(AttachmentDeliveryMethod::Link)
        ->and($attachment->mime_type)->toBe('application/zip');
    Storage::disk('local')->assertExists($attachment->path);

    app()->call([new DeliverOutboundMessage($message->id), 'handle']);

    $url = app(AttachmentDownloadLink::class)->url($attachment, now()->addDays(7)->toImmutable());

    Http::assertSent(fn (Request $request): bool => $request['type'] === 'text'
        && str_starts_with($request['text']['body'], 'Te comparto el respaldo')
        && str_contains($request['text']['body'], 'respaldo.zip')
        && str_contains($request['text']['body'], 'vence el 27/10/2026')
        && str_contains($request['text']['body'], $url));
    Http::assertNotSent(fn (Request $request): bool => str_ends_with($request->url(), '/media'));
    expect($message->refresh()->status)->toBe(MessageStatus::Accepted);
});

test('the signed link serves the file and refuses altered or expired signatures', function () {
    $conversation = policyConversation($this);
    postAttachment($this, $conversation, UploadedFile::fake()->createWithContent('respaldo.zip', archiveBytes()))->assertStatus(202);
    $attachment = MessageAttachment::query()->sole();
    $url = app(AttachmentDownloadLink::class)->url($attachment, AttachmentDownloadLink::expiresAt($attachment->message));

    auth()->logout();

    $download = $this->get($url)
        ->assertOk()
        ->assertHeader('Content-Type', 'application/octet-stream')
        ->assertHeader('X-Content-Type-Options', 'nosniff');
    expect($download->headers->get('Content-Disposition'))->toStartWith('attachment')->toContain('respaldo.zip');

    $this->get(str_replace('signature=', 'signature=0', $url))->assertForbidden()->assertSee('no es válido o ya venció');
    $this->get(route('attachments.link', $attachment))->assertForbidden();

    $this->travel(8)->days();
    $this->get($url)->assertForbidden();
});

test('a valid signature never serves files that were not sent as a link', function () {
    $conversation = policyConversation($this);
    postAttachment($this, $conversation, UploadedFile::fake()->createWithContent('cotizacion.pdf', "%PDF-1.4\n%%EOF\n"))->assertStatus(202);
    $attachment = MessageAttachment::query()->sole();

    $this->get(app(AttachmentDownloadLink::class)->url($attachment, now()->addDay()->toImmutable()))->assertNotFound();
});

test('ZIP archives are refused on WhatsApp when link delivery is off', function () {
    config(['whatsapp.media.link_fallback' => false]);

    postAttachment($this, policyConversation($this), UploadedFile::fake()->createWithContent('respaldo.zip', archiveBytes()))
        ->assertStatus(422)
        ->assertJsonValidationErrors(['attachment' => 'no se puede enviar por WhatsApp']);
});

test('the web chat hosts ZIP archives natively, up to its own limit', function () {
    $conversation = policyConversation($this, ConnectionChannel::WebChat);

    postAttachment($this, $conversation, UploadedFile::fake()->createWithContent('respaldo.zip', archiveBytes()))
        ->assertStatus(202)
        ->assertJsonPath('data.type', 'document')
        ->assertJsonPath('data.attachments.0.delivery_method', 'native');

    postAttachment($this, $conversation, UploadedFile::fake()->createWithContent('manual.pdf', "%PDF-1.4\n%%EOF\n")->size(25 * 1024 + 1))
        ->assertStatus(422)
        ->assertJsonValidationErrors(['attachment' => 'El archivo pesa más de 25 MB']);
});

test('Instagram only accepts PDF documents and no text with files', function () {
    $conversation = policyConversation($this, ConnectionChannel::Instagram);
    $docx = function (): UploadedFile {
        return UploadedFile::fake()->createWithContent('contrato.docx', archiveBytes());
    };

    postAttachment($this, $conversation, $docx())
        ->assertStatus(422)
        ->assertJsonValidationErrors(['attachment' => 'no se puede enviar por Instagram']);

    postAttachment($this, $conversation, UploadedFile::fake()->createWithContent('cotizacion.pdf', "%PDF-1.4\n%%EOF\n"), ['body' => 'Aquí está'])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['body' => 'Instagram no permite texto junto a un archivo']);

    expect(Message::query()->count())->toBe(0);
});

test('a file PHP could not receive gets a clear explanation', function () {
    $path = tempnam(sys_get_temp_dir(), 'upl');
    file_put_contents($path, '%PDF-1.4');
    $tooLarge = new UploadedFile($path, 'manual.pdf', 'application/pdf', UPLOAD_ERR_INI_SIZE, true);

    postAttachment($this, policyConversation($this), $tooLarge)
        ->assertStatus(422)
        ->assertJsonValidationErrors(['attachment' => 'El servidor de Chat Manager no pudo recibir el archivo']);
});

test('the composer receives the policy of every channel', function () {
    config(['whatsapp.media.types.video.max_bytes' => 2048]);
    $conversation = policyConversation($this);

    $this->actingAs($this->agent)
        ->get(route('conversations.index', ['c' => $conversation->id]))
        ->assertInertia(fn ($page) => $page
            ->where('composer.attachments.whatsapp.kinds', fn ($kinds) => collect($kinds)->firstWhere('key', 'video')['max_bytes'] === 2048
                && collect($kinds)->firstWhere('key', 'archive')['delivery'] === 'link'
                && collect($kinds)->firstWhere('key', 'audio')['caption'] === false)
            ->where('composer.attachments.web_chat.kinds', fn ($kinds) => collect($kinds)->firstWhere('key', 'archive')['delivery'] === 'native')
            ->where('composer.attachments.instagram.kinds', fn ($kinds) => collect($kinds)->firstWhere('key', 'document')['mimes'] === ['application/pdf']));
});

test('Messenger and Instagram refuse files over the limit of their type', function (ConnectionChannel $channel, Closure $file, string $error) {
    postAttachment($this, policyConversation($this, $channel), $file())
        ->assertStatus(422)
        ->assertJsonValidationErrors(['attachment' => $error]);

    expect(Message::query()->count())->toBe(0);
})->with([
    'Messenger image over 8 MB' => [ConnectionChannel::Messenger, fn () => UploadedFile::fake()->image('foto.png', 20, 20)->size(8 * 1024 + 1), 'Esta imagen supera el tamaño permitido para Facebook Messenger (8 MB)'],
    'Messenger video over 25 MB' => [ConnectionChannel::Messenger, fn () => UploadedFile::fake()->createWithContent('video.mp4', "\x00\x00\x00\x18ftypmp42\x00\x00\x00\x00mp42isom".str_repeat("\x00", 32))->size(25 * 1024 + 1), 'Este video supera el tamaño permitido para Facebook Messenger (25 MB)'],
    'Messenger document over 25 MB' => [ConnectionChannel::Messenger, fn () => UploadedFile::fake()->createWithContent('manual.pdf', "%PDF-1.4\n%%EOF\n")->size(25 * 1024 + 1), 'Este documento supera el tamaño permitido para Facebook Messenger (25 MB)'],
    'Instagram image over 8 MB' => [ConnectionChannel::Instagram, fn () => UploadedFile::fake()->image('foto.png', 20, 20)->size(8 * 1024 + 1), 'Esta imagen supera el tamaño permitido para Instagram (8 MB)'],
    'Instagram video over 25 MB' => [ConnectionChannel::Instagram, fn () => UploadedFile::fake()->createWithContent('video.mp4', "\x00\x00\x00\x18ftypmp42\x00\x00\x00\x00mp42isom".str_repeat("\x00", 32))->size(25 * 1024 + 1), 'Este video supera el tamaño permitido para Instagram (25 MB)'],
    'Instagram GIF image' => [ConnectionChannel::Instagram, fn () => UploadedFile::fake()->image('animacion.gif', 20, 20), 'no se puede enviar por Instagram'],
]);

test('Messenger accepts GIF images natively and Instagram sends ZIP archives as a download link', function () {
    postAttachment($this, policyConversation($this, ConnectionChannel::Messenger), UploadedFile::fake()->image('animacion.gif', 20, 20))
        ->assertStatus(202)
        ->assertJsonPath('data.type', 'image')
        ->assertJsonPath('data.attachments.0.delivery_method', 'native');

    postAttachment($this, policyConversation($this, ConnectionChannel::Instagram), UploadedFile::fake()->createWithContent('respaldo.zip', archiveBytes()))
        ->assertStatus(202)
        ->assertJsonPath('data.type', 'document')
        ->assertJsonPath('data.attachments.0.delivery_method', 'link')
        ->assertJsonPath('data.attachments.1', null);
});

test('the sender can revoke a download link, which then answers 410 and is audited', function () {
    $conversation = policyConversation($this);
    postAttachment($this, $conversation, UploadedFile::fake()->createWithContent('respaldo.zip', archiveBytes()))->assertStatus(202);
    $attachment = MessageAttachment::query()->sole();
    $message = $attachment->message;
    $url = app(AttachmentDownloadLink::class)->url($attachment, AttachmentDownloadLink::expiresAt($message));
    $revoke = route('conversations.messages.attachments.revoke-link', ['conversation' => $conversation, 'message' => $message, 'attachment' => $attachment]);

    $this->actingAs($this->agent)->postJson($revoke)->assertOk()->assertJsonPath('data.revoked', true);
    // Revoking again changes nothing.
    $this->actingAs($this->agent)->postJson($revoke)->assertOk();

    auth()->logout();
    $this->get($url)->assertStatus(410)->assertSee('Este enlace de descarga fue desactivado');

    expect(AttachmentDownloadLink::isRevoked($message->refresh()))->toBeTrue()
        ->and($message->metadata['download_link']['revoked_by_user_id'])->toBe($this->agent->id)
        ->and(AuditLog::query()->where('action', AuditAction::AttachmentLinkRevoked)->sole())
        ->user_id->toBe($this->agent->id)
        ->properties->toMatchArray(['message_id' => $message->id, 'attachment_id' => $attachment->id]);
});

test('only the sender or someone who can reply may revoke a download link', function () {
    $conversation = policyConversation($this);
    postAttachment($this, $conversation, UploadedFile::fake()->createWithContent('respaldo.zip', archiveBytes()))->assertStatus(202);
    $attachment = MessageAttachment::query()->sole();
    $revoke = route('conversations.messages.attachments.revoke-link', ['conversation' => $conversation, 'message' => $attachment->message_id, 'attachment' => $attachment]);
    // A supervisor sees the conversation but does not attend it.
    $colleague = User::factory()->supervisor()->inDepartments($this->department)->create();
    $outsider = User::factory()->agent()->create();
    $native = postAttachment($this, $conversation, UploadedFile::fake()->createWithContent('cotizacion.pdf', "%PDF-1.4\n%%EOF\n"))->json('data');

    $this->actingAs($outsider)->postJson($revoke)->assertNotFound();
    $this->actingAs($colleague)->postJson($revoke)->assertForbidden();
    $this->actingAs($this->agent)->postJson(route('conversations.messages.attachments.revoke-link', ['conversation' => $conversation, 'message' => $native['id'], 'attachment' => $native['attachments'][0]['id']]))
        ->assertStatus(409);

    expect(AttachmentDownloadLink::isRevoked($attachment->message->refresh()))->toBeFalse()
        ->and(AuditLog::query()->where('action', AuditAction::AttachmentLinkRevoked)->count())->toBe(0);
});

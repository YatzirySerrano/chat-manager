<?php

use App\Enums\MessageStatus;
use App\Jobs\DeliverOutboundMessage;
use App\Models\Connection;
use App\Models\ContactIdentity;
use App\Models\Conversation;
use App\Models\Department;
use App\Models\Message;
use App\Models\MessageAttachment;
use App\Models\TeamChat\TeamAttachment;
use App\Models\TeamChat\TeamThread;
use App\Models\User;
use App\Services\Meta\MetaGraphClient;
use App\Services\Operations\SystemHealth;
use App\Support\Attachments\ServerUploadLimits;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

/*
| A file that PHP refused (UPLOAD_ERR_*), a request larger than
| post_max_size and the channel's own refusals each get their own
| explanation and stable code on the attachment field, never "Escribe un
| mensaje o adjunta un archivo". A file alone (no text) is a valid reply.
*/

beforeEach(function () {
    Queue::fake();
    Storage::fake('local');
    config(['filesystems.media_disk' => 'local']);
    $this->travelTo(CarbonImmutable::parse('2026-10-20 15:00:00', 'UTC'));

    $this->department = Department::factory()->create();
    $this->agent = User::factory()->agent()->inDepartments($this->department)->create();
});

function uploadsConversation(object $test, bool $webChat = false): Conversation
{
    $connection = $webChat
        ? Connection::factory()->webChat()->create()
        : Connection::factory()->verified()->create(['phone_number_id' => '106540352242922', 'access_token' => 'EAAGuploadsToken']);

    return Conversation::factory()->inDepartment($test->department)->assignedTo($test->agent)->create([
        'connection_id' => $connection->id,
        'last_inbound_at' => now()->subHour(),
    ]);
}

/**
 * @param  array<string, mixed>  $data
 */
function uploadsPost(object $test, Conversation $conversation, array $data): TestResponse
{
    return $test->actingAs($test->agent)->post(route('conversations.messages.store', $conversation), [
        'client_reference' => (string) Str::uuid(),
        ...$data,
    ], ['Accept' => 'application/json']);
}

/**
 * A file exactly as PHP hands over a refused upload: no temporary file.
 */
function uploadRefusedByPhp(int $error, string $name = 'foto.png'): UploadedFile
{
    return new UploadedFile('', $name, null, $error, true);
}

/**
 * @return array<string, Closure(): UploadedFile>
 */
function uploadsSamples(): array
{
    return [
        'image' => fn () => UploadedFile::fake()->image('foto.png', 20, 20),
        'video' => fn () => UploadedFile::fake()->createWithContent('video.mp4', "\x00\x00\x00\x18ftypmp42\x00\x00\x00\x00mp42isom".str_repeat("\x00", 32)),
        'document' => fn () => UploadedFile::fake()->createWithContent('cotizacion.pdf', "%PDF-1.4\n1 0 obj << /Type /Catalog >> endobj\ntrailer << /Root 1 0 R >>\n%%EOF\n"),
        'audio' => fn () => UploadedFile::fake()->createWithContent('nota.mp3', "ID3\x03\x00\x00\x00\x00\x00\x00".str_repeat("\xff\xfb\x90\x64".str_repeat("\x00", 413), 4)),
    ];
}

dataset('files without text', fn (): array => collect(['WhatsApp' => false, 'web chat' => true])
    ->flatMap(fn (bool $webChat, string $channel): array => collect(uploadsSamples())
        ->mapWithKeys(fn (Closure $file, string $type): array => ["{$channel} {$type}" => [$webChat, $file, $type]])
        ->all())
    ->all());

test('a file without text is sent on WhatsApp and the web chat', function (bool $webChat, Closure $file, string $type) {
    $conversation = uploadsConversation($this, $webChat);

    uploadsPost($this, $conversation, ['body' => '', 'attachment' => $file()])
        ->assertStatus(202)
        ->assertJsonPath('data.type', $type)
        ->assertJsonPath('data.body', null);

    expect(MessageAttachment::query()->count())->toBe(1);
    Queue::assertPushed(DeliverOutboundMessage::class, 1);
})->with('files without text');

test('text alone and a file with its caption are sent', function () {
    $conversation = uploadsConversation($this);

    uploadsPost($this, $conversation, ['body' => 'Hola'])->assertStatus(202)->assertJsonPath('data.type', 'text');
    uploadsPost($this, $conversation, ['body' => 'Su cotización', 'attachment' => uploadsSamples()['document']()])
        ->assertStatus(202)
        ->assertJsonPath('data.type', 'document')
        ->assertJsonPath('data.body', 'Su cotización');
});

test('nothing to send asks for a message or a file', function () {
    uploadsPost($this, uploadsConversation($this), ['body' => '  '])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['body' => 'Escribe un mensaje o adjunta un archivo.'])
        ->assertJsonMissingValidationErrors(['attachment']);
});

test('a file PHP refused is explained by its cause, never as a missing message', function (int $error, string $code, string $explanation) {
    app()->instance(ServerUploadLimits::class, new ServerUploadLimits('2M', '8M'));

    $conversation = uploadsConversation($this);

    foreach (['', 'Le envío el video'] as $body) {
        uploadsPost($this, $conversation, ['body' => $body, 'attachment' => uploadRefusedByPhp($error)])
            ->assertStatus(422)
            ->assertJsonPath('code', $code)
            ->assertJsonPath('errors.upload_code.0', $code)
            ->assertJsonValidationErrors(['attachment' => $explanation])
            ->assertJsonMissingValidationErrors(['body']);
    }

    expect(Message::query()->count())->toBe(0)
        ->and(Storage::disk('local')->allFiles())->toBe([]);
})->with([
    'larger than upload_max_filesize' => [UPLOAD_ERR_INI_SIZE, 'server_upload_limit', 'El servidor de Chat Manager no pudo recibir el archivo: acepta hasta 2.1 MB, menos de lo que permite WhatsApp. Un administrador puede ampliar el límite.'],
    // MAX_FILE_SIZE is a form field the client controls: not a server limit.
    'larger than MAX_FILE_SIZE' => [UPLOAD_ERR_FORM_SIZE, 'upload_invalid', 'El archivo no se recibió correctamente. Vuelve a enviarlo.'],
    'interrupted upload' => [UPLOAD_ERR_PARTIAL, 'upload_interrupted', 'probablemente por la conexión'],
    'no temporary directory' => [UPLOAD_ERR_NO_TMP_DIR, 'server_upload_failure', 'problema temporal del servidor'],
    'temporary file not written' => [UPLOAD_ERR_CANT_WRITE, 'server_upload_failure', 'problema temporal del servidor'],
]);

test('a request larger than post_max_size is answered as a server limit', function () {
    app()->instance(ServerUploadLimits::class, new ServerUploadLimits('2M', '8M'));
    $conversation = uploadsConversation($this);
    $tooLarge = ['CONTENT_LENGTH' => (string) (ServerUploadLimits::bytes((string) ini_get('post_max_size')) + 1)];

    $this->actingAs($this->agent)
        ->call('POST', route('conversations.messages.store', $conversation), [], [], [], [...$tooLarge, 'HTTP_ACCEPT' => 'application/json'])
        ->assertStatus(413)
        ->assertJsonPath('code', 'server_upload_limit')
        ->assertJsonPath('message', fn (string $message): bool => str_contains($message, 'El servidor de Chat Manager no pudo recibir el archivo') && ! str_contains($message, 'Escribe un mensaje'));

    $this->actingAs($this->agent)
        ->from(route('conversations.index', ['c' => $conversation->id]))
        ->call('POST', route('conversations.messages.store', $conversation), [], [config('session.cookie') => 'session-of-the-page'], [], [...$tooLarge, 'HTTP_X_INERTIA' => 'true', 'HTTP_REFERER' => route('conversations.index', ['c' => $conversation->id])])
        ->assertRedirect(route('conversations.index', ['c' => $conversation->id]))
        ->assertSessionHasErrors(['attachment' => app(ServerUploadLimits::class)->limitMessage()]);

    expect(Message::query()->count())->toBe(0);
});

test('a request larger than post_max_size without a page session gets a stateless 413', function (string $path, bool $withCookie) {
    $cookies = $withCookie ? [config('session.cookie') => 'x'] : [];
    $response = $this->call('POST', $path, [], $cookies, [], ['CONTENT_LENGTH' => (string) (ServerUploadLimits::bytes((string) ini_get('post_max_size')) + 1)]);

    $response->assertStatus(413);

    expect($response->headers->getCookies())->toBe([])
        ->and($response->getContent())->toContain('El servidor de Chat Manager no pudo recibir el archivo');
})->with([
    'webhook' => ['/webhooks/meta', true],
    'broadcasting' => ['/broadcasting/auth', true],
    'page without session cookie' => ['/login', false],
]);

test('a file over the channel limit is refused with the limit of its category', function (Closure $file, string $code, string $explanation) {
    uploadsPost($this, uploadsConversation($this), ['attachment' => $file()])
        ->assertStatus(422)
        ->assertJsonPath('code', $code)
        ->assertJsonValidationErrors(['attachment' => $explanation])
        ->assertJsonMissingValidationErrors(['body']);
})->with([
    'image over 5 MB' => [fn () => UploadedFile::fake()->image('foto.png', 20, 20)->size(5 * 1024 + 300), 'channel_size_limit', 'Esta imagen supera el tamaño permitido para WhatsApp (5 MB): pesa 5.6 MB.'],
    'video of 16.2 decimal MB (under 16 MiB)' => [fn () => uploadsSamples()['video']()->size(15821), 'channel_size_limit', 'Este video supera el tamaño permitido para WhatsApp (16 MB): pesa 16.2 MB.'],
    'video just over 16 MB' => [fn () => uploadsSamples()['video']()->size(15626), 'channel_size_limit', 'Este video supera el tamaño permitido para WhatsApp (16 MB): pesa más de 16 MB.'],
    'audio over 16 MB' => [fn () => uploadsSamples()['audio']()->size(17 * 1024), 'channel_size_limit', 'Este audio supera el tamaño permitido para WhatsApp (16 MB)'],
    'renamed file' => [fn () => UploadedFile::fake()->image('foto.mp4', 20, 20), 'extension_mismatch', 'no corresponde a su contenido real (JPG)'],
    'corrupt file' => [fn () => UploadedFile::fake()->createWithContent('foto.png', random_bytes(2048)), 'unsupported_type', 'Este tipo de archivo no se puede enviar por WhatsApp'],
]);

test('files just under the decimal limits of WhatsApp are sent', function (Closure $file, string $type) {
    uploadsPost($this, uploadsConversation($this), ['attachment' => $file()])
        ->assertStatus(202)
        ->assertJsonPath('data.type', $type);
})->with([
    'image of 4.9 MB' => [fn () => UploadedFile::fake()->image('foto.png', 20, 20)->size(4785), 'image'],
    'video of 15.9 MB' => [fn () => uploadsSamples()['video']()->size(15528), 'video'],
    'document of 99 MB' => [fn () => uploadsSamples()['document']()->size(96680), 'document'],
]);

test('the media disk being down refuses the file with 503 and keeps nothing', function () {
    config(['filesystems.media_disk' => 'missing-disk']);

    uploadsPost($this, uploadsConversation($this), ['attachment' => uploadsSamples()['image']()])
        ->assertStatus(503)
        ->assertJsonPath('code', 'storage_unavailable');

    expect(Message::query()->count())->toBe(0);
});

test('a refused upload to Meta fails the message without sending it', function () {
    Http::preventStrayRequests();
    config(['whatsapp.transport' => 'meta', 'services.meta.graph_version' => 'v26.0']);
    $conversation = uploadsConversation($this);
    ContactIdentity::factory()->create(['contact_id' => $conversation->contact_id, 'external_id' => '5215511112222']);
    Http::fake([
        'graph.facebook.com/v26.0/106540352242922/media' => Http::response(['error' => ['message' => 'Media upload error', 'type' => 'OAuthException', 'code' => 131053]], 400),
        'graph.facebook.com/v26.0/106540352242922/messages' => Http::response(['messages' => [['id' => 'wamid.never']]]),
    ]);

    uploadsPost($this, $conversation, ['attachment' => uploadsSamples()['document']()])->assertStatus(202);
    $message = Message::query()->sole();
    app()->call([new DeliverOutboundMessage($message->id), 'handle']);

    expect($message->refresh()->status)->toBe(MessageStatus::Failed)
        ->and($message->whatsapp_message_id)->toBeNull();
    Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/media') && $request->isMultipart());
    Http::assertNotSent(fn (Request $request): bool => str_ends_with($request->url(), '/messages'));
});

test('the composer and the health check know when the server limit is below the channel limit', function () {
    app()->instance(ServerUploadLimits::class, new ServerUploadLimits('2M', '8M'));
    $conversation = uploadsConversation($this);

    $this->actingAs($this->agent)
        ->get(route('conversations.index', ['c' => $conversation->id]))
        ->assertInertia(fn ($page) => $page
            ->where('composer.attachments.whatsapp.server.max_file_bytes', 2 * 1024 * 1024)
            ->where('composer.attachments.whatsapp.server.upload_max_filesize', '2M')
            ->where('composer.attachments.whatsapp.server.message', fn (string $message): bool => str_contains($message, 'acepta hasta 2.1 MB, menos de lo que permite WhatsApp'))
            ->where('composer.attachments.whatsapp.categories', fn ($categories) => collect($categories)->every(fn (array $category): bool => $category['server_limited'] === true)));

    $check = app(SystemHealth::class)->uploads();

    expect($check->status->value)->toBe('warning')
        ->and($check->summary)->toContain('upload_max_filesize (2M) es menor que el máximo del canal (100 MB)');

    app()->instance(ServerUploadLimits::class, new ServerUploadLimits('100M', '110M'));

    expect(app(SystemHealth::class)->uploads()->status->value)->toBe('ok');

    $this->actingAs($this->agent)
        ->get(route('conversations.index', ['c' => $conversation->id]))
        ->assertInertia(fn ($page) => $page
            ->where('composer.attachments.whatsapp.categories', fn ($categories) => collect($categories)->every(fn (array $category): bool => $category['server_limited'] === false)));
});

test('the media upload always leaves time to send the message within one delivery attempt', function (int $graphTimeout, int $uploadTimeout) {
    config(['services.meta.timeout' => $graphTimeout, 'services.meta.media_upload_timeout' => $uploadTimeout]);
    $upload = MetaGraphClient::uploadTimeout();

    expect($upload + $graphTimeout + min(5, $graphTimeout))->toBeLessThan(DeliverOutboundMessage::TIMEOUT)
        ->and($upload)->toBeGreaterThan(0)
        ->and((new DeliverOutboundMessage(1))->timeout)->toBe(DeliverOutboundMessage::TIMEOUT);

    // The worker running the messages queue must outlive the job, and the
    // queue must not hand a running job to another worker.
    foreach (['deploy/supervisor/chat-manager.conf', 'deploy/systemd/chat-manager-worker@.service'] as $file) {
        preg_match('/--queue=messages,webhooks .*?--timeout=(\d+)/', (string) file_get_contents(base_path($file)), $worker);
        expect((int) $worker[1])->toBeGreaterThan(DeliverOutboundMessage::TIMEOUT);
    }

    foreach (['database', 'redis', 'beanstalkd'] as $connection) {
        expect((int) config("queue.connections.{$connection}.retry_after"))->toBeGreaterThan(150);
    }
})->with([
    'defaults' => [10, 60],
    'a long configured upload' => [10, 600],
    'a slow Graph API' => [30, 120],
]);

test('the team chat sends a file alone and explains a file PHP refused', function () {
    app()->instance(ServerUploadLimits::class, new ServerUploadLimits('2M', '8M'));
    $colleague = User::factory()->agent()->inDepartments($this->department)->create();
    $thread = TeamThread::factory()->direct($this->agent, $colleague)->create();
    $post = fn (array $data): TestResponse => $this->actingAs($this->agent)
        ->post(route('team-chat.threads.messages.store', $thread), $data, ['Accept' => 'application/json']);

    $post(['attachments' => [uploadsSamples()['image']()]])->assertCreated()->assertJsonPath('data.body', null);
    $post(['attachments' => [uploadsSamples()['video']()]])->assertCreated();

    $post(['body' => '', 'attachments' => [uploadRefusedByPhp(UPLOAD_ERR_INI_SIZE, 'video.mp4')]])
        ->assertStatus(422)
        ->assertJsonPath('code', 'server_upload_limit')
        ->assertJsonValidationErrors(['attachments.0' => 'El servidor de Chat Manager no pudo recibir el archivo: acepta hasta 2.1 MB. Un administrador puede ampliar el límite.'])
        ->assertJsonMissingValidationErrors(['body']);

    $post(['body' => 'Va el reporte', 'attachments' => [uploadsSamples()['document'](), uploadRefusedByPhp(UPLOAD_ERR_PARTIAL, 'reporte.pdf')]])
        ->assertStatus(422)
        ->assertJsonPath('code', 'upload_interrupted')
        ->assertJsonValidationErrors(['attachments.1' => 'probablemente por la conexión']);

    // A key that is not a position (attachments[foo]) is a validation error, never a 500.
    $post(['attachments' => ['foo' => uploadRefusedByPhp(UPLOAD_ERR_INI_SIZE, 'video.mp4')]])
        ->assertStatus(422)
        ->assertJsonPath('code', 'server_upload_limit');

    $post(['body' => ''])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['body' => 'Escribe un mensaje o adjunta un archivo.']);

    expect(TeamAttachment::query()->count())->toBe(2);
});

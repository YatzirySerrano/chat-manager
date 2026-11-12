<?php

use App\Enums\ConnectionChannel;
use App\Enums\MessageDirection;
use App\Enums\MessageType;
use App\Models\Connection;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Department;
use App\Models\Message;
use App\Models\MessageAttachment;
use App\Models\TeamChat\TeamAttachment;
use App\Models\TeamChat\TeamMessage;
use App\Models\TeamChat\TeamThread;
use App\Models\User;
use App\Services\Meta\MediaStorage;
use App\Services\Storage\MediaDisk;
use App\Services\Storage\MediaPaths;
use App\Services\Storage\MediaThumbnails;
use App\Services\Storage\StorageStatus;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;

/*
| Readable layout of the media disk (MediaPaths): a folder per channel and
| per contact with words a person understands, dated file names without
| ids, UUIDs or random suffixes, previews in JPEG away from the originals,
| and storage:reorganize-media for the files stored with the old layout.
*/

beforeEach(function () {
    Storage::fake('local');
    // Names use the platform time zone (America/Mexico_City by default, UTC-6 in November).
    config(['filesystems.media_disk' => 'local']);
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-12 20:30:15', 'UTC'));
    Carbon::setTestNow('2026-11-12 20:30:15');
});

afterEach(function () {
    CarbonImmutable::setTestNow();
    Carbon::setTestNow();
    File::deleteDirectory(storage_path('app/private/reorganizacion-de-archivos'));
});

/** A 1x1 PNG. */
function readablePng(): string
{
    return (string) base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==');
}

function readableTempFile(string $bytes): string
{
    $path = (string) tempnam(sys_get_temp_dir(), 'readable-');
    file_put_contents($path, $bytes);

    return $path;
}

/** A photo large enough to need a preview. */
function readableLargeJpeg(): string
{
    $image = imagecreatetruecolor(1600, 1200);
    imagefill($image, 0, 0, (int) imagecolorallocate($image, 30, 120, 90));
    ob_start();
    imagejpeg($image, null, 95);

    return (string) ob_get_clean();
}

/**
 * @return array{contact: Contact, conversation: Conversation, message: Message, attachment: MessageAttachment}
 */
function readableInbound(array $contact = [], ?Connection $connection = null, array $attachment = []): array
{
    $person = Contact::factory()->create(['name' => 'Juan Pérez', 'phone_e164' => '+525512345678', ...$contact]);
    $conversation = Conversation::factory()->create([
        'contact_id' => $person->id,
        ...($connection !== null ? ['connection_id' => $connection->id] : []),
    ]);
    $message = Message::factory()->create([
        'conversation_id' => $conversation->id,
        'direction' => MessageDirection::Inbound,
        'type' => MessageType::Image,
    ]);
    $message->forceFill(['sent_at' => CarbonImmutable::parse('2026-11-12 20:30:15', 'UTC')])->save();

    $file = new MessageAttachment(['message_id' => $message->id, 'disk' => 'local', 'mime_type' => 'image/png', ...$attachment]);
    $file->save();

    return ['contact' => $person, 'conversation' => $conversation, 'message' => $message, 'attachment' => $file];
}

test('a received file is filed under the channel, the contact and the month with a readable name', function () {
    $inbound = readableInbound();
    $stored = app(MediaStorage::class)->store(readableTempFile(readablePng()), 'image', null, $inbound['attachment']);

    expect($stored['path'])->toBe('WhatsApp/Juan Perez (+52 55 1234 5678)/2026-11 Noviembre/2026-11-12 14.30.15 Foto recibida.png')
        ->and(Storage::disk('local')->exists($stored['path']))->toBeTrue()
        ->and($inbound['contact']->fresh()->media_folder)->toBe('Juan Perez (+52 55 1234 5678)');
});

test('two files of the same second get a readable counter instead of a random suffix', function () {
    $inbound = readableInbound();
    $storage = app(MediaStorage::class);

    $first = $storage->store(readableTempFile(readablePng()), 'image', null, $inbound['attachment'])['path'];
    $second = $storage->store(readableTempFile(readablePng()), 'image', null, $inbound['attachment'])['path'];
    $third = $storage->store(readableTempFile(readablePng()), 'image', null, $inbound['attachment'])['path'];

    expect(basename($first))->toBe('2026-11-12 14.30.15 Foto recibida.png')
        ->and(basename($second))->toBe('2026-11-12 14.30.15 Foto recibida (2).png')
        ->and(basename($third))->toBe('2026-11-12 14.30.15 Foto recibida (3).png')
        ->and(dirname($second))->toBe(dirname($first));
});

test('what the team sends goes to the same folder as what the contact sent, and the folder survives a rename', function () {
    $inbound = readableInbound();
    $paths = app(MediaPaths::class);
    $at = CarbonImmutable::parse('2026-11-12 20:31:02', 'UTC');

    $received = $paths->forMessage($inbound['message'], 'image/png');
    $sent = $paths->conversationFile($inbound['contact'], ConnectionChannel::Whatsapp, MessageDirection::Outbound, 'document', 'application/pdf', $at, 'Contrato firmado.PDF');

    expect($sent['directory'])->toBe($received['directory'])
        ->and($sent['stem'])->toBe('2026-11-12 14.31.02 Documento enviado - Contrato firmado')
        ->and($sent['extension'])->toBe('pdf');

    $inbound['contact']->forceFill(['name' => 'Juan Carlos Pérez Gómez'])->save();

    expect($paths->contactFolder($inbound['contact']->fresh(), ConnectionChannel::Whatsapp))->toBe('Juan Perez (+52 55 1234 5678)');
});

test('every kind of file is named with a whole word in Spanish', function (string $kind, string $mime, bool $voice, MessageDirection $direction, string $expected) {
    $contact = Contact::factory()->create(['name' => 'Ana', 'phone_e164' => '+525500000001']);
    $file = app(MediaPaths::class)->conversationFile($contact, ConnectionChannel::Whatsapp, $direction, $kind, $mime, CarbonImmutable::parse('2026-11-12 09:05:40', 'UTC'), 'IMG_0042.whatever', $voice);

    expect($file['stem'].'.'.$file['extension'])->toBe('2026-11-12 03.05.40 '.$expected);
})->with([
    'photo' => ['image', 'image/jpeg', false, MessageDirection::Inbound, 'Foto recibida.jpg'],
    'video' => ['video', 'video/mp4', false, MessageDirection::Outbound, 'Video enviado.mp4'],
    'audio' => ['audio', 'audio/mpeg', false, MessageDirection::Inbound, 'Audio recibido.mp3'],
    'voice note' => ['audio', 'audio/ogg', true, MessageDirection::Inbound, 'Nota de voz recibida.ogg'],
    'sticker keeps its real format' => ['sticker', 'image/webp', false, MessageDirection::Inbound, 'Sticker recibido.webp'],
    'document' => ['document', 'application/pdf', false, MessageDirection::Inbound, 'Documento recibido - IMG 0042.pdf'],
    'word file detected as a zip container' => ['document', 'application/zip', false, MessageDirection::Outbound, 'Documento enviado - IMG 0042.zip'],
]);

test('a generic detection keeps a consistent extension of the original name, never an arbitrary one', function () {
    $paths = app(MediaPaths::class);

    expect($paths->extensionFor('application/zip', 'reporte.docx'))->toBe('docx')
        ->and($paths->extensionFor('application/zip', 'reporte.exe'))->toBe('zip')
        ->and($paths->extensionFor('image/jpeg', 'foto.png'))->toBe('jpg')
        ->and($paths->extensionFor('text/plain', 'lista.csv'))->toBe('csv')
        ->and($paths->extensionFor('application/x-unknown-thing', 'x.sh'))->toBe('bin');
});

test('a contact without a phone is filed by name and channel, and a namesake gets an order number', function () {
    $connection = Connection::factory()->messenger()->create();
    $paths = app(MediaPaths::class);

    $first = Contact::factory()->create(['name' => 'María López', 'phone_e164' => null]);
    $second = Contact::factory()->create(['name' => 'maría lópez', 'phone_e164' => null]);
    $nameless = Contact::factory()->create(['name' => '', 'profile_name' => null, 'phone_e164' => null]);
    $onlyPhone = Contact::factory()->create(['name' => '+52 55 9999 0000', 'phone_e164' => '+525599990000']);

    expect($paths->contactFolder($first, $connection->channel))->toBe('Maria Lopez (Messenger)')
        ->and($paths->contactFolder($second, $connection->channel))->toBe('maria lopez 2 (Messenger)')
        ->and($paths->contactFolder($nameless, $connection->channel))->toBe('Contacto sin nombre (Messenger)')
        ->and($paths->contactFolder($onlyPhone, ConnectionChannel::Whatsapp))->toBe('+52 55 9999 0000')
        ->and($paths->channelFolder(ConnectionChannel::WebChat))->toBe('Chat web')
        ->and($paths->channelFolder(ConnectionChannel::Instagram))->toBe('Instagram');
});

test('a hostile, empty, emoji or endless name can never leave the media root or produce an unusable folder', function (string $name) {
    $contact = Contact::factory()->create(['name' => $name, 'profile_name' => null, 'phone_e164' => null]);
    $folder = app(MediaPaths::class)->contactFolder($contact, ConnectionChannel::Whatsapp);

    expect($folder)->not->toBe('')
        ->and($folder)->not->toContain('/')->not->toContain('\\')->not->toContain('..')->not->toContain(':')
        ->and($folder)->toMatch("/^[A-Za-z0-9 .,'()&+-]+$/")
        ->and(strlen($folder))->toBeLessThanOrEqual(120)
        ->and($folder)->not->toEndWith('.')->not->toEndWith(' ')
        ->and(strtoupper((string) strtok($folder, ' .(')))->not->toBeIn(['CON', 'NUL', 'PRN', 'AUX', 'COM1', 'LPT1']);
})->with([
    'path traversal' => ['../../etc/passwd'],
    'windows separators' => ['..\\..\\Windows\\System32 <script>:*?"|'],
    'only emoji' => ['🐑🤑❗'],
    'only dots and spaces' => [' . . . '],
    'reserved device name' => ['CON'],
    'reserved with extension' => ['nul.txt'],
    'endless' => [str_repeat('Nombre Largo ', 9)],
    'control characters' => ["Ana\0\n\tLopez"],
]);

test('an internal chat is filed by the people of the private chat or the name of the group', function () {
    $ana = User::factory()->agent()->create(['name' => 'Ana López']);
    $beto = User::factory()->agent()->create(['name' => 'Beto Ruiz']);
    $direct = TeamThread::factory()->direct($ana, $beto)->create();
    $paths = app(MediaPaths::class);

    $file = $paths->teamFile($direct, $ana, 'video', 'video/mp4', CarbonImmutable::parse('2026-11-12 09:05:40', 'UTC'), 'clip.mp4');

    expect($file['directory'])->toBe('Chat interno/Ana Lopez y Beto Ruiz/2026-11 Noviembre')
        ->and($file['stem'].'.'.$file['extension'])->toBe('2026-11-12 03.05.40 Video de Ana Lopez.mp4')
        ->and($direct->fresh()->media_folder)->toBe('Ana Lopez y Beto Ruiz');
});

test('a file posted in the internal chat lands in the readable folder of the chat', function () {
    $department = Department::factory()->create();
    $ana = User::factory()->agent()->inDepartments($department)->create(['name' => 'Ana López']);
    $beto = User::factory()->agent()->inDepartments($department)->create(['name' => 'Beto Ruiz']);
    $thread = TeamThread::factory()->direct($ana, $beto)->create();

    $this->actingAs($ana)
        ->post(route('team-chat.threads.messages.store', $thread), ['attachments' => [UploadedFile::fake()->createWithContent('captura de pantalla.png', readablePng())]], ['Accept' => 'application/json'])
        ->assertCreated();

    $path = (string) TeamAttachment::query()->latest('id')->value('path');

    expect($path)->toBe('Chat interno/Ana Lopez y Beto Ruiz/2026-11 Noviembre/2026-11-12 14.30.15 Foto de Ana Lopez.png')
        ->and(Storage::disk('local')->exists($path))->toBeTrue();
});

test('the preview of a photo is a JPEG kept in the system folder, never a WebP next to the original', function () {
    $path = 'WhatsApp/Juan Perez (+52 55 1234 5678)/2026-11 Noviembre/2026-11-12 14.30.15 Foto recibida.jpg';
    Storage::disk('local')->put($path, readableLargeJpeg());

    $preview = app(MediaThumbnails::class)->ensure('local', $path, 'image/jpeg');

    expect($preview)->not->toBeNull()
        ->and($preview['mime'])->toBe('image/jpeg')
        ->and($preview['path'])->toBe('Miniaturas del sistema/'.$path.'.jpg')
        ->and(Storage::disk('local')->files(dirname($path)))->toBe([$path])
        ->and(collect(Storage::disk('local')->allFiles())->filter(fn (string $file): bool => str_ends_with($file, '.webp'))->all())->toBe([]);

    // A sticker keeps the format it was sent in and gets no preview.
    expect(app(MediaThumbnails::class)->supports('image/webp', 1000))->toBeFalse();

    app(MediaDisk::class)->discard('local', $path);

    expect(Storage::disk('local')->allFiles())->toBe([]);
})->skip(fn () => ! function_exists('imagecreatetruecolor'), 'GD is not available');

test('the storage monitor shows the readable name without the original name of a document', function () {
    $inbound = readableInbound();
    $path = 'WhatsApp/Juan Perez (+52 55 1234 5678)/2026-11 Noviembre/2026-11-12 14.31.02 Documento recibido - estado de cuenta Juan.pdf';
    Storage::disk('local')->put($path, 'pdf');
    $inbound['attachment']->forceFill(['disk' => 'local', 'path' => $path, 'mime_type' => 'application/pdf', 'file_name' => 'estado de cuenta Juan.pdf', 'size_bytes' => 3])->save();

    $recent = collect(app(StorageStatus::class)->snapshot()['activity']['recent'] ?? []);

    expect($recent->pluck('name')->all())->toContain('2026-11-12 14.31.02 Documento recibido.pdf');
})->skip(fn () => ! method_exists(StorageStatus::class, 'snapshot'), 'StorageStatus::snapshot is not available');

/**
 * A file stored with the old layout, with the preview an earlier version
 * left next to it.
 */
function readableLegacyAttachment(string $path = 'media/2026/11/0b0e6a3c-legacy'): array
{
    $inbound = readableInbound();
    Storage::disk('local')->put($path, readablePng());
    Storage::disk('local')->put($path.'.thumb.webp', 'old preview');
    $inbound['attachment']->forceFill(['disk' => 'local', 'path' => $path, 'mime_type' => 'image/png', 'size_bytes' => strlen(readablePng()), 'sha256' => hash('sha256', readablePng())])->save();

    return $inbound;
}

test('the reorganization only simulates unless --apply is given', function () {
    $legacy = readableLegacyAttachment();
    $before = Storage::disk('local')->allFiles();

    $this->artisan('storage:reorganize-media')
        ->expectsOutputToContain('SIMULACIÓN')
        ->expectsOutputToContain('Se moverían: 1')
        ->assertSuccessful();

    expect(Storage::disk('local')->allFiles())->toBe($before)
        ->and($legacy['attachment']->fresh()->path)->toBe('media/2026/11/0b0e6a3c-legacy')
        ->and($legacy['contact']->fresh()->media_folder)->toBeNull()
        ->and(is_dir(storage_path('app/private/reorganizacion-de-archivos')))->toBeFalse();
});

test('the reorganization moves the file, updates every row, removes the old previews and can be repeated and undone', function () {
    $legacy = readableLegacyAttachment();
    // A second row referencing the same stored file.
    $twin = $legacy['attachment']->replicate();
    $twin->save();

    $this->artisan('storage:reorganize-media', ['--apply' => true])->expectsOutputToContain('Movidos: 1')->assertSuccessful();

    $moved = 'WhatsApp/Juan Perez (+52 55 1234 5678)/2026-11 Noviembre/2026-11-12 14.30.15 Foto recibida.png';

    expect($legacy['attachment']->fresh()->path)->toBe($moved)
        ->and($twin->fresh()->path)->toBe($moved)
        ->and(Storage::disk('local')->allFiles())->toBe([$moved])
        ->and(Storage::disk('local')->get($moved))->toBe(readablePng());

    // Nothing is left with the old layout: a second run has nothing to do.
    $this->artisan('storage:reorganize-media', ['--apply' => true])->expectsOutputToContain('Movidos: 0')->assertSuccessful();

    expect(Storage::disk('local')->allFiles())->toBe([$moved]);

    $journals = File::files(storage_path('app/private/reorganizacion-de-archivos'));
    expect($journals)->toHaveCount(1);

    $this->artisan('storage:reorganize-media', ['--undo' => $journals[0]->getPathname()])->assertSuccessful();

    expect($legacy['attachment']->fresh()->path)->toBe('media/2026/11/0b0e6a3c-legacy')
        ->and($twin->fresh()->path)->toBe('media/2026/11/0b0e6a3c-legacy')
        ->and(Storage::disk('local')->allFiles())->toBe(['media/2026/11/0b0e6a3c-legacy']);
});

test('the reorganization can be done in parts and leaves alone what has no file or belongs to nobody', function () {
    $first = readableLegacyAttachment('media/2026/11/first');
    $second = readableLegacyAttachment('media/2026/11/second');
    // A row whose file is not on the disk, and a file no attachment knows.
    $ghost = readableInbound();
    $ghost['attachment']->forceFill(['disk' => 'local', 'path' => 'media/2026/11/ghost', 'mime_type' => 'image/png'])->save();
    Storage::disk('local')->put('media/2026/11/unknown-file', 'x');

    $this->artisan('storage:reorganize-media', ['--apply' => true, '--limit' => 1])->expectsOutputToContain('Movidos: 1')->assertSuccessful();

    expect($first['attachment']->fresh()->path)->toStartWith('WhatsApp/')
        ->and($second['attachment']->fresh()->path)->toBe('media/2026/11/second');

    $this->artisan('storage:reorganize-media', ['--apply' => true])
        ->expectsOutputToContain('Movidos: 1')
        ->expectsOutputToContain('Sin archivo en el almacenamiento: 1')
        ->assertSuccessful();

    expect($second['attachment']->fresh()->path)->toStartWith('WhatsApp/')
        ->and($ghost['attachment']->fresh()->path)->toBe('media/2026/11/ghost')
        ->and(Storage::disk('local')->exists('media/2026/11/unknown-file'))->toBeTrue();
});

test('the reorganization also files the internal chat and can be limited to one area', function () {
    $ana = User::factory()->agent()->create(['name' => 'Ana López']);
    $beto = User::factory()->agent()->create(['name' => 'Beto Ruiz']);
    $thread = TeamThread::factory()->direct($ana, $beto)->create();
    $message = TeamMessage::factory()->create(['team_thread_id' => $thread->id, 'user_id' => $ana->id]);
    Storage::disk('local')->put('team-chat/2026/11/abc', readablePng());
    $file = TeamAttachment::factory()->create(['team_message_id' => $message->id, 'disk' => 'local', 'path' => 'team-chat/2026/11/abc', 'original_name' => 'captura.png', 'mime_type' => 'image/png', 'kind' => 'image', 'size_bytes' => strlen(readablePng())]);
    $conversationFile = readableLegacyAttachment();

    $this->artisan('storage:reorganize-media', ['--apply' => true, '--only' => 'team-chat'])->expectsOutputToContain('Movidos: 1')->assertSuccessful();

    expect($file->fresh()->path)->toStartWith('Chat interno/Ana Lopez y Beto Ruiz/')->toEndWith(' Foto de Ana Lopez.png')
        ->and($conversationFile['attachment']->fresh()->path)->toBe('media/2026/11/0b0e6a3c-legacy');
});

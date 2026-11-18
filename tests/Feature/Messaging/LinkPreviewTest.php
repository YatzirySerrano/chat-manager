<?php

use App\Enums\MessageType;
use App\Models\Conversation;
use App\Models\ConversationNote;
use App\Models\Department;
use App\Models\LinkPreview;
use App\Models\Message;
use App\Models\TeamChat\TeamMessage;
use App\Models\TeamChat\TeamThread;
use App\Models\User;
use App\Services\LinkPreviews\BlockedAddress;
use App\Services\LinkPreviews\HostResolver;
use App\Services\LinkPreviews\LinkPreviews;
use App\Services\LinkPreviews\MapsLink;
use App\Services\LinkPreviews\OpenGraphParser;
use App\Services\LinkPreviews\SafeUrl;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

/*
| Previews of the links written in messages: the server only opens public
| addresses (also after each redirect), reads the Open Graph data, keeps
| the image on the media disk and caches the result (also failures). Only
| people who can see the message get its preview, and the address always
| comes from the stored message.
*/

/**
 * DNS answers of the test: host => addresses.
 *
 * @param  array<string, list<string>>  $answers
 */
function fakeDns(array $answers): void
{
    app()->instance(HostResolver::class, new class($answers) implements HostResolver
    {
        /** @param array<string, list<string>> $answers */
        public function __construct(private array $answers) {}

        public function resolve(string $host): array
        {
            return $this->answers[$host] ?? [];
        }
    });
}

function pngBytes(int $width = 40, int $height = 20): string
{
    $image = imagecreatetruecolor($width, $height);
    imagefill($image, 0, 0, (int) imagecolorallocate($image, 200, 30, 30));
    ob_start();
    imagepng($image);

    return (string) ob_get_clean();
}

function ogPage(string $title = 'Tacos El Güero', string $image = '/img/portada.png'): string
{
    return <<<HTML
        <!doctype html><html><head><meta charset="utf-8">
        <title>Ignorado</title>
        <meta property="og:title" content="{$title}">
        <meta property="og:description" content="Los mejores tacos &amp; salsas <b>de la ciudad</b>">
        <meta property="og:site_name" content="Taquería">
        <meta property="og:image" content="{$image}">
        <script>alert(1)</script>
        </head><body>Hola</body></html>
        HTML;
}

beforeEach(function () {
    config([
        'filesystems.media_disk' => 'local',
        'attachments.storage.media_disk_required' => false,
        'link_previews.enabled' => true,
    ]);
    Storage::fake('local');
    fakeDns([
        'tacos.example.com' => ['93.184.216.34'],
        'internal.example.com' => ['10.0.0.7'],
        'mixed.example.com' => ['93.184.216.35', '127.0.0.1'],
        'maps.app.goo.gl' => ['142.250.1.1'],
        'www.google.com' => ['142.250.1.2'],
    ]);

    $this->department = Department::factory()->create();
    $this->agent = User::factory()->agent()->available()->inDepartments($this->department)->create();
    $this->conversation = Conversation::factory()->inDepartment($this->department)->assignedTo($this->agent)->create();
});

test('only public http and https addresses on standard ports may be opened', function (string $url, string $reason) {
    expect(fn () => app(SafeUrl::class)->check($url))
        ->toThrow(fn (BlockedAddress $exception) => expect($exception->reason)->toBe($reason));
})->with([
    'loopback' => ['http://127.0.0.1/', 'blocked_address'],
    'localhost' => ['http://localhost/admin', 'blocked_address'],
    'private network' => ['http://10.1.2.3/', 'blocked_address'],
    'cloud metadata' => ['http://169.254.169.254/latest/meta-data/', 'blocked_address'],
    'carrier-grade NAT' => ['http://100.64.0.1/', 'blocked_address'],
    'IPv6 loopback' => ['http://[::1]/', 'blocked_address'],
    'IPv4 mapped in IPv6' => ['http://[::ffff:127.0.0.1]/', 'blocked_address'],
    'decimal address' => ['http://2130706433/', 'blocked_address'],
    'host resolving to a private address' => ['https://internal.example.com/', 'blocked_address'],
    'host with one private address among public ones' => ['https://mixed.example.com/', 'blocked_address'],
    'other scheme' => ['ftp://tacos.example.com/menu', 'invalid_scheme'],
    'file scheme' => ['file:///etc/passwd', 'invalid_url'],
    'other port' => ['http://tacos.example.com:8080/', 'blocked_port'],
    'credentials' => ['https://user:secret@tacos.example.com/', 'invalid_url'],
    'unknown host' => ['https://nowhere.example.com/', 'unresolved'],
]);

test('a public address is pinned to the address that was checked', function () {
    expect(app(SafeUrl::class)->check('https://tacos.example.com/menu'))
        ->toMatchArray(['host' => 'tacos.example.com', 'port' => 443, 'ip' => '93.184.216.34']);
});

test('the Open Graph data is read as text only', function () {
    $data = app(OpenGraphParser::class)->parse(ogPage(), 'https://tacos.example.com/menu/hoy');

    expect($data)->toBe([
        'title' => 'Tacos El Güero',
        'description' => 'Los mejores tacos & salsas de la ciudad',
        'site_name' => 'Taquería',
        'image' => 'https://tacos.example.com/img/portada.png',
        'icon' => null,
    ]);
});

test('a page in another charset is read correctly', function () {
    $html = mb_convert_encoding('<html><head><meta property="og:title" content="Café de la esquina"></head></html>', 'ISO-8859-1', 'UTF-8');

    expect(app(OpenGraphParser::class)->parse($html, 'https://tacos.example.com/', 'ISO-8859-1')['title'])->toBe('Café de la esquina');
});

test('the preview of a link in a customer message is fetched once, with its image kept on the media disk', function () {
    Http::fake([
        'https://tacos.example.com/menu' => Http::response(ogPage(), 200, ['Content-Type' => 'text/html; charset=utf-8']),
        'https://tacos.example.com/img/portada.png' => Http::response(pngBytes(), 200, ['Content-Type' => 'image/png']),
    ]);
    $message = Message::factory()->create(['conversation_id' => $this->conversation->id, 'body' => 'Mira el menú: https://tacos.example.com/menu.']);
    $route = route('conversations.messages.link-preview', [$this->conversation, $message]);

    $response = $this->actingAs($this->agent)->getJson($route)
        ->assertOk()
        ->assertJsonPath('preview.url', 'https://tacos.example.com/menu')
        ->assertJsonPath('preview.status', 'ready')
        ->assertJsonPath('preview.domain', 'tacos.example.com')
        ->assertJsonPath('preview.title', 'Tacos El Güero')
        ->assertJsonPath('preview.site_name', 'Taquería')
        ->assertJsonPath('preview.maps', false)
        ->assertJsonPath('preview.image_width', 40);

    $this->actingAs($this->agent)->getJson($route)->assertOk()->assertJsonPath('preview.title', 'Tacos El Güero');
    Http::assertSentCount(2);
    Http::assertSent(fn (Request $request) => str_contains($request->header('User-Agent')[0] ?? '', 'LinkPreviewBot'));

    $preview = LinkPreview::query()->sole();
    expect($preview->image_mime)->toBe('image/jpeg')
        ->and($preview->image_path)->toStartWith(LinkPreviews::FOLDER.'/');
    Storage::disk('local')->assertExists($preview->image_path);

    $this->actingAs($this->agent)->get($response->json('preview.image_url'))
        ->assertOk()
        ->assertHeader('Content-Type', 'image/jpeg')
        ->assertHeader('X-Content-Type-Options', 'nosniff');
});

test('the image is only served through its signed address', function () {
    $preview = LinkPreview::query()->forceCreate([
        'url_hash' => LinkPreviews::hash('https://tacos.example.com/'), 'url' => 'https://tacos.example.com/', 'status' => 'ready',
        'image_disk' => 'local', 'image_path' => LinkPreviews::FOLDER.'/x.jpg', 'image_mime' => 'image/jpeg', 'fetched_at' => now(), 'expires_at' => now()->addDay(),
    ]);
    Storage::disk('local')->put(LinkPreviews::FOLDER.'/x.jpg', 'jpeg');

    $this->actingAs($this->agent)->get(route('link-previews.image', ['linkPreview' => $preview->id, 'variant' => 'image']))->assertForbidden();
});

test('a redirect to a private address is never followed', function () {
    Http::fake([
        'https://tacos.example.com/corto' => Http::response('', 302, ['Location' => 'https://internal.example.com/admin']),
        '*' => Http::response('secreto', 200, ['Content-Type' => 'text/html']),
    ]);

    $preview = app(LinkPreviews::class)->forUrl('https://tacos.example.com/corto');

    expect($preview->status)->toBe(LinkPreview::FAILED)->and($preview->error)->toBe('blocked_address');
    Http::assertSentCount(1);
});

test('at most three redirects are followed', function () {
    Http::fake([
        'https://tacos.example.com/1' => Http::response('', 301, ['Location' => '/2']),
        'https://tacos.example.com/2' => Http::response('', 301, ['Location' => '/3']),
        'https://tacos.example.com/3' => Http::response('', 301, ['Location' => '/4']),
        'https://tacos.example.com/4' => Http::response('', 301, ['Location' => '/5']),
        '*' => Http::response(ogPage(), 200, ['Content-Type' => 'text/html']),
    ]);

    expect(app(LinkPreviews::class)->forUrl('https://tacos.example.com/1')->error)->toBe('too_many_redirects');
    Http::assertSentCount(4);
});

test('anything that is not a web page is not read', function () {
    Http::fake(['*' => Http::response('%PDF-1.4', 200, ['Content-Type' => 'application/pdf'])]);

    expect(app(LinkPreviews::class)->forUrl('https://tacos.example.com/menu.pdf')->error)->toBe('type_not_allowed');
});

test('a failed preview is cached for a while and then tried again', function () {
    Http::fake(['*' => Http::response('no', 500, ['Content-Type' => 'text/html'])]);
    $previews = app(LinkPreviews::class);

    expect($previews->forUrl('https://tacos.example.com/roto')->status)->toBe(LinkPreview::FAILED);
    $previews->forUrl('https://tacos.example.com/roto');
    Http::assertSentCount(1);

    $this->travel(7)->hours();
    $previews->forUrl('https://tacos.example.com/roto');
    Http::assertSentCount(2);
});

test('an image that is not really an image is not kept', function () {
    Http::fake([
        'https://tacos.example.com/menu' => Http::response(ogPage(image: 'https://tacos.example.com/falsa.png'), 200, ['Content-Type' => 'text/html']),
        'https://tacos.example.com/falsa.png' => Http::response('<svg onload="alert(1)"></svg>', 200, ['Content-Type' => 'image/png']),
    ]);

    $preview = app(LinkPreviews::class)->forUrl('https://tacos.example.com/menu');

    expect($preview->status)->toBe(LinkPreview::READY)->and($preview->hasImage())->toBeFalse();
});

test('a Google Maps short link becomes a place with its point', function () {
    Http::fake([
        'https://maps.app.goo.gl/abc123' => Http::response('', 302, ['Location' => 'https://www.google.com/maps/place/Sucursal+Centro/@19.4326077,-99.133208,17z/data=!3d19.4326077!4d-99.133208']),
        'https://www.google.com/maps/*' => Http::response('<html><head><meta property="og:title" content="Sucursal Centro · Av. Juárez 1"></head></html>', 200, ['Content-Type' => 'text/html']),
    ]);
    $message = Message::factory()->create(['conversation_id' => $this->conversation->id, 'body' => 'Aquí estoy https://maps.app.goo.gl/abc123']);

    $this->actingAs($this->agent)->getJson(route('conversations.messages.link-preview', [$this->conversation, $message]))
        ->assertOk()
        ->assertJsonPath('preview.maps', true)
        ->assertJsonPath('preview.title', 'Sucursal Centro · Av. Juárez 1')
        ->assertJsonPath('preview.location.latitude', 19.4326077)
        ->assertJsonPath('preview.location.longitude', -99.133208);
});

test('maps addresses are recognized and their point read', function () {
    expect(MapsLink::is('https://maps.app.goo.gl/xyz'))->toBeTrue()
        ->and(MapsLink::is('https://www.google.com.mx/maps/place/Algo'))->toBeTrue()
        ->and(MapsLink::is('https://www.google.com/search?q=mapas'))->toBeFalse()
        ->and(MapsLink::coordinates('https://www.google.com/maps/search/?api=1&query=19.43,-99.13'))->toBe(['latitude' => 19.43, 'longitude' => -99.13])
        ->and(MapsLink::placeName('https://www.google.com/maps/place/Sucursal+Centro/@19.4,-99.1,17z'))->toBe('Sucursal Centro');
});

test('only people who see the conversation get its previews, and never for another conversation', function () {
    Http::fake();
    $message = Message::factory()->create(['conversation_id' => $this->conversation->id, 'body' => 'https://tacos.example.com/menu']);
    $stranger = User::factory()->agent()->inDepartments(Department::factory()->create())->create();
    $other = Conversation::factory()->inDepartment($this->department)->assignedTo($this->agent)->create();

    $this->actingAs($stranger)->getJson(route('conversations.messages.link-preview', [$this->conversation, $message]))->assertNotFound();
    $this->actingAs($this->agent)->getJson(route('conversations.messages.link-preview', [$other, $message]))->assertNotFound();
    Http::assertNothingSent();
});

test('a message without a link, or deleted for everyone, has no preview and opens nothing', function () {
    Http::fake();
    $plain = Message::factory()->create(['conversation_id' => $this->conversation->id, 'body' => 'Sin enlaces, javascript:alert(1)']);
    $revoked = Message::factory()->create(['conversation_id' => $this->conversation->id, 'body' => 'https://tacos.example.com/', 'revoked_at' => now()]);

    foreach ([$plain, $revoked] as $message) {
        $this->actingAs($this->agent)->getJson(route('conversations.messages.link-preview', [$this->conversation, $message]))
            ->assertOk()->assertJsonPath('preview', null);
    }

    Http::assertNothingSent();
});

test('internal notes have previews for whoever sees the conversation', function () {
    Http::fake(['*' => Http::response(ogPage(image: ''), 200, ['Content-Type' => 'text/html'])]);
    $note = ConversationNote::query()->forceCreate(['conversation_id' => $this->conversation->id, 'user_id' => $this->agent->id, 'body' => 'Ver https://tacos.example.com/menu']);

    $this->actingAs($this->agent)->getJson(route('conversations.notes.link-preview', [$this->conversation, $note]))
        ->assertOk()->assertJsonPath('preview.title', 'Tacos El Güero');
});

test('internal chat messages have previews only for participants', function () {
    Http::fake(['*' => Http::response(ogPage(image: ''), 200, ['Content-Type' => 'text/html'])]);
    $beto = User::factory()->agent()->inDepartments($this->department)->create();
    $outsider = User::factory()->agent()->inDepartments($this->department)->create();
    $thread = TeamThread::factory()->direct($this->agent, $beto)->create();
    $message = TeamMessage::factory()->in($thread, $beto)->create(['body' => 'https://tacos.example.com/menu']);

    $this->actingAs($this->agent)->getJson(route('team-chat.threads.messages.link-preview', [$thread, $message]))
        ->assertOk()->assertJsonPath('preview.title', 'Tacos El Güero');
    $this->actingAs($outsider)->getJson(route('team-chat.threads.messages.link-preview', [$thread, $message]))->assertNotFound();
});

test('a shared location reaches the chat with its point, name and address', function () {
    Message::factory()->create([
        'conversation_id' => $this->conversation->id,
        'type' => MessageType::Location,
        'body' => 'Sucursal Centro',
        'metadata' => ['content' => ['latitude' => 19.4326, 'longitude' => -99.1332, 'name' => 'Sucursal Centro', 'address' => 'Av. Juárez 1', 'url' => 'https://sucursal.example.com']],
    ]);

    $this->actingAs($this->agent)->getJson(route('conversations.preview', $this->conversation))
        ->assertOk()
        ->assertJsonPath('messages.0.type', 'location')
        ->assertJsonPath('messages.0.content.latitude', 19.4326)
        ->assertJsonPath('messages.0.content.longitude', -99.1332)
        ->assertJsonPath('messages.0.content.name', 'Sucursal Centro')
        ->assertJsonPath('messages.0.content.address', 'Av. Juárez 1')
        ->assertJsonPath('messages.0.content.website', 'https://sucursal.example.com');
});

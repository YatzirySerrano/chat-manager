<?php

use App\Enums\MessageDirection;
use App\Enums\MessageType;
use App\Models\Conversation;
use App\Models\Department;
use App\Models\Message;
use App\Models\MessageAttachment;
use App\Models\User;
use App\Services\Storage\MediaDisk;
use App\Services\Storage\MediaThumbnails;
use App\Support\Attachments\RangedStream;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

/*
| Image previews (MediaThumbnails), sizes kept for the layout and byte
| ranges of remote files (RangedStream): the chat paints a bubble without
| downloading the original, and a video can be sought on any disk.
*/

beforeEach(function () {
    Queue::fake();
    Storage::fake('local');
    config(['filesystems.media_disk' => 'local']);

    $this->department = Department::factory()->create();
    $this->agent = User::factory()->agent()->available()->inDepartments($this->department)->create();
    $this->outsider = User::factory()->agent()->available()->inDepartments(Department::factory()->create())->create();
    $this->conversation = Conversation::factory()->inDepartment($this->department)->assignedTo($this->agent)->create();
});

/** A real JPEG of the given size, with noise so it is not trivially small. */
function photoBytes(int $width, int $height): string
{
    $image = imagecreatetruecolor($width, $height);

    for ($index = 0; $index < 400; $index++) {
        imagefilledrectangle($image, random_int(0, $width), random_int(0, $height), random_int(0, $width), random_int(0, $height), (int) imagecolorallocate($image, random_int(0, 255), random_int(0, 255), random_int(0, 255)));
    }

    ob_start();
    imagejpeg($image, null, 95);

    return (string) ob_get_clean();
}

function previewAttachment(Conversation $conversation, string $mime, string $name, string $content): MessageAttachment
{
    $message = Message::factory()->create([
        'conversation_id' => $conversation->id,
        'direction' => MessageDirection::Inbound,
        'type' => MessageType::Image,
    ]);
    $path = 'media/2026/11/'.Str::uuid();
    Storage::disk('local')->put($path, $content);

    $attachment = new MessageAttachment(['disk' => 'local', 'path' => $path, 'mime_type' => $mime, 'file_name' => $name, 'size_bytes' => strlen($content)]);
    $attachment->forceFill(['message_id' => $message->id])->save();

    return $attachment;
}

test('the bubble gets a small preview and the original stays untouched', function () {
    $original = photoBytes(2400, 1600);
    $photo = previewAttachment($this->conversation, 'image/jpeg', 'ine.jpg', $original);
    $url = route('conversations.messages.attachments.show', [$this->conversation, $photo->message_id, $photo]);

    $preview = $this->actingAs($this->agent)->get($url.'?thumb=1')->assertOk();
    $bytes = (string) file_get_contents($preview->baseResponse->getFile()->getPathname());
    $size = getimagesizefromstring($bytes);

    expect($preview->headers->get('Content-Type'))->toBeIn(['image/webp', 'image/jpeg'])
        ->and($preview->headers->get('X-Content-Type-Options'))->toBe('nosniff')
        ->and((string) $preview->headers->get('Cache-Control'))->toContain('private')->toContain('no-cache')
        ->and($size[0])->toBe(MediaThumbnails::EDGE)
        ->and($size[1])->toBe(427)
        ->and(strlen($bytes))->toBeLessThan((int) (strlen($original) / 4));

    // The size of the original is remembered for the layout, without touching its timestamps.
    $photo->refresh();
    expect($photo->width)->toBe(2400)
        ->and($photo->height)->toBe(1600)
        ->and($photo->updated_at->equalTo($photo->created_at))->toBeTrue();

    // The original is still served whole, and each variant has its own validator.
    $full = $this->actingAs($this->agent)->get($url)->assertOk()->assertHeader('Content-Type', 'image/jpeg');
    expect(filesize($full->baseResponse->getFile()->getPathname()))->toBe(strlen($original))
        ->and($full->headers->get('ETag'))->not->toBe($preview->headers->get('ETag'));

    $this->actingAs($this->agent)->get($url.'?thumb=1', ['If-None-Match' => (string) $preview->headers->get('ETag')])->assertStatus(304);
});

test('a preview is authorized like its original', function () {
    $photo = previewAttachment($this->conversation, 'image/jpeg', 'ine.jpg', photoBytes(1200, 900));
    $url = route('conversations.messages.attachments.show', [$this->conversation, $photo->message_id, $photo]).'?thumb=1';

    $this->actingAs($this->outsider)->get($url)->assertNotFound();
    $this->post(route('logout'));
    $this->get($url)->assertRedirect();

    expect(Storage::disk('local')->exists($photo->path.'.thumb.webp') || Storage::disk('local')->exists($photo->path.'.thumb.jpg'))->toBeFalse();
});

test('files that cannot or need not be reduced are served as they are', function () {
    $small = previewAttachment($this->conversation, 'image/jpeg', 'icono.jpg', photoBytes(40, 40));
    $broken = previewAttachment($this->conversation, 'image/png', 'rota.png', 'not-a-png');
    $gif = previewAttachment($this->conversation, 'image/gif', 'animado.gif', 'GIF89a-bytes');

    foreach ([[$small, 'image/jpeg'], [$broken, 'image/png'], [$gif, 'image/gif']] as [$attachment, $mime]) {
        $response = $this->actingAs($this->agent)
            ->get(route('conversations.messages.attachments.show', [$this->conversation, $attachment->message_id, $attachment]).'?thumb=1')
            ->assertOk()
            ->assertHeader('Content-Type', $mime);

        expect(filesize($response->baseResponse->getFile()->getPathname()))->toBe($attachment->size_bytes);
    }

    expect(app(MediaThumbnails::class)->supports('image/gif', 10))->toBeFalse()
        ->and(app(MediaThumbnails::class)->supports('video/mp4', 10))->toBeFalse()
        ->and(app(MediaThumbnails::class)->supports('image/jpeg', 200 * 1024 * 1024))->toBeFalse();
});

test('discarding a file removes its preview too', function () {
    $photo = previewAttachment($this->conversation, 'image/jpeg', 'ine.jpg', photoBytes(1600, 1200));
    $made = app(MediaThumbnails::class)->ensure('local', $photo->path, 'image/jpeg', $photo->size_bytes);

    expect($made)->not->toBeNull()
        ->and(Storage::disk('local')->exists($made['path']))->toBeTrue();

    app(MediaDisk::class)->discard('local', $photo->path);

    expect(Storage::disk('local')->exists($photo->path))->toBeFalse()
        ->and(Storage::disk('local')->exists($made['path']))->toBeFalse();
});

test('the conversation tells the chat which preview to load and the size it will have', function () {
    $photo = previewAttachment($this->conversation, 'image/jpeg', 'ine.jpg', photoBytes(1600, 1200));
    $photo->forceFill(['width' => 1600, 'height' => 1200])->save();
    $video = previewAttachment($this->conversation, 'video/mp4', 'clip.mp4', 'mp4-bytes');
    $this->withoutVite();

    $this->actingAs($this->agent)
        ->get(route('conversations.index', ['c' => $this->conversation->id]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('timeline.messages', function ($messages) use ($photo, $video): bool {
            $attachments = collect($messages)->flatMap(fn ($message) => $message['attachments'] ?? [])->keyBy('id');

            return str_ends_with((string) $attachments[$photo->id]['thumb_url'], '?thumb=1')
                && $attachments[$photo->id]['width'] === 1600
                && $attachments[$photo->id]['height'] === 1200
                && $attachments[$video->id]['thumb_url'] === null;
        }));
});

test('a remote file answers byte ranges without being read whole', function () {
    $content = implode('', array_map(fn (int $index): string => str_pad((string) $index, 8, '0', STR_PAD_LEFT), range(1, 4096)));
    $size = strlen($content);
    $open = function () use ($content) {
        $stream = fopen('php://temp', 'w+b');
        fwrite($stream, $content);
        rewind($stream);

        return $stream;
    };
    $send = function (?string $range) use ($open, $size): array {
        $request = Request::create('/file', 'GET', server: $range === null ? [] : ['HTTP_RANGE' => $range]);
        $response = RangedStream::response($request, $open, $size, ['Content-Type' => 'video/mp4']);

        ob_start();
        $response->sendContent();

        return [$response, (string) ob_get_clean()];
    };

    [$whole, $body] = $send(null);
    expect($whole->getStatusCode())->toBe(200)
        ->and($whole->headers->get('Accept-Ranges'))->toBe('bytes')
        ->and($whole->headers->get('Content-Length'))->toBe((string) $size)
        ->and($body)->toBe($content);

    [$part, $body] = $send('bytes=16-31');
    expect($part->getStatusCode())->toBe(206)
        ->and($part->headers->get('Content-Range'))->toBe("bytes 16-31/{$size}")
        ->and($part->headers->get('Content-Length'))->toBe('16')
        ->and($body)->toBe(substr($content, 16, 16));

    [$tail, $body] = $send('bytes=-8');
    expect($tail->getStatusCode())->toBe(206)->and($body)->toBe(substr($content, -8));

    [$open1, $body] = $send('bytes='.($size - 4).'-');
    expect($open1->getStatusCode())->toBe(206)->and($body)->toBe(substr($content, -4));

    [$beyond] = $send('bytes='.($size + 10).'-');
    expect($beyond->getStatusCode())->toBe(416)
        ->and($beyond->headers->get('Content-Range'))->toBe("bytes */{$size}");

    // Several ranges are not supported: the whole file, never a wrong part.
    [$multi, $body] = $send('bytes=0-1,4-5');
    expect($multi->getStatusCode())->toBe(200)->and($body)->toBe($content);
});

<?php

use App\Models\Department;
use App\Models\TeamChat\TeamAttachment;
use App\Models\TeamChat\TeamThread;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/*
 * Audio and video of the internal chat are played in place. Phones need two
 * things: the formats they record (an iPhone shares .mov) are accepted, and
 * the file is answered with byte ranges (Safari refuses a video without them).
 */

beforeEach(function () {
    Storage::fake('local');

    $department = Department::factory()->create();
    $this->ana = User::factory()->agent()->inDepartments($department)->create();
    $this->beto = User::factory()->agent()->inDepartments($department)->create();
    $this->outsider = User::factory()->agent()->create();
    $this->thread = TeamThread::factory()->direct($this->ana, $this->beto)->create();
});

function teamClip(string $name): UploadedFile
{
    $extension = pathinfo($name, PATHINFO_EXTENSION);

    return UploadedFile::fake()->createWithContent($name, (string) file_get_contents(base_path("tests/fixtures/team-chat/clip.{$extension}")));
}

test('a video recorded with a phone is accepted and offered for playing in place', function (string $name, string $mime) {
    $this->actingAs($this->ana)
        ->post(route('team-chat.threads.messages.store', $this->thread), ['attachments' => [teamClip($name)]], ['Accept' => 'application/json'])
        ->assertCreated()
        ->assertJsonPath('data.attachments.0.kind', 'video')
        ->assertJsonPath('data.attachments.0.mime_type', $mime)
        ->assertJsonPath('data.attachments.0.previewable', false)
        ->assertJsonPath('data.attachments.0.stream_url', fn (?string $url): bool => is_string($url) && str_contains($url, 'inline=1'));
})->with([
    'mp4' => ['video.mp4', 'video/mp4'],
    'iPhone mov' => ['IMG_0042.mov', 'video/quicktime'],
]);

test('a playable file answers byte ranges and is never cached as public', function () {
    $this->actingAs($this->ana)
        ->post(route('team-chat.threads.messages.store', $this->thread), ['attachments' => [teamClip('video.mp4')]], ['Accept' => 'application/json'])
        ->assertCreated();

    $attachment = TeamAttachment::query()->firstOrFail();
    $size = $attachment->size_bytes;
    $url = route('team-chat.attachments.show', ['thread' => $this->thread, 'message' => $attachment->team_message_id, 'attachment' => $attachment, 'inline' => 1]);

    $whole = $this->actingAs($this->beto)->get($url)->assertOk();
    expect($whole->headers->get('Accept-Ranges'))->toBe('bytes')
        ->and($whole->headers->get('Content-Type'))->toBe('video/mp4')
        ->and($whole->headers->get('Content-Disposition'))->toStartWith('inline')
        ->and($whole->headers->get('X-Content-Type-Options'))->toBe('nosniff')
        ->and($whole->headers->get('Cache-Control'))->toContain('private')->toContain('no-store')->not->toContain('public');

    $part = $this->actingAs($this->beto)->get($url, ['Range' => 'bytes=0-99'])->assertStatus(206);
    expect($part->headers->get('Content-Range'))->toBe('bytes 0-99/'.$size)
        ->and($part->headers->get('Content-Length'))->toBe('100')
        ->and($part->headers->get('Cache-Control'))->not->toContain('public');

    // Someone outside the thread gets nothing, with or without a range.
    $this->actingAs($this->outsider)->get($url, ['Range' => 'bytes=0-99'])->assertNotFound();
});

test('a document is still only downloaded, never streamed inline', function () {
    $this->actingAs($this->ana)
        ->post(route('team-chat.threads.messages.store', $this->thread), [
            'attachments' => [UploadedFile::fake()->createWithContent('notas.txt', "hola\n")],
        ], ['Accept' => 'application/json'])
        ->assertCreated()
        ->assertJsonPath('data.attachments.0.stream_url', null);

    $attachment = TeamAttachment::query()->firstOrFail();

    $response = $this->actingAs($this->beto)
        ->get(route('team-chat.attachments.show', ['thread' => $this->thread, 'message' => $attachment->team_message_id, 'attachment' => $attachment, 'inline' => 1]))
        ->assertOk();

    expect($response->headers->get('Content-Disposition'))->toStartWith('attachment');
});

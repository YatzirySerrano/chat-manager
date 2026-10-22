<?php

use App\Models\Department;
use App\Models\TeamChat\TeamAttachment;
use App\Models\TeamChat\TeamThread;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('local');

    $department = Department::factory()->create();
    $this->ana = User::factory()->agent()->inDepartments($department)->create();
    $this->beto = User::factory()->agent()->inDepartments($department)->create();
    $this->thread = TeamThread::factory()->direct($this->ana, $this->beto)->create();
});

function teamPdfUpload(string $name = 'Reporte de turno.pdf'): UploadedFile
{
    return UploadedFile::fake()->createWithContent($name, "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\ntrailer\n<< /Root 1 0 R >>\n%%EOF\n");
}

test('allowed files are stored privately under a random name and described without exposing the path', function () {
    $response = $this->actingAs($this->ana)
        ->post(route('team-chat.threads.messages.store', $this->thread), [
            'attachments' => [teamPdfUpload('../../Reporte "final".pdf'), UploadedFile::fake()->image('foto.png', 20, 20)],
        ], ['Accept' => 'application/json'])
        ->assertCreated()
        ->assertJsonPath('data.body', null)
        ->assertJsonCount(2, 'data.attachments')
        ->assertJsonPath('data.attachments.0.name', 'Reporte final.pdf')
        ->assertJsonPath('data.attachments.0.mime_type', 'application/pdf')
        ->assertJsonPath('data.attachments.1.kind', 'image')
        ->assertJsonPath('data.attachments.1.previewable', true);

    $attachments = TeamAttachment::query()->orderBy('id')->get();

    expect($attachments)->toHaveCount(2)
        ->and($attachments[0]->path)->toMatch('#^team-chat/\d{4}/\d{2}/[0-9a-f-]{36}$#')
        ->and($attachments[0]->disk)->toBe('local')
        ->and($attachments[0]->sha256)->toHaveLength(64);

    Storage::disk('local')->assertExists($attachments[0]->path);
    expect(json_encode($response->json()))->not->toContain($attachments[0]->path);
});

test('files are checked by their real content, not by their name', function () {
    $disguised = UploadedFile::fake()->createWithContent('factura.pdf', '<html><script>alert(1)</script></html>');
    $svg = UploadedFile::fake()->createWithContent('logo.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>');
    $renamed = UploadedFile::fake()->createWithContent('reporte.png', "%PDF-1.4\n%%EOF\n");

    foreach ([$disguised, $svg, $renamed] as $file) {
        $this->actingAs($this->ana)
            ->post(route('team-chat.threads.messages.store', $this->thread), ['attachments' => [$file]], ['Accept' => 'application/json'])
            ->assertJsonValidationErrors(['attachments.0' => 'no es de un tipo permitido']);
    }

    expect(TeamAttachment::query()->count())->toBe(0)
        ->and(Storage::disk('local')->allFiles())->toBe([]);
});

test('size and count limits are enforced', function () {
    config(['team-chat.attachments.max_kilobytes' => 1024, 'team-chat.attachments.max_files' => 2]);

    $this->actingAs($this->ana)
        ->post(route('team-chat.threads.messages.store', $this->thread), ['attachments' => [UploadedFile::fake()->create('grande.pdf', 2048, 'application/pdf')]], ['Accept' => 'application/json'])
        ->assertJsonValidationErrors(['attachments.0' => 'Cada archivo debe pesar como máximo 1 MB.']);

    $this->actingAs($this->ana)
        ->post(route('team-chat.threads.messages.store', $this->thread), ['body' => 'x', 'attachments' => [teamPdfUpload(), teamPdfUpload(), teamPdfUpload()]], ['Accept' => 'application/json'])
        ->assertJsonValidationErrors(['attachments' => 'Puedes adjuntar como máximo 2 archivos por mensaje.']);
});

test('participants download files with safe headers and only previewable types are shown inline', function () {
    $this->actingAs($this->ana)
        ->post(route('team-chat.threads.messages.store', $this->thread), ['attachments' => [teamPdfUpload(), UploadedFile::fake()->createWithContent('notas.txt', "texto plano\n")]], ['Accept' => 'application/json']);
    [$pdf, $text] = TeamAttachment::query()->orderBy('id')->get()->all();

    $download = $this->actingAs($this->beto)->get(route('team-chat.attachments.show', [$this->thread, $pdf->team_message_id, $pdf]));
    $download->assertOk()
        ->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeader('Content-Type', 'application/pdf')
        ->assertHeader('Content-Security-Policy')
        ->assertDownload('Reporte de turno.pdf');

    $this->actingAs($this->beto)
        ->get(route('team-chat.attachments.show', [$this->thread, $pdf->team_message_id, $pdf, 'inline' => 1]))
        ->assertOk()
        ->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeaderMissing('Content-Security-Policy')
        ->assertHeader('Content-Disposition', 'inline; filename="Reporte de turno.pdf"');

    $this->actingAs($this->beto)
        ->get(route('team-chat.attachments.show', [$this->thread, $text->team_message_id, $text, 'inline' => 1]))
        ->assertHeader('Content-Security-Policy', "default-src 'none'; img-src 'self'; style-src 'unsafe-inline'; sandbox")
        ->assertDownload('notas.txt');
});

test('deleting the message purges its files and their download stops working', function () {
    $this->actingAs($this->ana)
        ->post(route('team-chat.threads.messages.store', $this->thread), ['body' => 'Te paso el archivo', 'attachments' => [teamPdfUpload()]], ['Accept' => 'application/json']);
    $attachment = TeamAttachment::query()->sole();
    $path = $attachment->path;

    $this->actingAs($this->ana)
        ->deleteJson(route('team-chat.threads.messages.destroy', [$this->thread, $attachment->team_message_id]))
        ->assertOk()
        ->assertJsonCount(0, 'data.attachments');

    Storage::disk('local')->assertMissing($path);
    expect($attachment->fresh()->purged_at)->not->toBeNull();

    $this->actingAs($this->beto)
        ->get(route('team-chat.attachments.show', [$this->thread, $attachment->team_message_id, $attachment]))
        ->assertNotFound();
});

test('a missing file answers not found instead of an error', function () {
    $this->actingAs($this->ana)
        ->post(route('team-chat.threads.messages.store', $this->thread), ['attachments' => [teamPdfUpload()]], ['Accept' => 'application/json']);
    $attachment = TeamAttachment::query()->sole();
    Storage::disk('local')->delete((string) $attachment->path);

    $this->actingAs($this->beto)
        ->get(route('team-chat.attachments.show', [$this->thread, $attachment->team_message_id, $attachment]))
        ->assertNotFound();
});

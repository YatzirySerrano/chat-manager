<?php

use App\Enums\MessageDirection;
use App\Enums\MessageType;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\MessageAttachment;
use App\Models\WebChatSession;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Tests\Support\WebChatFixtures;
use Tests\Support\WebChatVisitor;

/*
| Files the agents send to a web chat visitor: reachable only through
| short-lived signed URLs bound to the visitor's session, only for
| outbound messages the session can see, and served so they can never run
| in the application's origin.
*/

beforeEach(function () {
    Storage::fake('local');
    $this->connection = WebChatFixtures::connection();
    $this->token = WebChatVisitor::token($this, $this->connection);
    WebChatVisitor::send($this, $this->token)->assertCreated();
    $this->conversation = Conversation::query()->sole();
    $this->session = WebChatSession::query()->sole();
});

function visitorFile(Conversation $conversation, string $mime, string $name, MessageDirection $direction = MessageDirection::Outbound, string $status = 'accepted'): MessageAttachment
{
    $message = Message::factory()->create([
        'conversation_id' => $conversation->id,
        'direction' => $direction,
        'type' => MessageType::Document,
        'status' => $direction === MessageDirection::Inbound ? 'received' : $status,
        'body' => null,
    ]);
    $path = 'media/2026/10/'.Str::uuid();
    Storage::disk('local')->put($path, 'contenido');

    $attachment = new MessageAttachment(['disk' => 'local', 'path' => $path, 'mime_type' => $mime, 'file_name' => $name, 'size_bytes' => 9]);
    $attachment->forceFill(['message_id' => $message->id])->save();

    return $attachment;
}

function visitorFileUrl(MessageAttachment $attachment, string $sessionPublicId): string
{
    return URL::temporarySignedRoute('api.v1.widget.session.attachments.show', now()->addMinutes(10), ['attachment' => $attachment->id, 's' => $sessionPublicId]);
}

test('the history lists the agent files with a signed URL that serves them safely', function () {
    $pdf = visitorFile($this->conversation, 'application/pdf', 'cotización.pdf');
    $image = visitorFile($this->conversation, 'image/png', 'foto.png');

    $files = collect(WebChatVisitor::messages($this, $this->token)->json('data'))->pluck('attachments')->flatten(1);
    expect($files->pluck('name')->all())->toBe(['cotización.pdf', 'foto.png']);

    $download = $this->withoutToken()->get($files->first()['url'])->assertOk();
    expect($download->headers->get('Content-Disposition'))->toStartWith('attachment;')
        ->and($download->headers->get('Content-Type'))->toBe('application/octet-stream')
        ->and($download->headers->get('X-Content-Type-Options'))->toBe('nosniff')
        ->and($download->headers->get('Content-Security-Policy'))->toContain('sandbox');

    $inline = $this->get(visitorFileUrl($image, $this->session->public_id))->assertOk();
    expect($inline->headers->get('Content-Disposition'))->toStartWith('inline;')
        ->and($inline->headers->get('Content-Type'))->toBe('image/png');
});

test('an invalid or expired signature is a 403', function () {
    $file = visitorFile($this->conversation, 'application/pdf', 'a.pdf');
    $url = visitorFileUrl($file, $this->session->public_id);

    $this->getJson($url.'x')->assertForbidden()->assertJson(['code' => 'forbidden']);
    $this->getJson(str_replace('s='.$this->session->public_id, 's='.strtolower((string) Str::ulid()), $url))->assertForbidden();

    $this->travel(11)->minutes();
    $this->getJson($url)->assertForbidden();
});

test('a file of another session, of an inbound message or of an unsent reply is a 404', function (Closure $file) {
    $this->getJson(visitorFileUrl($file($this), $this->session->public_id))
        ->assertNotFound()
        ->assertExactJson(['message' => 'No encontrado.', 'code' => 'not_found']);
})->with([
    'another session' => [function ($test) {
        $other = WebChatVisitor::token($test, $test->connection);
        WebChatVisitor::send($test, $other)->assertCreated();

        return visitorFile(Conversation::query()->latest('id')->firstOrFail(), 'application/pdf', 'ajeno.pdf');
    }],
    'inbound message' => [fn ($test) => visitorFile($test->conversation, 'application/pdf', 'interno.pdf', MessageDirection::Inbound)],
    'reply still queued' => [fn ($test) => visitorFile($test->conversation, 'application/pdf', 'cola.pdf', status: 'queued')],
]);

test('a revoked session can no longer open its files', function () {
    $file = visitorFile($this->conversation, 'application/pdf', 'a.pdf');
    $url = visitorFileUrl($file, $this->session->public_id);
    $this->session->forceFill(['revoked_at' => now(), 'revoke_reason' => 'visitor_closed'])->save();

    $this->getJson($url)->assertNotFound();
});

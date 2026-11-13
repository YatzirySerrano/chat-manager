<?php

use App\Actions\WhatsAppWeb\ImportWhatsAppWebHistory;
use App\Enums\AttachmentStatus;
use App\Enums\ConversationOrigin;
use App\Enums\ConversationStatus;
use App\Enums\MessageDirection;
use App\Enums\MessageStatus;
use App\Enums\MessageType;
use App\Events\Conversations\ConversationChanged;
use App\Events\Messaging\MessageRecorded;
use App\Jobs\DownloadWhatsAppWebMedia;
use App\Jobs\ImportWhatsAppWebHistoryBatch;
use App\Models\Connection;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Department;
use App\Models\Message;
use App\Models\MessageAttachment;
use App\Models\User;
use App\Services\Conversations\ConversationTimeline;
use App\Services\WhatsAppWeb\WhatsAppWebTransport;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Tests\Support\WhatsAppWebFixtures;

/*
| What the sender does to a message after sending it (edit, delete for
| everyone), the kinds of message a person's WhatsApp sends, and the chat
| history of a newly linked number. The service is a double: the payloads
| follow the contract (docs/whatsapp-web-qr-contrato.md §5).
*/

beforeEach(function () {
    Http::preventStrayRequests();
    WhatsAppWebFixtures::configure();
    $this->department = Department::factory()->create();
    $this->connection = Connection::factory()->whatsAppWeb()->create(['default_department_id' => $this->department->id]);
    $this->connection->departments()->sync([$this->department->id]);
    $this->viewer = User::factory()->superAdministrator()->create();
    $this->event = fn (string $type, array $data) => WhatsAppWebFixtures::event($this, $this->connection, $type, $data)->assertOk();
    $this->timeline = fn (Conversation $conversation, array $query = []): array => $this->actingAs($this->viewer)
        ->getJson(route('conversations.timeline', [$conversation, ...$query]))->assertOk()->json();
    $this->history = fn (array $overrides = []): array => WhatsAppWebFixtures::inbound(['timestamp' => now()->subDays(90)->getTimestamp(), ...$overrides]);
});

test('an edit replaces the text of the stored message and marks it edited, instead of adding a message', function () {
    ($this->event)('message.inbound', WhatsAppWebFixtures::inbound(['provider_message_id' => '3EB0ORIGINAL', 'text' => 'Pago el martes']));
    Event::fake([ConversationChanged::class, MessageRecorded::class]);
    $editedAt = now()->subSeconds(30)->getTimestamp();
    $edit = ['provider_message_id' => '3EB0ORIGINAL', 'remote_jid' => '5215511112222@s.whatsapp.net', 'from_me' => false, 'timestamp' => $editedAt, 'type' => 'text', 'text' => 'Pago el miércoles'];

    ($this->event)('message.edited', $edit)->assertJsonPath('result', 'applied');
    // The service delivered it twice, and an older edit arrives late.
    ($this->event)('message.edited', $edit)->assertJsonPath('result', 'ignored');
    ($this->event)('message.edited', [...$edit, 'timestamp' => $editedAt - 60, 'text' => 'Pago el lunes'])->assertJsonPath('result', 'ignored');

    $message = Message::query()->sole();

    expect($message->body)->toBe('Pago el miércoles')
        ->and($message->edited_at?->getTimestamp())->toBe($editedAt)
        ->and($message->metadata['content']['body'])->toBe('Pago el miércoles')
        // What it said before is kept.
        ->and($message->metadata['edits'][0]['body'])->toBe('Pago el martes')
        ->and($message->revoked_at)->toBeNull();

    Event::assertDispatchedTimes(ConversationChanged::class, 1);
    Event::assertDispatched(ConversationChanged::class, fn (ConversationChanged $event): bool => $event->reason === 'message' && $event->messageId === $message->id);
    Event::assertNotDispatched(MessageRecorded::class);

    $shown = ($this->timeline)($message->conversation)['messages'][0];

    expect($shown['body'])->toBe('Pago el miércoles')
        ->and($shown['edited_at'])->toBe($message->edited_at->toIso8601String())
        ->and($shown['revoked_at'])->toBeNull();
});

test('an edit of the caption of a file changes the caption, and one of an unknown or foreign message changes nothing', function () {
    Queue::fake([DownloadWhatsAppWebMedia::class]);
    $other = Connection::factory()->whatsAppWeb()->create();
    ($this->event)('message.inbound', WhatsAppWebFixtures::inbound([
        'provider_message_id' => '3EB0PHOTO', 'type' => 'image', 'text' => 'Comprobante',
        'media' => ['mime' => 'image/jpeg', 'size' => 100, 'file_name' => null, 'seconds' => null, 'voice' => false, 'descriptor' => ['kind' => 'image', 'media_key' => 'a2V5', 'direct_path' => '/v/x']],
    ]));
    $edit = fn (string $id): array => ['provider_message_id' => $id, 'remote_jid' => '5215511112222@s.whatsapp.net', 'from_me' => false, 'timestamp' => now()->getTimestamp(), 'type' => 'image', 'text' => 'Comprobante de marzo'];

    WhatsAppWebFixtures::event($this, $other, 'message.edited', $edit('3EB0PHOTO'))->assertOk()->assertJsonPath('result', 'ignored');
    ($this->event)('message.edited', $edit('3EB0UNKNOWN'))->assertJsonPath('result', 'ignored');
    ($this->event)('message.edited', $edit('3EB0PHOTO'))->assertJsonPath('result', 'applied');

    $message = Message::query()->sole();

    expect($message->body)->toBe('Comprobante de marzo')
        ->and($message->metadata['content']['caption'])->toBe('Comprobante de marzo')
        ->and($message->type)->toBe(MessageType::Image)
        ->and(MessageAttachment::query()->count())->toBe(1);
});

test('a message deleted for everyone is marked as deleted by its sender and keeps its content', function () {
    ($this->event)('message.inbound', WhatsAppWebFixtures::inbound(['provider_message_id' => '3EB0REGRET', 'text' => 'No voy a pagar']));
    Event::fake([ConversationChanged::class]);
    $revocation = ['provider_message_id' => '3EB0REGRET', 'remote_jid' => '5215511112222@s.whatsapp.net', 'from_me' => false, 'timestamp' => now()->getTimestamp()];

    ($this->event)('message.revoked', $revocation)->assertJsonPath('result', 'applied');
    ($this->event)('message.revoked', $revocation)->assertJsonPath('result', 'ignored');
    ($this->event)('message.revoked', [...$revocation, 'provider_message_id' => '3EB0UNKNOWN'])->assertJsonPath('result', 'ignored');
    // A deleted message is not edited any more.
    ($this->event)('message.edited', [...$revocation, 'type' => 'text', 'text' => 'Otra cosa'])->assertJsonPath('result', 'ignored');

    $message = Message::query()->sole();

    expect($message->revoked_at)->not->toBeNull()
        ->and($message->body)->toBe('No voy a pagar');

    Event::assertDispatchedTimes(ConversationChanged::class, 1);

    $shown = ($this->timeline)($message->conversation)['messages'][0];

    expect($shown['revoked_at'])->toBe($message->revoked_at->toIso8601String())
        ->and($shown['id'])->toBe($message->id);
});

test('polls, button answers, calls and other kinds arrive with something readable instead of a bare placeholder', function () {
    ($this->event)('message.inbound', WhatsAppWebFixtures::inbound([
        'type' => 'poll', 'text' => "Encuesta: ¿Qué día?\n• Lunes\n• Martes",
        'poll' => ['question' => '¿Qué día?', 'options' => ['Lunes', 'Martes'], 'multiple' => false],
    ]));
    ($this->event)('message.inbound', WhatsAppWebFixtures::inbound([
        'type' => 'interactive', 'text' => 'Sí, confirmo',
        'interactive' => ['kind' => 'button_reply', 'title' => 'Sí, confirmo', 'description' => null],
    ]));
    ($this->event)('message.inbound', WhatsAppWebFixtures::inbound([
        'type' => 'unsupported', 'text' => null, 'unsupported' => ['kind' => 'call_missed', 'label' => 'Llamada perdida'],
    ]));
    ($this->event)('message.inbound', WhatsAppWebFixtures::inbound([
        'type' => 'unsupported', 'text' => 'Junta de cobranza', 'unsupported' => ['kind' => 'event', 'label' => 'Evento'],
    ]));

    [$poll, $button, $call, $event] = ($this->timeline)(Conversation::query()->sole())['messages'];

    expect($poll['type'])->toBe('poll')
        ->and($poll['body'])->toBe("Encuesta: ¿Qué día?\n• Lunes\n• Martes")
        ->and($poll['content'])->toBe(['question' => '¿Qué día?', 'options' => ['Lunes', 'Martes'], 'multiple' => false])
        ->and($button['type'])->toBe('interactive')
        ->and($button['body'])->toBe('Sí, confirmo')
        ->and($button['content'])->toBe(['kind' => 'button_reply', 'title' => 'Sí, confirmo', 'description' => null])
        ->and($call['type'])->toBe('unsupported')
        ->and($call['body'])->toBe('Llamada perdida')
        ->and($call['content'])->toBe(['kind' => 'call_missed', 'label' => 'Llamada perdida'])
        ->and($event['body'])->toBe('Evento: Junta de cobranza');
});

test('voice notes, GIFs and stickers keep what the chat needs to draw them, and a file without key is shown as unavailable', function () {
    Queue::fake([DownloadWhatsAppWebMedia::class]);
    $descriptor = fn (string $kind): array => ['kind' => $kind, 'media_key' => 'a2V5', 'direct_path' => '/v/'.$kind, 'url' => null, 'mime' => null, 'size' => 10];
    $thumbnail = base64_encode("\xFF\xD8".str_repeat('x', 40));

    ($this->event)('message.inbound', WhatsAppWebFixtures::inbound([
        'type' => 'audio', 'text' => null,
        'media' => ['mime' => 'audio/ogg', 'size' => 9000, 'file_name' => null, 'seconds' => 7, 'voice' => true, 'descriptor' => $descriptor('audio')],
    ]));
    ($this->event)('message.inbound', WhatsAppWebFixtures::inbound([
        'type' => 'video', 'text' => null,
        'media' => ['mime' => 'video/mp4', 'size' => 9000, 'file_name' => null, 'seconds' => 3, 'voice' => false, 'gif' => true, 'width' => 320, 'height' => 240, 'thumbnail' => $thumbnail, 'descriptor' => $descriptor('video')],
    ]));
    ($this->event)('message.inbound', WhatsAppWebFixtures::inbound([
        'type' => 'sticker', 'text' => null,
        'media' => ['mime' => 'image/webp', 'size' => 9000, 'file_name' => null, 'seconds' => null, 'voice' => false, 'animated' => true, 'descriptor' => $descriptor('sticker')],
    ]));
    // "View once" media reaches a linked device without its key.
    ($this->event)('message.inbound', WhatsAppWebFixtures::inbound([
        'type' => 'image', 'text' => null, 'view_once' => true,
        'media' => ['mime' => 'image/jpeg', 'size' => null, 'file_name' => null, 'seconds' => null, 'voice' => false, 'descriptor' => null],
    ]));

    [$voice, $gif, $sticker, $once] = ($this->timeline)(Conversation::query()->sole())['messages'];

    expect($voice['content'])->toBe(['voice' => true, 'seconds' => 7])
        ->and($voice['attachments'][0]['mime_type'])->toBe('audio/ogg')
        ->and($voice['attachments'][0]['status'])->toBe('pending')
        ->and($gif['content']['gif'])->toBeTrue()
        ->and($gif['content']['thumbnail'])->toBe('data:image/jpeg;base64,'.$thumbnail)
        ->and($gif['attachments'][0]['width'])->toBe(320)
        ->and($gif['attachments'][0]['height'])->toBe(240)
        ->and($sticker['content'])->toBe(['animated' => true])
        ->and($once['type'])->toBe('image')
        ->and($once['content']['view_once'])->toBeTrue()
        ->and($once['attachments'][0]['available'])->toBeFalse()
        ->and($once['attachments'][0]['status'])->toBe('unavailable')
        ->and($once['attachments'][0]['error_code'])->toBe('expired');

    // Only the three files that can be fetched are queued.
    Queue::assertPushed(DownloadWhatsAppWebMedia::class, 3);
    // The download key never travels to the browser.
    expect(json_encode([$voice, $gif, $sticker]))->not->toContain('a2V5')->not->toContain('media_key');
});

test('a reply shows the quoted message it has, or the excerpt WhatsApp sent when the original was never stored', function () {
    ($this->event)('message.inbound', WhatsAppWebFixtures::inbound(['provider_message_id' => '3EB0FIRST', 'text' => 'El primero']));
    ($this->event)('message.inbound', WhatsAppWebFixtures::inbound([
        'provider_message_id' => '3EB0KNOWN', 'quoted' => ['provider_message_id' => '3EB0FIRST', 'from_me' => false, 'text' => 'El primero'],
    ]));
    ($this->event)('message.inbound', WhatsAppWebFixtures::inbound([
        'provider_message_id' => '3EB0OLDQUOTE', 'quoted' => ['provider_message_id' => '3EB0BEFORELINK', 'from_me' => true, 'text' => 'Le esperamos el viernes'],
    ]));

    [$first, $known, $external] = ($this->timeline)(Conversation::query()->sole())['messages'];

    expect($first['reply_to'])->toBeNull()
        ->and($first['reactions'])->toBe([])
        ->and($first['is_historical'])->toBeFalse()
        ->and($known['reply_to'])->toMatchArray(['id' => $first['id'], 'origin' => 'conversation', 'preview' => 'El primero', 'from_me' => false])
        ->and($external['reply_to'])->toBe([
            'id' => null,
            'direction' => 'outbound',
            'type' => null,
            'body' => 'Le esperamos el viernes',
            'origin' => 'external',
            'conversation_id' => null,
            'url' => null,
            'preview' => 'Le esperamos el viernes',
            'from_me' => true,
        ]);
});

test('a history slice is only queued by the internal endpoint, encrypted, and is discarded when the import is off', function () {
    Queue::fake([ImportWhatsAppWebHistoryBatch::class]);
    $messages = array_map(fn (int $index): array => ($this->history)(['provider_message_id' => 'HIST'.$index]), range(1, 150));

    ($this->event)('history.batch', ['sync_type' => 'recent', 'progress' => 40, 'messages' => $messages])->assertJsonPath('result', 'queued');

    Queue::assertPushed(ImportWhatsAppWebHistoryBatch::class, 2);
    Queue::assertPushed(ImportWhatsAppWebHistoryBatch::class, fn (ImportWhatsAppWebHistoryBatch $job): bool => $job->connectionId === $this->connection->id
        && count($job->messages) === 100 && $job instanceof ShouldBeEncrypted && $job->queue === 'default');

    config(['whatsapp_web.history.enabled' => false]);
    ($this->event)('history.batch', ['messages' => $messages])->assertJsonPath('result', 'ignored');
    ($this->event)('history.batch', ['messages' => []])->assertJsonPath('result', 'ignored');

    Queue::assertPushed(ImportWhatsAppWebHistoryBatch::class, 2);
    expect(Message::query()->count())->toBe(0);
});

test('the history of a chat becomes one closed, read-only conversation with every message at its own time and direction', function () {
    Event::fake([MessageRecorded::class, ConversationChanged::class]);
    Notification::fake();
    $agent = User::factory()->agent()->available()->inDepartments($this->department)->create();
    $this->department->forceFill(['distribution_mode' => 'automatic'])->save();
    $start = now()->subDays(200)->startOfMinute();
    // As the phone hands them over: newest first.
    $messages = [
        ($this->history)(['provider_message_id' => 'H3', 'timestamp' => $start->copy()->addMinutes(2)->getTimestamp(), 'text' => 'Gracias', 'chat_name' => 'Doña Lupita (cliente)', 'push_name' => 'Lupita']),
        ($this->history)(['provider_message_id' => 'H2', 'timestamp' => $start->copy()->addMinute()->getTimestamp(), 'text' => 'Con gusto, queda registrado', 'from_me' => true, 'push_name' => null, 'status' => 'read',
            'quoted' => ['provider_message_id' => 'H1', 'from_me' => false, 'text' => 'Ya pagué'], 'reactions' => [['emoji' => '🙏', 'from_me' => false]]]),
        ($this->history)(['provider_message_id' => 'H1', 'timestamp' => $start->getTimestamp(), 'text' => 'Ya pagué', 'push_name' => 'Lupita']),
        ($this->history)(['provider_message_id' => 'H4', 'timestamp' => $start->copy()->addMinutes(3)->getTimestamp(), 'type' => 'text', 'text' => null, 'revoked' => true]),
        // A group never is history of a customer.
        ($this->history)(['provider_message_id' => 'G1', 'remote_jid' => '120363012345678901@g.us']),
    ];

    ($this->event)('history.batch', ['sync_type' => 'recent', 'progress' => 100, 'messages' => $messages])->assertJsonPath('result', 'queued');

    $conversation = Conversation::query()->sole();
    $contact = Contact::query()->sole();
    $stored = Message::query()->orderBy('sent_at')->get();

    expect($conversation->origin)->toBe(ConversationOrigin::WhatsappHistory)
        ->and($conversation->isImported())->toBeTrue()
        ->and($conversation->status)->toBe(ConversationStatus::Closed)
        ->and($conversation->assigned_user_id)->toBeNull()
        ->and($conversation->department_id)->toBe($this->department->id)
        ->and($conversation->created_at->getTimestamp())->toBe($start->getTimestamp())
        ->and($conversation->closed_at->getTimestamp())->toBe($start->copy()->addMinutes(3)->getTimestamp())
        ->and($conversation->last_message_at->getTimestamp())->toBe($start->copy()->addMinutes(3)->getTimestamp())
        // No customer service window, no response clock.
        ->and($conversation->last_inbound_at)->toBeNull()
        ->and($conversation->first_response_at)->toBeNull()
        ->and($contact->name)->toBe('Doña Lupita (cliente)')
        ->and($contact->saved_at)->toBeNull()
        ->and($stored->pluck('whatsapp_message_id')->all())->toBe(array_map(fn (string $id): string => WhatsAppWebTransport::storedId($this->connection->id, $id), ['H1', 'H2', 'H3', 'H4']))
        ->and($stored->every(fn (Message $message): bool => $message->is_historical && $message->created_at->equalTo($message->sent_at)))->toBeTrue()
        ->and($stored[0]->direction)->toBe(MessageDirection::Inbound)
        ->and($stored[0]->status)->toBe(MessageStatus::Received)
        ->and($stored[1]->direction)->toBe(MessageDirection::Outbound)
        ->and($stored[1]->status)->toBe(MessageStatus::Read)
        ->and($stored[1]->sender_user_id)->toBeNull()
        ->and($stored[1]->reply_to_message_id)->toBe($stored[0]->id)
        ->and($stored[3]->revoked_at)->not->toBeNull()
        ->and($agent->refresh()->last_assigned_at)->toBeNull();

    // Nothing of a live message happened.
    Event::assertNotDispatched(MessageRecorded::class);
    Event::assertNotDispatched(ConversationChanged::class);
    Notification::assertNothingSent();

    $shown = ($this->timeline)($conversation)['messages'];

    expect(array_column($shown, 'body'))->toBe(['Ya pagué', 'Con gusto, queda registrado', 'Gracias', null])
        ->and(array_column($shown, 'is_historical'))->toBe([true, true, true, true])
        ->and($shown[1]['direction'])->toBe('outbound')
        ->and($shown[1]['sent_from_phone'])->toBeTrue()
        ->and($shown[1]['reactions'])->toBe([['emoji' => '🙏', 'from_me' => false]])
        ->and($shown[1]['reply_to'])->toMatchArray(['id' => $shown[0]['id'], 'preview' => 'Ya pagué', 'from_me' => false])
        ->and($shown[1]['sent_at'])->toBe($stored[1]->sent_at->toIso8601String())
        ->and($shown[3]['revoked_at'])->not->toBeNull();

    // Nobody can answer, reopen or take a history conversation.
    $this->actingAs($this->viewer)->postJson(route('conversations.reopen', $conversation), ['version' => $conversation->version])->assertForbidden();
});

test('importing is idempotent: repeated slices, overlaps and messages already received live add nothing', function () {
    ($this->event)('message.inbound', WhatsAppWebFixtures::inbound(['provider_message_id' => 'LIVE1', 'text' => 'Mensaje en vivo']));
    $live = Conversation::query()->sole();
    $liveState = $live->only(['status', 'last_message_at', 'last_inbound_at', 'assigned_user_id', 'version']);
    Event::fake([MessageRecorded::class]);
    $slice = [
        ($this->history)(['provider_message_id' => 'OLD1', 'text' => 'De hace meses']),
        // WhatsApp replays in the history what also arrived live.
        WhatsAppWebFixtures::inbound(['provider_message_id' => 'LIVE1', 'text' => 'Mensaje en vivo']),
    ];
    $import = app(ImportWhatsAppWebHistory::class);

    expect($import->handle($this->connection, $slice))->toMatchArray(['imported' => 1, 'duplicates' => 1, 'conversations' => 1])
        ->and($import->handle($this->connection, $slice))->toMatchArray(['imported' => 0, 'duplicates' => 2, 'conversations' => 0])
        ->and($import->handle($this->connection, [...$slice, ($this->history)(['provider_message_id' => 'OLD2', 'timestamp' => now()->subDays(120)->getTimestamp()])]))
        ->toMatchArray(['imported' => 1, 'duplicates' => 2, 'conversations' => 0]);

    $history = Conversation::query()->where('origin', ConversationOrigin::WhatsappHistory)->sole();

    expect(Message::query()->count())->toBe(3)
        ->and(Message::query()->where('conversation_id', $history->id)->count())->toBe(2)
        // The live conversation and its message were not touched.
        ->and($live->refresh()->only(['status', 'last_message_at', 'last_inbound_at', 'assigned_user_id', 'version']))->toEqual($liveState)
        ->and(Message::query()->where('conversation_id', $live->id)->sole()->is_historical)->toBeFalse()
        ->and(Contact::query()->count())->toBe(1);

    Event::assertNotDispatched(MessageRecorded::class);

    // The customer writes again: the live conversation continues; the history is never reopened.
    Event::fake([]);
    ($this->event)('message.inbound', WhatsAppWebFixtures::inbound(['provider_message_id' => 'LIVE2']))->assertJsonPath('result', 'applied');

    expect(Message::query()->where('conversation_id', $live->id)->count())->toBe(2)
        ->and($history->refresh()->status)->toBe(ConversationStatus::Closed);
});

test('history handed over newest slice first is still read in the order it was written, page by page', function () {
    $import = app(ImportWhatsAppWebHistory::class);
    $start = now()->subDays(100)->startOfMinute();
    $message = fn (int $index): array => ($this->history)(['provider_message_id' => 'M'.str_pad((string) $index, 3, '0', STR_PAD_LEFT), 'timestamp' => $start->copy()->addMinutes($index)->getTimestamp(), 'text' => 'Mensaje '.$index]);
    $total = ConversationTimeline::PAGE_SIZE + 20;

    // The newest slice arrives first, the oldest last.
    $import->handle($this->connection, array_map($message, range($total - 29, $total)));
    $import->handle($this->connection, array_map($message, range(1, $total - 30)));

    $conversation = Conversation::query()->sole();
    $newest = ($this->timeline)($conversation);

    expect($newest['has_more'])->toBeTrue()
        ->and(count($newest['messages']))->toBe(ConversationTimeline::PAGE_SIZE)
        ->and($newest['messages'][0]['body'])->toBe('Mensaje 21')
        ->and(end($newest['messages'])['body'])->toBe('Mensaje '.$total);

    $older = ($this->timeline)($conversation, ['before' => $newest['messages'][0]['id']]);

    expect(array_column($older['messages'], 'body'))->toBe(array_map(fn (int $index): string => 'Mensaje '.$index, range(1, 20)))
        ->and($older['has_more'])->toBeFalse();
});

test('files of the history are fetched only while WhatsApp may still serve them; older ones keep the message and its preview', function () {
    Queue::fake([DownloadWhatsAppWebMedia::class]);
    $thumbnail = base64_encode("\xFF\xD8".str_repeat('y', 40));
    $media = fn (array $extra = []): array => ['mime' => 'image/jpeg', 'size' => 48213, 'file_name' => null, 'seconds' => null, 'voice' => false, 'thumbnail' => $thumbnail,
        'descriptor' => ['kind' => 'image', 'media_key' => 'b2xkLWtleQ==', 'direct_path' => '/v/old'], ...$extra];

    $result = app(ImportWhatsAppWebHistory::class)->handle($this->connection, [
        ($this->history)(['provider_message_id' => 'OLDPHOTO', 'type' => 'image', 'text' => 'Recibo viejo', 'media' => $media(), 'timestamp' => now()->subDays(200)->getTimestamp()]),
        ($this->history)(['provider_message_id' => 'NEWPHOTO', 'type' => 'image', 'text' => 'Recibo reciente', 'media' => $media(), 'timestamp' => now()->subDays(3)->getTimestamp()]),
    ]);

    [$old, $recent] = Message::query()->orderBy('sent_at')->with('attachments')->get()->all();

    expect($result['downloads'])->toBe(1)
        ->and($old->body)->toBe('Recibo viejo')
        ->and($old->attachments[0]->status)->toBe(AttachmentStatus::Unavailable)
        ->and($old->attachments[0]->error_code)->toBe('historical_missing')
        ->and($old->attachments[0]->provider_reference)->toBeNull()
        ->and($recent->attachments[0]->status)->toBe(AttachmentStatus::Pending)
        ->and($recent->attachments[0]->provider_reference)->not->toBeNull()
        ->and(json_encode($old->metadata))->not->toContain('b2xkLWtleQ');

    Queue::assertPushed(DownloadWhatsAppWebMedia::class, 1);
    Queue::assertPushed(DownloadWhatsAppWebMedia::class, fn (DownloadWhatsAppWebMedia $job): bool => $job->attachmentId === $recent->attachments[0]->id);

    $shown = ($this->timeline)(Conversation::query()->sole())['messages'][0];

    expect($shown['content']['thumbnail'])->toBe('data:image/jpeg;base64,'.$thumbnail)
        ->and($shown['attachments'][0]['error_code'])->toBe('historical_missing')
        ->and($shown['attachments'][0]['available'])->toBeFalse();
});

test('a slice for a deleted connection or with unusable entries imports only what is valid', function () {
    $import = app(ImportWhatsAppWebHistory::class);

    expect($import->handle($this->connection, [
        'not-a-message',
        ($this->history)(['provider_message_id' => 'bad id with spaces']),
        ($this->history)(['timestamp' => 0]),
        ($this->history)(['remote_jid' => 'status@broadcast']),
        ($this->history)(['provider_message_id' => 'GOOD1']),
    ]))->toMatchArray(['imported' => 1, 'skipped' => 4]);

    $this->connection->forceFill(['deleted_at' => now(), 'is_active' => false])->save();
    (new ImportWhatsAppWebHistoryBatch($this->connection->id, [($this->history)(['provider_message_id' => 'AFTERDELETE'])]))->handle($import);

    expect(Message::query()->count())->toBe(1);
});

<?php

use App\Enums\AttachmentStatus;
use App\Enums\DataTransferStatus;
use App\Enums\HistoryImportFileKind;
use App\Enums\HistoryImportFileStatus;
use App\Enums\HistoryImportRowState;
use App\Enums\MessageType;
use App\Http\Requests\Admin\HistoryImportFileRequest;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\DataTransfer;
use App\Models\HistoryImportConversation;
use App\Models\HistoryImportFile;
use App\Models\HistoryImportMessage;
use App\Models\HistoryImportSourceKey;
use App\Models\Message;
use App\Models\MessageAttachment;
use App\Models\User;
use App\Services\Migration\History\HistoryColumns;
use App\Services\Migration\History\HistoryContactResolver;
use App\Services\Migration\History\HistoryFileIngestor;
use App\Services\Migration\History\HistoryImportBatches;
use App\Services\Migration\History\HistoryValues;
use App\Support\Attachments\ServerUploadLimits;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;

require_once __DIR__.'/HistoryImportSupport.php';

/*
| The authentic Whaticket messages export («Configuración → Exportación →
| Exportar mensajes»), verified on 2026-11-12 with a real file of 447,429
| messages. tests/fixtures/whaticket/messages-export.csv reproduces its
| exact shape with FICTITIOUS data: the same header (camelCase and
| snake_case mixed, no message id), day-first dates without seconds, rows
| sent by the company without contact columns, a ticket without a number
| (Messenger / Instagram), a ticket that identifies nobody, a place and a
| shared contact as JSON, a missed call, files that the export does not
| include, expired CDN links, multi-line texts, identical rows in the same
| minute and a campaign id.
*/

const REAL_TICKET = 'ticket:00000000-0000-4000-8000-00000000000';

beforeEach(function () {
    // The Vue pages are checked by vue-tsc and the browser scenarios.
    config(['inertia.testing.ensure_pages_exist' => false]);
    Storage::fake('local');
    $this->admin = User::factory()->superAdministrator()->create();
});

/**
 * A batch with the fixture read (nothing decided).
 */
function realExportBatch(User $admin, ?string $content = null)
{
    $batch = historyBatch($admin);
    historyAddFile($admin, $batch, 'messages', $content === null
        ? historyFixtureUpload('messages-export.csv')
        : historyUpload($content, 'whaticket_messages.csv'));

    return $batch->refresh();
}

/**
 * Import what has a number, archive the rest and run.
 */
function realExportRun(User $admin, $batch): array
{
    historyMapPlaceholders($admin, $batch);
    historyDecide($admin, $batch, 'import', ['with_phone' => true]);
    historyDecide($admin, $batch, 'archive', ['without_phone' => true]);

    return historyConfirm($admin, $batch);
}

/**
 * The fixture plus rows a later export would add.
 */
function realExportLater(): string
{
    return rtrim((string) file_get_contents(historyFixture('messages-export.csv')))."\n".implode("\n", [
        // A new message of an imported ticket.
        '00000000-0000-4000-8000-000000000001,Cliente Ficticio Uno ,5215500000001,20/10/2024 10:00,false,Mensaje nuevo (ficticio),chat,,',
        // A third copy of the greeting already imported twice in that minute.
        '00000000-0000-4000-8000-000000000001,,,13/10/2024 01:18,true,"Bienvenido a *EMPRESA FICTICIA* ❗',
        '',
        '🔔 *Indica una opción* (selecciona 1 o 2):',
        '',
        '*1* - Opción uno',
        '*2* - Opción dos",,,',
        // A new ticket.
        '00000000-0000-4000-8000-000000000007,Cliente Ficticio Siete,5215500000007,21/10/2024 09:30,false,Hola de nuevo (ficticio),chat,,',
    ])."\n";
}

test('the header of the real export is mapped without manual work and labelled as verified', function () {
    $batch = realExportBatch($this->admin);
    $file = HistoryImportFile::query()->where('data_transfer_id', $batch->id)->sole();

    expect($file->status)->toBe(HistoryImportFileStatus::Parsed)
        ->and($file->column_mapping)->toMatchArray([
            'conversation_id' => 0,
            'contact_name' => 1,
            'contact_number' => 2,
            'created_at' => 3,
            'from_me' => 4,
            'body' => 5,
            'media_type' => 6,
            'media_name' => 7,
            'campaign_id' => 8,
            'message_id' => null,
        ])
        ->and($file->unknown_headers)->toBe([])
        ->and($file->missing_fields)->toBe([])
        ->and($file->format_version)->toBe(HistoryFileIngestor::FORMAT_MESSAGES_VERIFIED)
        ->and($file->rows_total)->toBe(23)
        ->and($file->rows_accepted)->toBe(23)
        ->and($file->rows_rejected)->toBe(0)
        ->and($file->issues)->toBeNull()
        ->and(app(HistoryImportBatches::class)->present($batch)['files'][0]['verified_format'])->toBeTrue();

    // Any other header stays "not verified", even when its columns are recognized.
    $other = historyBatch($this->admin);
    historyAddFile($this->admin, $other, 'messages', historyFixtureUpload('messages.csv'));

    expect(app(HistoryImportBatches::class)->present($other->refresh())['files'][0]['verified_format'])->toBeFalse()
        ->and(HistoryImportFile::query()->where('data_transfer_id', $other->id)->sole()->format_version)->toBe('whaticket-messages-csv:no-verificado');
});

test('snake_case, camelCase and the verified header are recognized', function () {
    $columns = app(HistoryColumns::class);

    expect(HistoryColumns::normalize('contact_name'))->toBe('contact name')
        ->and(HistoryColumns::normalize('Contact-Number'))->toBe('contact number')
        ->and(HistoryColumns::normalize('createdAt'))->toBe('createdat')
        ->and($columns->isVerifiedMessagesExport(['ticketId', 'contact_name', 'contact_number', 'createdAt', 'fromMe', 'body', 'mediaType', 'mediaUrl', 'campaignId']))->toBeTrue()
        ->and($columns->isVerifiedMessagesExport(['campaignId', 'mediaUrl', 'mediaType', 'body', 'fromMe', 'createdAt', 'contact_number', 'contact_name', 'ticketId']))->toBeTrue()
        ->and($columns->isVerifiedMessagesExport(['ticketId', 'contact_name', 'contact_number', 'createdAt', 'fromMe', 'body', 'mediaType', 'mediaUrl']))->toBeFalse()
        ->and($columns->isVerifiedMessagesExport(['id', 'ticketId', 'contactNumber', 'body', 'createdAt']))->toBeFalse();
});

test('places, shared contacts and missed calls of the real export are read as such', function () {
    $values = app(HistoryValues::class);

    expect($values->location('{"degreesLatitude":19.4326077,"degreesLongitude":-99.133208}'))->toBe([
        'content' => ['latitude' => 19.4326077, 'longitude' => -99.133208],
        'text' => 'Ubicación: 19.4326077, -99.133208',
    ])
        ->and($values->location('{"latitude":"19.5","longitude":"-99.1","name":"Sucursal Ficticia"}')['text'])->toBe('Sucursal Ficticia')
        ->and($values->location('{"degreesLatitude":190,"degreesLongitude":-99}'))->toBeNull()
        ->and($values->location('Nos vemos en la sucursal'))->toBeNull()
        ->and($values->location('[1,2]'))->toBeNull()
        ->and($values->sharedContacts('[{"name":"Contacto Ficticio","number":"+52 55 0000 0099"}]'))->toBe([
            'content' => [['name' => ['formatted_name' => 'Contacto Ficticio'], 'phones' => [['phone' => '+52 55 0000 0099']]]],
            'text' => 'Contacto compartido: Contacto Ficticio (+52 55 0000 0099)',
        ])
        ->and($values->sharedContacts("BEGIN:VCARD\nVERSION:3.0\nFN:Otro Ficticio\nTEL;type=CELL;waid=525500000098:+52 55 0000 0098\nEND:VCARD")['text'])->toBe('Contacto compartido: Otro Ficticio (+52 55 0000 0098)')
        ->and($values->sharedContacts('[{"name":"<b>Solo nombre</b>","number":"llámame"}]')['content'])->toBe([['name' => ['formatted_name' => 'Solo nombre'], 'phones' => []]])
        ->and($values->sharedContacts('Te paso su contacto'))->toBeNull()
        ->and($values->isMissedCall('missed'))->toBeTrue()
        ->and($values->isMissedCall('chat'))->toBeFalse()
        ->and($values->type('application', null))->toBe(MessageType::Document)
        ->and($values->type('file', 'documento.pdf'))->toBe(MessageType::Document)
        ->and($values->type('contact', null))->toBe(MessageType::Contacts)
        // 13 digits (legacy "521"), 12 digits and 10 national digits are the same Mexican number.
        ->and($values->phone('5215500000001'))->toBe('+525500000001')
        ->and($values->phone('525500000001'))->toBe('+525500000001')
        ->and($values->phone('5500000001'))->toBe('+525500000001')
        ->and($values->phone('550000005'))->toBeNull()
        ->and($values->date('13/10/2024 01:18', 'America/Mexico_City')?->toDateTimeString())->toBe('2024-10-13 07:18:00');
});

test('a row without message id gets a fingerprint that tells repeated rows apart and never depends on the line', function () {
    $seen = [];
    $identity = hash('sha256', 'a', true);

    $first = HistoryFileIngestor::messageFingerprint($identity, $seen);
    $second = HistoryFileIngestor::messageFingerprint($identity, $seen);
    $other = HistoryFileIngestor::messageFingerprint(hash('sha256', 'b', true), $seen);

    $again = [];

    expect($first)->toStartWith('fp:')->toHaveLength(43)
        ->and($second)->not->toBe($first)
        ->and($other)->not->toBe($first)
        ->and(HistoryFileIngestor::messageFingerprint($identity, $again))->toBe($first)
        ->and(HistoryFileIngestor::messageFingerprint($identity, $again))->toBe($second);
});

test('every row of the real export is staged: contact by ticket, file order, types and what the export does not include', function () {
    $batch = realExportBatch($this->admin);
    $staged = HistoryImportMessage::query()->where('data_transfer_id', $batch->id)->orderBy('id')->get();

    expect($staged)->toHaveCount(23)
        ->and($staged->pluck('source_message_id')->unique())->toHaveCount(23)
        ->and($staged->pluck('source_message_id')->every(fn (string $id): bool => str_starts_with($id, 'fp:')))->toBeTrue()
        ->and($staged->whereNull('from_me'))->toHaveCount(0);

    $first = historyRow($batch, REAL_TICKET.'1');
    $messages = $staged->where('history_import_conversation_id', $first->id)->values();

    // The rows sent by the company have no contact columns: the ticket gives the contact.
    expect($first->contact_name)->toBe('Cliente Ficticio Uno')
        ->and($first->contact_phone)->toBe('+525500000001')
        ->and($first->message_count)->toBe(13)
        ->and($first->inbound_count)->toBe(9)
        ->and($first->outbound_count)->toBe(4)
        ->and($first->attachments_total)->toBe(6)
        ->and($first->attachments_available)->toBe(0)
        ->and($first->first_message_at?->toDateTimeString())->toBe('2024-10-13 07:18:00')
        ->and($first->last_message_at?->toDateTimeString())->toBe('2024-10-14 15:00:00')
        ->and(collect($first->issues)->pluck('code')->all())->toBe(['attachments_missing'])
        ->and($first->recommendation)->toBeNull();

    // Minute resolution: the order inside the minute is the order of the file.
    expect($messages[0]->body)->toBe('Hola (mensaje ficticio)')
        ->and($messages[0]->from_me)->toBeFalse()
        ->and($messages[1]->from_me)->toBeTrue()
        ->and($messages[1]->body)->toContain("*1* - Opción uno\n*2* - Opción dos")
        ->and($messages[2]->body)->toBe($messages[1]->body)
        ->and($messages[2]->source_message_id)->not->toBe($messages[1]->source_message_id)
        ->and($messages[1]->contact_phone)->toBeNull();

    expect($messages[3]->type)->toBe('location')
        ->and($messages[3]->body)->toBe('Ubicación: 19.4326077, -99.133208')
        ->and($messages[3]->details['content'])->toBe(['latitude' => 19.4326077, 'longitude' => -99.133208])
        ->and($messages[4]->type)->toBe('contacts')
        ->and($messages[4]->body)->toBe('Contacto compartido: Contacto Ficticio (+52 55 0000 0099)')
        ->and($messages[4]->details['content'][0]['phones'][0]['phone'])->toBe('+52 55 0000 0099')
        ->and($messages[5]->type)->toBe('text')
        ->and($messages[5]->body)->toBe('Llamada perdida')
        ->and($messages[5]->details)->toBe(['event' => 'missed_call'])
        // A photo without text nor file is still a message (its file is just not in the export).
        ->and($messages[6]->type)->toBe('image')
        ->and($messages[6]->body)->toBeNull()
        ->and($messages[6]->media_name)->toBeNull()
        ->and($messages[7]->source_message_id)->not->toBe($messages[6]->source_message_id)
        ->and($messages[8]->type)->toBe('document')
        ->and($messages[8]->body)->toBe('Documento ficticio')
        ->and($messages[9]->type)->toBe('video')
        ->and($messages[10]->type)->toBe('audio')
        ->and($messages[11]->type)->toBe('document')
        ->and($messages[12]->details)->toBe(['campaign_id' => '00000000-0000-4000-9000-0000000000c1'])
        ->and($messages[12]->type)->toBe('text');

    // An expired CDN link only gives the file name: nothing is downloaded (Http::preventStrayRequests()).
    $third = historyRow($batch, REAL_TICKET.'3');
    $media = $staged->where('history_import_conversation_id', $third->id)->whereNotNull('media_name')->values();

    expect($media->pluck('media_name')->all())->toBe(['000000000_000000000000000_n.jpg', '000000000_documento-ficticio.pdf'])
        ->and($media->pluck('type')->all())->toBe(['image', 'document'])
        ->and($media->pluck('media_available')->all())->toBe([false, false])
        ->and($staged->pluck('body')->filter()->contains(fn (string $body): bool => str_contains($body, 'example.invalid')))->toBeFalse();
});

test('tickets without a number or without any contact are classified without inventing data', function () {
    $batch = realExportBatch($this->admin);

    $national = historyRow($batch, REAL_TICKET.'2');
    $nameOnly = historyRow($batch, REAL_TICKET.'3');
    $nobody = historyRow($batch, REAL_TICKET.'4');
    $short = historyRow($batch, REAL_TICKET.'5');
    $twelve = historyRow($batch, REAL_TICKET.'6');

    expect($national->contact_phone)->toBe('+525500000002')
        ->and($twelve->contact_phone)->toBe('+525500000001')
        // Messenger / Instagram: a name, no number.
        ->and($nameOnly->contact_phone)->toBeNull()
        ->and($nameOnly->contact_name)->toBe('Cliente Ficticio Tres')
        ->and(collect($nameOnly->issues)->pluck('code')->all())->toBe(['no_phone', 'attachments_missing'])
        ->and($nameOnly->recommendation)->toBe('archive')
        ->and($nameOnly->allowedDecisions())->toHaveCount(3)
        // Only messages of the company: nobody to attach an operational conversation to.
        ->and($nobody->hasIdentifiableContact())->toBeFalse()
        ->and($nobody->inbound_count)->toBe(0)
        ->and($nobody->message_count)->toBe(2)
        ->and(collect($nobody->issues)->pluck('code')->all())->toBe(['no_contact'])
        ->and($nobody->recommendation)->toBe('archive')
        ->and(array_map(fn ($decision) => $decision->value, $nobody->allowedDecisions()))->toBe(['archive', 'exclude'])
        // A number that cannot be normalized is kept as written, never completed.
        ->and($short->contact_phone)->toBeNull()
        ->and($short->contact_identifier)->toBe('550000005')
        ->and(collect($short->issues)->pluck('code')->all())->toBe(['phone_invalid']);

    $inventory = fn (array $filters) => $this->actingAs($this->admin)
        ->getJson(route('admin.history-import.inventory.index', [$batch, ...$filters]))->assertOk()->json('meta.total');

    expect($inventory([]))->toBe(6)
        ->and($inventory(['with_phone' => 1]))->toBe(3)
        ->and($inventory(['without_phone' => 1]))->toBe(3);

    // «Importar» is refused for the ticket that identifies nobody, one by one and in bulk.
    $this->actingAs($this->admin)
        ->patchJson(route('admin.history-import.inventory.update', [$batch, $nobody]), ['decision' => 'import'])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['decision']);

    expect(historyDecide($this->admin, $batch, 'import'))->toMatchArray(['matched' => 6, 'updated' => 5, 'not_allowed' => 1])
        ->and($nobody->refresh()->decision)->toBeNull();
});

test('the real export is imported end to end with its original dates, order and structured messages', function () {
    $batch = realExportBatch($this->admin);
    $summary = realExportRun($this->admin, $batch);

    expect($summary['totals'])->toMatchArray(['conversations' => 6, 'import' => 3, 'archive' => 3, 'exclude' => 0, 'undecided' => 0])
        ->and($summary['messages']['total'])->toBe(23)
        ->and($summary['attachments'])->toMatchArray(['recoverable' => 0, 'missing' => 8])
        ->and($batch->refresh()->status)->toBe(DataTransferStatus::Completed)
        ->and($batch->summary)->toMatchArray(['outcome' => 'completed', 'imported' => 3, 'archived' => 3, 'failed' => 0, 'messages' => 23, 'attachments_available' => 0, 'attachments_missing' => 8]);

    $conversation = Conversation::query()->findOrFail(historyRow($batch, REAL_TICKET.'1')->result_conversation_id);
    $messages = Message::query()->where('conversation_id', $conversation->id)->orderBy('created_at')->orderBy('id')->get();

    expect($messages)->toHaveCount(13)
        ->and($conversation->isImported())->toBeTrue()
        ->and($conversation->isHistoryOnly())->toBeFalse()
        ->and($conversation->created_at?->toDateTimeString())->toBe('2024-10-13 07:18:00')
        ->and($conversation->closed_at?->toDateTimeString())->toBe('2024-10-14 15:00:00')
        ->and($conversation->last_inbound_at)->toBeNull()
        ->and($messages->pluck('direction')->map(fn ($direction) => $direction->value)->all())->toBe(['inbound', 'outbound', 'outbound', 'inbound', 'inbound', 'inbound', 'inbound', 'inbound', 'inbound', 'inbound', 'inbound', 'outbound', 'outbound'])
        ->and($messages[0]->created_at?->toDateTimeString())->toBe('2024-10-13 07:18:00')
        ->and($messages[0]->metadata['import'])->toMatchArray(['source' => 'whaticket', 'kind' => 'ticket', 'from_me' => false])
        // The fingerprint is this importer's key, never shown as an id of Whaticket.
        ->and($messages[0]->metadata['import'])->not->toHaveKey('source_id')
        ->and($messages[0]->whatsapp_message_id)->toBeNull()
        ->and($messages[3]->type)->toBe(MessageType::Location)
        ->and($messages[3]->metadata['content'])->toBe(['latitude' => 19.4326077, 'longitude' => -99.133208])
        ->and($messages[4]->type)->toBe(MessageType::Contacts)
        ->and($messages[4]->metadata['content'][0]['name']['formatted_name'])->toBe('Contacto Ficticio')
        ->and($messages[5]->body)->toBe('Llamada perdida')
        ->and($messages[5]->metadata['import']['event'])->toBe('missed_call')
        ->and($messages[12]->metadata['import']['campaign_id'])->toBe('00000000-0000-4000-9000-0000000000c1');

    // Files the export does not include: an unavailable attachment with its type, never an error.
    $attachments = MessageAttachment::query()->whereIn('message_id', $messages->pluck('id'))->get();

    expect($attachments)->toHaveCount(6)
        ->and($attachments->pluck('status')->unique()->all())->toBe([AttachmentStatus::Unavailable])
        ->and($attachments->pluck('error_code')->unique()->all())->toBe(['historical_missing'])
        ->and($attachments->pluck('path')->filter()->all())->toBe([])
        ->and(MessageAttachment::query()->count())->toBe(8);

    // "521…" and "52…" of two tickets are one contact; nobody is merged by name.
    $contact = $conversation->contact;
    $unidentified = Contact::query()->findOrFail(historyRow($batch, REAL_TICKET.'4')->result_contact_id);

    expect($contact->phone_e164)->toBe('+525500000001')
        ->and($contact->name)->toBe('Cliente Ficticio Uno')
        ->and($contact->isSaved())->toBeFalse()
        ->and(historyRow($batch, REAL_TICKET.'6')->result_contact_id)->toBe($contact->id)
        ->and(Contact::query()->findOrFail(historyRow($batch, REAL_TICKET.'3')->result_contact_id)->phone_e164)->toBeNull()
        ->and(Contact::query()->findOrFail(historyRow($batch, REAL_TICKET.'5')->result_contact_id)->phone_e164)->toBeNull()
        ->and($unidentified->name)->toBe(HistoryContactResolver::UNIDENTIFIED_NAME)
        ->and($unidentified->phone_e164)->toBeNull()
        ->and(Contact::query()->count())->toBe(5)
        ->and(Conversation::query()->whereNotNull('history_only_at')->count())->toBe(3);

    // The archive shows the place and the people like a live message would.
    $this->actingAs($this->admin)->get(route('admin.history.conversations.show', $conversation))->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('admin/history/Show')
            ->has('timeline.messages', 13)
            ->where('timeline.messages.3.type', 'location')
            ->where('timeline.messages.3.content.latitude', 19.4326077)
            ->where('timeline.messages.3.content.longitude', -99.133208)
            ->where('timeline.messages.4.content.contacts.0.name', 'Contacto Ficticio')
            ->where('timeline.messages.4.content.contacts.0.phones.0', '+52 55 0000 0099')
            ->where('timeline.messages.5.body', 'Llamada perdida')
            ->where('timeline.messages.6.attachments.0.status', 'unavailable')
            ->where('timeline.messages.6.attachments.0.error_code', 'historical_missing')
            ->where('timeline.messages.1.imported.from_me', true));
});

test('importing the same export again creates nothing', function () {
    realExportRun($this->admin, realExportBatch($this->admin));

    $before = [Conversation::query()->count(), Message::query()->count(), Contact::query()->count(), MessageAttachment::query()->count(), HistoryImportSourceKey::query()->count()];

    $second = realExportBatch($this->admin);
    $row = historyRow($second, REAL_TICKET.'1');

    expect(HistoryImportConversation::query()->where('data_transfer_id', $second->id)->where('already_imported', true)->count())->toBe(6)
        // A ticket imported with a key per message is not skipped blindly: only what is new would be added.
        ->and(collect($row->issues)->firstWhere('code', 'already_imported')['message'])->toContain('solo se agregarán los mensajes que todavía no tiene')
        ->and($row->recommendation)->toBeNull();

    realExportRun($this->admin, $second);

    expect([Conversation::query()->count(), Message::query()->count(), Contact::query()->count(), MessageAttachment::query()->count(), HistoryImportSourceKey::query()->count()])->toBe($before)
        ->and($second->refresh()->status)->toBe(DataTransferStatus::Completed)
        ->and($second->summary)->toMatchArray(['imported' => 0, 'archived' => 0, 'duplicates' => 6, 'failed' => 0])
        ->and(HistoryImportConversation::query()->where('data_transfer_id', $second->id)->pluck('state')->unique()->all())->toBe([HistoryImportRowState::Duplicate]);
});

test('a later export that overlaps an imported one only adds what is new', function () {
    $first = realExportBatch($this->admin);
    realExportRun($this->admin, $first);
    $conversationId = historyRow($first, REAL_TICKET.'1')->result_conversation_id;

    $later = realExportBatch($this->admin, realExportLater());
    realExportRun($this->admin, $later);

    $messages = Message::query()->where('conversation_id', $conversationId)->orderBy('created_at')->orderBy('id')->get();
    $greeting = $messages[1]->body;

    expect(Conversation::query()->count())->toBe(7)
        ->and(Message::query()->count())->toBe(26)
        ->and(historyRow($later, REAL_TICKET.'1')->state)->toBe(HistoryImportRowState::Done)
        ->and(historyRow($later, REAL_TICKET.'1')->result_conversation_id)->toBe($conversationId)
        ->and(historyRow($later, REAL_TICKET.'2')->state)->toBe(HistoryImportRowState::Duplicate)
        ->and(historyRow($later, REAL_TICKET.'7')->state)->toBe(HistoryImportRowState::Done)
        ->and($messages)->toHaveCount(15)
        // The third identical greeting of that minute is a third message, not a duplicate of the first two.
        ->and($messages->where('body', $greeting))->toHaveCount(3)
        ->and($messages->last()->body)->toBe('Mensaje nuevo (ficticio)')
        ->and(Conversation::query()->findOrFail($conversationId)->last_message_at?->toDateTimeString())->toBe('2024-10-20 16:00:00')
        ->and($later->refresh()->summary)->toMatchArray(['duplicates' => 5, 'failed' => 0]);

    // And once more: nothing left to add.
    $again = realExportBatch($this->admin, realExportLater()."\n");
    realExportRun($this->admin, $again);

    expect(Message::query()->count())->toBe(26)
        ->and(Conversation::query()->count())->toBe(7);
});

test('two overlapping exports in the same batch count every message once', function () {
    $batch = realExportBatch($this->admin);
    historyAddFile($this->admin, $batch, 'messages', historyUpload(realExportLater(), 'whaticket_messages_2.csv'));

    $second = HistoryImportFile::query()->where('data_transfer_id', $batch->id)->orderByDesc('id')->firstOrFail();

    expect(HistoryImportMessage::query()->where('data_transfer_id', $batch->id)->count())->toBe(26)
        ->and($second->kind)->toBe(HistoryImportFileKind::Messages)
        ->and($second->rows_total)->toBe(26)
        ->and($second->rows_accepted)->toBe(3)
        ->and($second->issues['duplicate_message']['count'])->toBe(23)
        ->and(historyRow($batch, REAL_TICKET.'1')->message_count)->toBe(15)
        ->and(HistoryImportConversation::query()->where('data_transfer_id', $batch->id)->count())->toBe(7);
});

test('the time zone of the batch never changes which message is which', function () {
    $mexico = realExportBatch($this->admin);

    $this->actingAs($this->admin)->post(route('admin.history-import.store'), ['source_instance' => 'whaticket-prueba', 'timezone' => 'UTC'])->assertSessionHasNoErrors();
    $utc = DataTransfer::query()->latest('id')->firstOrFail();
    historyAddFile($this->admin, $utc, 'messages', historyUpload(file_get_contents(historyFixture('messages-export.csv'))."\n", 'otra-copia.csv'));

    $ids = fn ($batch) => HistoryImportMessage::query()->where('data_transfer_id', $batch->id)->orderBy('line')->pluck('source_message_id')->all();

    expect($ids($utc))->toBe($ids($mexico))
        ->and(HistoryImportMessage::query()->where('data_transfer_id', $utc->id)->min('occurred_at'))->toBe('2024-10-13 01:18:00');
});

test('a file already on the server is added to a draft batch from the command line', function () {
    $batch = historyBatch($this->admin);
    $path = sys_get_temp_dir().'/histcli-'.uniqid().'.csv';
    copy(historyFixture('messages-export.csv'), $path);

    try {
        $this->artisan('history-import:add-file', ['batch' => $batch->id, 'path' => $path, '--kind' => 'messages'])->assertSuccessful();

        $file = HistoryImportFile::query()->where('data_transfer_id', $batch->id)->sole();

        // Same checks, private copy and reading as the upload form; the original stays where it was.
        expect(file_exists($path))->toBeTrue()
            ->and($file->status)->toBe(HistoryImportFileStatus::Parsed)
            ->and($file->rows_accepted)->toBe(23)
            ->and($file->uploaded_by_user_id)->toBe($this->admin->id)
            ->and($file->format_version)->toBe(HistoryFileIngestor::FORMAT_MESSAGES_VERIFIED);

        $this->artisan('history-import:add-file', ['batch' => $batch->id, 'path' => $path, '--kind' => 'messages'])
            ->expectsOutputToContain('Este archivo ya está en esta importación.')
            ->assertFailed();
        $this->artisan('history-import:add-file', ['batch' => $batch->id, 'path' => $path, '--kind' => 'otro'])->assertFailed();
        $this->artisan('history-import:add-file', ['batch' => $batch->id + 1000, 'path' => $path])->assertFailed();
        $this->artisan('history-import:add-file', ['batch' => $batch->id, 'path' => $path.'.no-existe'])->assertFailed();

        config(['history-import.max_cli_kilobytes' => 1]);
        $this->artisan('history-import:add-file', ['batch' => historyBatch($this->admin)->id, 'path' => $path])->assertFailed();
    } finally {
        @unlink($path);
    }

    expect(HistoryImportFile::query()->count())->toBe(1);
});

test('the browser upload limit is the smaller of the configured one and what PHP accepts', function () {
    config(['history-import.max_kilobytes' => 102400]);

    app()->instance(ServerUploadLimits::class, new ServerUploadLimits('2M', '8M'));
    expect(HistoryImportFileRequest::maxKilobytes())->toBe(2048);

    app()->instance(ServerUploadLimits::class, new ServerUploadLimits('1G', '1G'));
    expect(HistoryImportFileRequest::maxKilobytes())->toBe(102400);

    app()->instance(ServerUploadLimits::class, new ServerUploadLimits('100M', '110M'));
    expect(HistoryImportFileRequest::maxKilobytes())->toBe(102400);
});

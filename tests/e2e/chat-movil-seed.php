<?php

/*
| Stress conversation of the mobile chat scenario (tests/e2e/chat-movil.mjs),
| also measured by the responsive matrix of tests/e2e/bandeja.mjs. It sits on
| top of tests/e2e/increment-b-seed.php (already run by tests/e2e/run.php):
| E2E Ana and E2E Ventas, and the "E2E WhatsApp Ventas" connection.
|
| One conversation with everything that once widened the chat on phones:
|   - a PDF whose name has 120 characters (no spaces to break at),
|   - a quote and an internal note with a 90-character word,
|   - a 300-character URL and 40 emojis in a row,
|   - a contact and an author (agent) with very long names,
|   - more than 80 messages, so the history scrolls.
| Messages are written directly (no notifications, no jobs); "live" sends
| one more customer message through the real inbound pipeline.
|
| It only touches the testing database (chatmanager_testing) and refuses any
| other one. SIMULACIÓN: nothing is sent to or received from Meta.
|
|   php tests/e2e/chat-movil-seed.php prepare [open|closed]
|       -> {"conversation", "contact", "author", "wa_id", "pdf", "word", "url"}
|          open (default): assigned to E2E Ana, window open, ready to reply;
|          closed: closed history (does not change the inbox lists).
|   php tests/e2e/chat-movil-seed.php live <wa_id> <text>
|       -> {"conversation", "outcome"} (a customer message, real pipeline)
*/

use App\Actions\Conversations\ProcessInboundMessages;
use App\Enums\ConversationStatus;
use App\Enums\MessageDirection;
use App\Enums\MessageStatus;
use App\Enums\MessageType;
use App\Models\Connection;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\ConversationNote;
use App\Models\Message;
use App\Models\MessageAttachment;
use App\Models\User;
use App\Services\Meta\SimulatedWebhook;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\TestDatabase;

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

try {
    TestDatabase::guard(DB::connection()->getDatabaseName());
} catch (RuntimeException $exception) {
    fwrite(STDERR, $exception->getMessage().PHP_EOL);
    exit(1);
}

const CHAT_MOVIL_PHONE_NUMBER_ID = '880000000000002';

$connection = Connection::query()->where('phone_number_id', CHAT_MOVIL_PHONE_NUMBER_ID)->firstOrFail();
$command = $argv[1] ?? 'prepare';

if ($command === 'live') {
    [, , $waId, $text] = $argv + [null, null, '', 'Hola'];

    // The connection needs its (throwaway) business account for the webhook.
    $receipts = app(ProcessInboundMessages::class)->handle(SimulatedWebhook::message(
        CHAT_MOVIL_PHONE_NUMBER_ID,
        (string) ($connection->business_account_id ?? '990000000000002'),
        $waId,
        'Cliente E2E',
        'wamid.e2e.'.Str::random(16),
        'text',
        $text,
        now()->getTimestamp(),
    ));

    echo json_encode(['conversation' => $receipts[0]->conversation->id, 'outcome' => $receipts[0]->outcome->value]), PHP_EOL;

    exit(0);
}

if ($command !== 'prepare') {
    fwrite(STDERR, "Unknown command [{$command}].".PHP_EOL);
    exit(1);
}

$closed = ($argv[2] ?? 'open') === 'closed';

// Throwaway values only in chatmanager_testing: a connection without stored
// credentials cannot send (Connection::sendingBlockReason).
$connection->forceFill([
    'is_active' => true,
    'business_account_id' => $connection->business_account_id ?? '990000000000002',
    'access_token' => $connection->access_token ?? 'E2E-SIMULATED-'.Str::random(32),
    'app_secret' => $connection->app_secret ?? Str::random(40),
])->save();

$ana = User::query()->where('email', 'e2e-ana@example.test')->firstOrFail();
$department = $ana->departments()->firstOrFail();
$author = User::query()->where('email', 'e2e-autor-largo@example.test')->first()
    ?? User::factory()->agent()->inDepartments($department)->create([
        'name' => 'Maximiliano Bartolomé de la Santísima Trinidad Fernández-Villaseñor',
        'email' => 'e2e-autor-largo@example.test',
        'password' => 'e2e-password',
    ]);

$suffix = $closed ? 'Historial' : 'Activa';
$waId = $closed ? '5215511139901' : '5215511139900';
$contact = Contact::factory()->fromWhatsapp($waId)->create([
    'name' => "Cliente Estrés {$suffix} Con Un Nombre Larguísimo Que No Cabe En La Cabecera Del Chat",
]);

$word = str_repeat('ABCDEFGHIJ', 9);
$pdf = 'Cotizacion_'.str_repeat('Pedido000123456789_', 5).'Sucursal_Poniente_Final.pdf';
$pdf = substr($pdf, 0, 116).'.pdf';
$url = 'https://www.ejemplo-de-dominio-muy-largo.com.mx/seguimiento/pedido/000123456789/?'.str_repeat('parametro=valor-muy-largo-sin-espacios&', 10);
$url = substr($url, 0, 300);
$emojis = str_repeat('😀', 40);

$start = CarbonImmutable::now()->subHours(3);
$conversation = Conversation::factory()
    ->for($connection, 'channelConnection')
    ->for($contact)
    ->inDepartment($department)
    ->assignedTo($ana)
    ->create([
        'last_inbound_at' => now()->subMinutes(5),
        'last_message_at' => now()->subMinutes(5),
        ...($closed ? ['status' => ConversationStatus::Closed, 'closed_at' => now()->subMinute()] : []),
    ]);

$minute = 0;
$message = function (array $attributes) use ($conversation, &$minute, $start): Message {
    $minute++;
    $message = Message::query()->create([
        'conversation_id' => $conversation->id,
        'whatsapp_message_id' => 'wamid.e2e.stress.'.Str::random(16),
        'direction' => MessageDirection::Inbound,
        'type' => MessageType::Text,
        'status' => MessageStatus::Received,
        ...$attributes,
    ]);
    $message->forceFill(['created_at' => $start->addMinutes($minute), 'updated_at' => $start->addMinutes($minute)])->save();

    return $message;
};
$reply = fn (array $attributes): Message => $message([
    'direction' => MessageDirection::Outbound,
    'status' => MessageStatus::Read,
    'sender_user_id' => $author->id,
    ...$attributes,
]);

// More than 80 messages so the history scrolls.
for ($i = 1; $i <= 40; $i++) {
    $message(['body' => "Mensaje del cliente número {$i}: ¿me pueden confirmar el pedido?"]);
    $reply(['body' => "Respuesta número {$i}: con gusto, lo revisamos enseguida."]);
}

$document = $message(['type' => MessageType::Document, 'body' => null]);
MessageAttachment::query()->create([
    'message_id' => $document->id,
    'disk' => 'local',
    'path' => 'e2e/chat-movil.pdf',
    'mime_type' => 'application/pdf',
    'file_name' => $pdf,
    'size_bytes' => 245_760,
]);
$reply(['body' => 'Recibimos tu PDF, gracias.', 'reply_to_message_id' => $document->id]);
$longWord = $message(['body' => $word]);
$reply(['body' => 'Revisamos el código que nos enviaste.', 'reply_to_message_id' => $longWord->id]);
$message(['body' => 'Aquí está el enlace: '.$url]);
$message(['body' => $emojis]);
$reply(['body' => 'Te escribo desde la sucursal con el detalle completo del pedido y la factura.']);

$note = new ConversationNote(['body' => 'Nota interna: '.$word]);
$note->conversation_id = $conversation->id;
$note->user_id = $author->id;
$note->save();

$message(['body' => 'Último mensaje del cliente: '.$word]);

echo json_encode([
    'conversation' => $conversation->id,
    'contact' => $contact->name,
    'author' => $author->name,
    'wa_id' => $waId,
    'pdf' => $pdf,
    'word' => $word,
    'url' => $url,
    'messages' => Message::query()->where('conversation_id', $conversation->id)->count(),
], JSON_UNESCAPED_UNICODE), PHP_EOL;

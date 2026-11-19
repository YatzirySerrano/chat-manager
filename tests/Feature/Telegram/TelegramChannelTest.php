<?php

use App\Enums\AttachmentStatus;
use App\Enums\ConnectionChannel;
use App\Enums\ConnectionHealth;
use App\Enums\ConnectionProvider;
use App\Enums\MessageStatus;
use App\Enums\MessageType;
use App\Jobs\DeliverOutboundMessage;
use App\Models\Connection;
use App\Models\ContactIdentity;
use App\Models\Conversation;
use App\Models\Department;
use App\Models\Message;
use App\Models\User;
use App\Services\Ai\AiAssistant;
use App\Services\Conversations\CustomerServiceWindow;
use App\Services\Telegram\TelegramUpdate;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\Support\WhatsAppFixtures;

/*
| Telegram bots: connection form (getMe + setWebhook), authenticated webhook,
| inbound pipeline (text, media, edits, groups), outbound Bot API calls and
| their failures. Every Telegram call is faked; nothing reaches Telegram.
*/

const TG_TOKEN = '123456789:AAHdqTcvCH1vGWJxfSeofSAs0K5PALDsaw';

/** A 1x1 PNG. */
function telegramPng(): string
{
    return (string) base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==');
}

/**
 * @param  array<string, mixed>  $message
 * @return array<string, mixed>
 */
function telegramUpdate(array $message, int $updateId = 1000, string $key = 'message'): array
{
    return ['update_id' => $updateId, $key => [
        'message_id' => 10,
        'date' => now()->getTimestamp(),
        'chat' => ['id' => 555000111, 'type' => 'private', 'first_name' => 'Ana'],
        'from' => ['id' => 555000111, 'is_bot' => false, 'first_name' => 'Ana', 'last_name' => 'López', 'username' => 'ana'],
        ...$message,
    ]];
}

beforeEach(function () {
    Http::preventStrayRequests();
    Storage::fake('local');
    config(['filesystems.media_disk' => 'local', 'services.telegram.base_url' => 'https://api.telegram.org', 'app.url' => 'https://chat.example.test']);
    $this->connection = Connection::factory()->telegram()->create(['access_token' => TG_TOKEN]);
    $this->post = fn (array $update, ?string $secret = 'telegram-secret-token-value') => $this->call(
        'POST',
        route('webhooks.telegram.receive', ['webhookKey' => $this->connection->webhook_key]),
        server: array_filter(['CONTENT_TYPE' => 'application/json', 'HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN' => $secret]),
        content: (string) json_encode($update),
    );
});

test('the webhook refuses a missing or wrong secret token and unknown keys', function () {
    ($this->post)(telegramUpdate(['text' => 'Hola']), 'wrong')->assertUnauthorized();
    ($this->post)(telegramUpdate(['text' => 'Hola']), null)->assertUnauthorized();
    $this->postJson('/webhooks/telegram/'.str_repeat('a', 48), telegramUpdate(['text' => 'Hola']))->assertNotFound();

    expect(Message::query()->count())->toBe(0);
});

test('a private text message enters the pipeline once with the Telegram identity', function () {
    ($this->post)(telegramUpdate(['text' => 'Hola, ¿tienen envíos?']))->assertOk();
    ($this->post)(telegramUpdate(['text' => 'Hola, ¿tienen envíos?']))->assertOk();

    $message = Message::query()->sole();
    $identity = ContactIdentity::query()->sole();

    expect($message)
        ->whatsapp_message_id->toBe("tg.{$this->connection->id}.555000111.10")
        ->type->toBe(MessageType::Text)
        ->body->toBe('Hola, ¿tienen envíos?')
        ->and($message->metadata['sender_external_id'])->toBe('555000111')
        ->and($identity)->channel->toBe(ConnectionChannel::Telegram)->external_id->toBe('555000111')
        ->and($identity->contact->name)->toBe('Ana López')
        ->and($this->connection->refresh()->health())->toBe(ConnectionHealth::Receiving);
});

test('groups, channels, other bots and button presses are ignored', function () {
    ($this->post)(telegramUpdate(['chat' => ['id' => -100123, 'type' => 'supergroup'], 'text' => 'Hola grupo']))->assertOk();
    ($this->post)(telegramUpdate(['from' => ['id' => 42, 'is_bot' => true, 'first_name' => 'Otro'], 'text' => 'bip'], 1001))->assertOk();
    ($this->post)(['update_id' => 1002, 'callback_query' => ['id' => '1', 'data' => 'x']])->assertOk();

    expect(Message::query()->count())->toBe(0);
});

test('an edited message updates the stored one', function () {
    ($this->post)(telegramUpdate(['text' => 'Quiero 2 cajas']))->assertOk();
    ($this->post)(telegramUpdate(['text' => 'Quiero 3 cajas', 'edit_date' => now()->addMinute()->getTimestamp()], 1001, 'edited_message'))->assertOk();

    $message = Message::query()->sole();

    expect($message->body)->toBe('Quiero 3 cajas')->and($message->edited_at)->not->toBeNull();
});

test('a photo is downloaded from the Bot API into the Telegram folder of the contact', function () {
    Http::fake([
        'api.telegram.org/bot'.TG_TOKEN.'/getFile' => Http::response(['ok' => true, 'result' => ['file_id' => 'big', 'file_path' => 'photos/file_1.jpg', 'file_size' => 68]]),
        'api.telegram.org/file/bot'.TG_TOKEN.'/photos/file_1.jpg' => Http::response(telegramPng()),
    ]);

    ($this->post)(telegramUpdate(['caption' => 'Mi recibo', 'photo' => [
        ['file_id' => 'small', 'file_unique_id' => 's', 'width' => 90, 'height' => 90, 'file_size' => 20],
        ['file_id' => 'big', 'file_unique_id' => 'b', 'width' => 800, 'height' => 800, 'file_size' => 68],
    ]]))->assertOk();

    $message = Message::query()->sole();
    $attachment = $message->attachments()->sole();

    expect($message)->type->toBe(MessageType::Image)->body->toBe('Mi recibo')
        ->and($message->metadata['content'])->not->toHaveKey('descriptor')
        ->and($attachment->status)->toBe(AttachmentStatus::Available)
        ->and($attachment->provider_reference)->toBeNull()
        ->and($attachment->path)->toStartWith('Telegram/');
    Storage::disk('local')->assertExists((string) $attachment->path);
    Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/getFile') && $request['file_id'] === 'big');
});

test('a file over the 20 MB bots may download is kept as unavailable without calling Telegram', function () {
    ($this->post)(telegramUpdate(['document' => ['file_id' => 'huge', 'file_name' => 'video.mp4', 'mime_type' => 'video/mp4', 'file_size' => 30 * 1024 * 1024]]))->assertOk();

    expect(Message::query()->sole()->attachments()->sole())
        ->status->toBe(AttachmentStatus::Unavailable)->error_code->toBe('too_large');
    Http::assertNothingSent();
});

test('a reply is sent with sendMessage as plain text, quoting the original', function () {
    ($this->post)(telegramUpdate(['text' => '¿Precio?']))->assertOk();
    $conversation = Conversation::query()->sole();
    $inbound = Message::query()->sole();
    Http::fake(['api.telegram.org/bot'.TG_TOKEN.'/sendMessage' => Http::response(['ok' => true, 'result' => ['message_id' => 11, 'chat' => ['id' => 555000111]]])]);

    $reply = WhatsAppFixtures::outbound($conversation, ['body' => 'Cuesta *$100*', 'reply_to_message_id' => $inbound->id]);
    app()->call([new DeliverOutboundMessage($reply->id), 'handle']);

    Http::assertSent(fn (Request $request): bool => $request['chat_id'] === '555000111'
        && $request['text'] === 'Cuesta *$100*'
        && ! isset($request['parse_mode'])
        && $request['reply_parameters']['message_id'] === 10);
    expect($reply->refresh())->status->toBe(MessageStatus::Accepted)
        ->whatsapp_message_id->toBe("tg.{$this->connection->id}.555000111.11");
});

test('a blocked bot fails the message with the reason', function () {
    ($this->post)(telegramUpdate(['text' => 'Hola']))->assertOk();
    $conversation = Conversation::query()->sole();

    Http::fake(['api.telegram.org/*' => Http::response(['ok' => false, 'error_code' => 403, 'description' => 'Forbidden: bot was blocked by the user'], 403)]);
    $blocked = WhatsAppFixtures::outbound($conversation);
    app()->call([new DeliverOutboundMessage($blocked->id), 'handle']);

    expect($blocked->refresh())->status->toBe(MessageStatus::Failed)
        ->error_message->toContain('bloqueó al bot');
});

test('Telegram has no customer service window and the AI assistant may answer it', function () {
    $conversation = Conversation::factory()->create(['connection_id' => $this->connection->id, 'last_inbound_at' => now()->subDays(5)]);

    expect(app(CustomerServiceWindow::class)->for($conversation)->allowsFreeFormMessages())->toBeTrue()
        ->and(AiAssistant::supports($this->connection))->toBeTrue()
        ->and($this->connection->usesMetaApi())->toBeFalse();
});

test('creating a Telegram connection validates the token with getMe and registers the webhook', function () {
    Http::fake([
        'api.telegram.org/bot'.TG_TOKEN.'/getMe' => Http::response(['ok' => true, 'result' => ['id' => 987654321, 'is_bot' => true, 'first_name' => 'Ventas', 'username' => 'ventas_bot']]),
        'api.telegram.org/bot'.TG_TOKEN.'/setWebhook' => Http::response(['ok' => true, 'result' => true]),
    ]);
    $this->connection->forceFill(['access_token' => null, 'external_account_id' => null])->save();
    $admin = User::factory()->superAdministrator()->create();

    $this->actingAs($admin)->post(route('connections.store'), [
        'channel' => 'telegram',
        'name' => 'Bot de ventas',
        'bot_token' => TG_TOKEN,
        'department_ids' => [Department::factory()->create()->id],
        'welcome_enabled' => false,
        'away_enabled' => false,
        'farewell_enabled' => false,
    ])->assertRedirect();

    $created = Connection::query()->where('name', 'Bot de ventas')->sole();

    expect($created)
        ->provider->toBe(ConnectionProvider::TelegramBot)
        ->access_token->toBe(TG_TOKEN)
        ->external_account_id->toBe('987654321')
        ->external_account_name->toBe('ventas_bot')
        ->is_active->toBeFalse()
        ->and($created->webhook_verify_token)->not->toBeEmpty()
        ->and($created->webhook_verified_at)->not->toBeNull();
    Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/setWebhook')
        && $request['url'] === 'https://chat.example.test/webhooks/telegram/'.$created->webhook_key
        && $request['secret_token'] === $created->webhook_verify_token
        && $request['allowed_updates'] === ['message', 'edited_message', 'callback_query']);
});

test('a token Telegram rejects is refused by the form', function () {
    Http::fake(['api.telegram.org/*' => Http::response(['ok' => false, 'error_code' => 401, 'description' => 'Unauthorized'], 401)]);
    $admin = User::factory()->superAdministrator()->create();

    $this->actingAs($admin)->post(route('connections.store'), [
        'channel' => 'telegram',
        'name' => 'Bot',
        'bot_token' => '111111111:AAHdqTcvCH1vGWJxfSeofSAs0K5PALDsaw',
        'department_ids' => [],
    ])->assertSessionHasErrors('bot_token');

    expect(Connection::query()->where('name', 'Bot')->exists())->toBeFalse();
});

test('deactivating the connection removes the webhook', function () {
    Http::fake(['api.telegram.org/bot'.TG_TOKEN.'/deleteWebhook' => Http::response(['ok' => true, 'result' => true])]);
    $admin = User::factory()->superAdministrator()->create();

    $this->actingAs($admin)->patch(route('connections.status.update', $this->connection), ['is_active' => false])->assertRedirect();

    Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/deleteWebhook'));
    expect($this->connection->refresh()->webhook_verified_at)->toBeNull();
});

test('stored ids are scoped to the connection and the chat', function () {
    $id = TelegramUpdate::storedId(7, '555', 10);

    expect(TelegramUpdate::parseStoredId(7, $id))->toBe(['chat_id' => '555', 'message_id' => 10])
        ->and(TelegramUpdate::parseStoredId(8, $id))->toBeNull()
        ->and(TelegramUpdate::parseStoredId(7, 'wamid.x'))->toBeNull();
});

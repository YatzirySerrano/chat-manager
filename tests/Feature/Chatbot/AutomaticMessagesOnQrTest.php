<?php

use App\Actions\Conversations\CloseConversation;
use App\Actions\Conversations\CloseInactiveConversations;
use App\Enums\MessageStatus;
use App\Models\ChatbotMenu;
use App\Models\Connection;
use App\Models\Conversation;
use App\Models\Department;
use App\Models\Message;
use App\Models\User;
use App\Services\PlatformSettings;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Support\WhatsAppWebFixtures;

/*
| Automatic messages through a phone linked by QR (WhatsApp Web): welcome,
| away and farewell when the administrator turns them on, the chatbot menu,
| and the farewell when an agent resolves the conversation. QR has no 24
| hour window; a close by inactivity days later says no goodbye.
*/

function qrAutomatic(Conversation $conversation): array
{
    return Message::query()
        ->where('conversation_id', $conversation->id)
        ->where('metadata->automatic', true)
        ->orderBy('id')
        ->get()
        ->map(fn (Message $message): array => [$message->metadata['automatic_kind'], $message->body])
        ->all();
}

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-19 10:00', 'America/Mexico_City'));
    app(PlatformSettings::class)->update(['general' => ['timezone' => 'America/Mexico_City'], 'business_hours' => ['enabled' => true]]);

    WhatsAppWebFixtures::configure();
    $this->department = Department::factory()->create(['name' => 'Mesa de control']);
    $this->connection = Connection::factory()->whatsAppWeb()->create([
        'name' => 'Solicitudes QR',
        'default_department_id' => $this->department->id,
        'welcome_enabled' => true,
        'welcome_message' => '¡Hola {{nombre}}! En breve te atendemos.',
        'farewell_enabled' => true,
        'farewell_message' => "👉 Cerraremos el chat\nSi necesitas hacer una nueva solicitud recuerda que el horario de atención es de 9:00 a 16:00 hrs, de Lunes a Viernes.",
    ]);

    Http::fake([WhatsAppWebFixtures::SERVICE.'/*' => fn () => Http::response(['provider_message_id' => '3EB0'.strtoupper(Str::random(12)), 'timestamp' => now()->getTimestamp()])]);
});

test('a new conversation by QR gets the welcome, sent through the linked phone', function () {
    WhatsAppWebFixtures::event($this, $this->connection, 'message.inbound', WhatsAppWebFixtures::inbound(['push_name' => 'Gestor Uno']))->assertOk();

    $conversation = Conversation::query()->sole();
    $welcome = Message::query()->where('metadata->automatic_kind', 'welcome')->sole();

    expect($welcome->body)->toBe('¡Hola Gestor Uno! En breve te atendemos.')
        ->and($welcome->status)->toBe(MessageStatus::Accepted)
        ->and($conversation->first_response_at)->toBeNull();

    Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), "/v1/sessions/{$this->connection->id}/messages")
        && $request['text'] === '¡Hola Gestor Uno! En breve te atendemos.');
});

test('resolving the conversation («Solicitud completada» included) sends the farewell once, days after the last message too', function () {
    WhatsAppWebFixtures::event($this, $this->connection, 'message.inbound', WhatsAppWebFixtures::inbound())->assertOk();
    $conversation = Conversation::query()->sole();
    $this->travel(3)->days();

    app(CloseConversation::class)->handle($conversation, User::factory()->administrator()->create(), completed: true);

    expect(qrAutomatic($conversation))->toBe([
        ['welcome', '¡Hola Gestor Uno! En breve te atendemos.'],
        ['farewell', "👉 Cerraremos el chat\nSi necesitas hacer una nueva solicitud recuerda que el horario de atención es de 9:00 a 16:00 hrs, de Lunes a Viernes."],
    ]);
});

test('a close by inactivity says goodbye by QR only when the customer wrote in the last 24 hours', function () {
    app(PlatformSettings::class)->update(['operations' => ['auto_close_after_hours' => 2]]);

    WhatsAppWebFixtures::event($this, $this->connection, 'message.inbound', WhatsAppWebFixtures::inbound(['phone' => '5215500000001', 'remote_jid' => '5215500000001@s.whatsapp.net']))->assertOk();
    $recent = Conversation::query()->latest('id')->firstOrFail();

    $this->travel(3)->hours();
    app(CloseInactiveConversations::class)->handle();

    WhatsAppWebFixtures::event($this, $this->connection, 'message.inbound', WhatsAppWebFixtures::inbound(['phone' => '5215500000002', 'remote_jid' => '5215500000002@s.whatsapp.net']))->assertOk();
    $stale = Conversation::query()->latest('id')->firstOrFail();

    $this->travel(2)->days();
    app(CloseInactiveConversations::class)->handle();

    expect(collect(qrAutomatic($recent))->pluck(0)->all())->toBe(['welcome', 'farewell'])
        ->and(collect(qrAutomatic($stale))->pluck(0)->all())->toBe(['welcome']);
});

test('the chatbot menu works by QR as well', function () {
    $orizaba = Department::factory()->create(['name' => 'Orizaba']);
    $menu = new ChatbotMenu(['is_enabled' => true, 'greeting' => '¡Hola {{name}}!']);
    $menu->forceFill(['connection_id' => $this->connection->id])->save();
    $menu->options()->create(['department_id' => $this->department->id, 'position' => 0]);
    $menu->options()->create(['department_id' => $orizaba->id, 'position' => 1, 'reply_message' => 'Te atiende Orizaba.']);

    WhatsAppWebFixtures::event($this, $this->connection, 'message.inbound', WhatsAppWebFixtures::inbound())->assertOk();
    WhatsAppWebFixtures::event($this, $this->connection, 'message.inbound', WhatsAppWebFixtures::inbound(['text' => '2']))->assertOk();

    $conversation = Conversation::query()->sole();

    expect($conversation->department_id)->toBe($orizaba->id)
        ->and(qrAutomatic($conversation))->toBe([
            ['chatbot_menu', "¡Hola Gestor Uno!\n\n1 - Mesa de control\n2 - Orizaba"],
            ['chatbot_reply', 'Te atiende Orizaba.'],
        ]);
});

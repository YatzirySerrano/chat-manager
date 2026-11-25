<?php

use App\Actions\Conversations\AcceptConversation;
use App\Actions\Conversations\CloseConversation;
use App\Actions\Conversations\ProcessInboundMessages;
use App\Enums\ChatbotSessionStatus;
use App\Enums\ConversationEventType;
use App\Enums\ConversationStatus;
use App\Enums\DistributionMode;
use App\Events\Conversations\ConversationChanged;
use App\Http\Resources\ConversationEventResource;
use App\Models\ChatbotMenu;
use App\Models\ChatbotSession;
use App\Models\Connection;
use App\Models\Conversation;
use App\Models\Department;
use App\Models\Message;
use App\Models\User;
use App\Notifications\NotificationKind;
use App\Services\PlatformSettings;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Notifications\DatabaseNotification;

/*
| The chatbot's welcome menu: a new conversation gets the greeting and the
| numbered options, waits undistributed and unannounced, and the customer's
| answer sends it to the chosen department (or the ordinary routing takes
| over after the allowed retries). Inbound traffic goes through the real
| pipeline.
*/

/**
 * @return list<string|null>
 */
function chatbotKinds(Conversation $conversation): array
{
    return Message::query()
        ->where('conversation_id', $conversation->id)
        ->where('metadata->automatic', true)
        ->orderBy('id')
        ->get()
        ->map(fn (Message $message): ?string => $message->metadata['automatic_kind'] ?? null)
        ->all();
}

function chatbotLast(Conversation $conversation): Message
{
    return Message::query()->where('conversation_id', $conversation->id)->where('metadata->automatic', true)->latest('id')->firstOrFail();
}

beforeEach(function () {
    // Monday 2026-10-19 at 16:00 in Mexico City, within the default hours.
    $this->travelTo(CarbonImmutable::parse('2026-10-19 16:00', 'America/Mexico_City'));

    app(PlatformSettings::class)->update([
        'general' => ['timezone' => 'America/Mexico_City'],
        'business_hours' => ['enabled' => true],
    ]);

    $this->reception = Department::factory()->create(['name' => 'Recepción']);
    $this->control = Department::factory()->distribution(DistributionMode::Automatic)->create(['name' => '🧑‍💻 Mesa de control']);
    $this->orizaba = Department::factory()->create(['name' => 'Atención de solicitudes Orizaba', 'welcome_message' => 'Bienvenido a Orizaba, {{nombre}}.']);

    $this->connection = Connection::factory()->create([
        'name' => 'Solicitudes MC',
        'default_department_id' => $this->reception->id,
        'welcome_enabled' => true,
        'welcome_message' => 'Bienvenida de la conexión.',
        'away_enabled' => true,
        'away_message' => 'Estamos fuera de horario.',
    ]);

    $this->menu = new ChatbotMenu(['is_enabled' => true, 'greeting' => "¡Hola {{userName}}! 👋\n{{saludo}}, elige una opción:", 'max_attempts' => 2]);
    $this->menu->forceFill(['connection_id' => $this->connection->id])->save();
    $this->menu->options()->create(['department_id' => $this->control->id, 'position' => 0, 'reply_message' => 'Te atiende Mesa de control, {{nombre}}.']);
    $this->menu->options()->create(['department_id' => $this->orizaba->id, 'position' => 1]);
});

test('a new conversation gets the greeting with its variables and the numbered options, and waits undistributed and unannounced', function () {
    $agent = User::factory()->agent()->inDepartments([$this->control, $this->reception])->create();

    $receipt = receiveWhatsapp($this->connection, '5215512345678', 'Hola');
    $conversation = $receipt->conversation->refresh();

    expect(chatbotKinds($conversation))->toBe(['chatbot_menu'])
        ->and(chatbotLast($conversation)->body)->toBe("¡Hola Laura Pérez! 👋\nBuenas tardes, elige una opción:\n\n1 - 🧑‍💻 Mesa de control\n2 - Atención de solicitudes Orizaba")
        ->and(chatbotLast($conversation)->sender_user_id)->toBeNull()
        ->and($conversation->department_id)->toBe($this->reception->id)
        ->and($conversation->status)->toBe(ConversationStatus::Pending)
        ->and($conversation->assigned_user_id)->toBeNull()
        ->and(ChatbotSession::query()->sole()->status)->toBe(ChatbotSessionStatus::Awaiting)
        // Nobody is told yet: the choice will announce it.
        ->and(DatabaseNotification::query()->where('notifiable_id', $agent->id)->count())->toBe(0);
});

test('answering with the number routes to the department, records the event, replies and lets it be distributed and announced', function () {
    $agent = User::factory()->agent()->inDepartments([$this->control])->create();
    $receipt = receiveWhatsapp($this->connection, '5215512345678', 'Hola');

    receiveWhatsapp($this->connection, '5215512345678', '1');
    $conversation = $receipt->conversation->refresh();
    $event = $conversation->events()->where('type', ConversationEventType::ChatbotRouted)->sole();

    expect($conversation->department_id)->toBe($this->control->id)
        // Automatic department: distributed right after the choice.
        ->and($conversation->assigned_user_id)->toBe($agent->id)
        ->and($event->from_department_id)->toBe($this->reception->id)
        ->and($event->to_department_id)->toBe($this->control->id)
        ->and($event->actor_user_id)->toBeNull()
        ->and((new ConversationEventResource($event->load('toDepartment', 'fromDepartment')))->toArray(Request::create('/'))['label'])->toBe('El chatbot la envió a 🧑‍💻 Mesa de control')
        ->and(chatbotKinds($conversation))->toBe(['chatbot_menu', 'chatbot_reply'])
        ->and(chatbotLast($conversation)->body)->toBe('Te atiende Mesa de control, Laura Pérez.')
        ->and(ChatbotSession::query()->sole())
        ->status->toBe(ChatbotSessionStatus::Routed)
        ->department_id->toBe($this->control->id);
});

test('the option text works too, ignoring case and accents, and the department welcome is the reply when the option has none', function () {
    $acceptor = User::factory()->agent()->inDepartments([$this->orizaba])->create();
    $receipt = receiveWhatsapp($this->connection, '5215512345678', 'Hola');

    receiveWhatsapp($this->connection, '5215512345678', 'ORIZABA');
    $conversation = $receipt->conversation->refresh();

    expect($conversation->department_id)->toBe($this->orizaba->id)
        ->and($conversation->assigned_user_id)->toBeNull()
        ->and(chatbotLast($conversation)->body)->toBe('Bienvenido a Orizaba, Laura Pérez.')
        // The destination's people are told now that it entered their queue.
        ->and(DatabaseNotification::query()->where('notifiable_id', $acceptor->id)->sole()->kind)->toBe(NotificationKind::ConversationPending->value);
});

test('an answer that is not an option gets the short retry with the options, and after the allowed retries the ordinary routing takes over', function () {
    $acceptor = User::factory()->agent()->inDepartments([$this->reception])->create();
    $receipt = receiveWhatsapp($this->connection, '5215512345678', 'Hola');

    receiveWhatsapp($this->connection, '5215512345678', 'quiero hablar con alguien');
    $conversation = $receipt->conversation->refresh();

    expect(chatbotKinds($conversation))->toBe(['chatbot_menu', 'chatbot_retry'])
        ->and(chatbotLast($conversation)->body)->toBe("Responde con el número de una opción.\n\n1 - 🧑‍💻 Mesa de control\n2 - Atención de solicitudes Orizaba")
        ->and(DatabaseNotification::query()->where('notifiable_id', $acceptor->id)->count())->toBe(0);

    receiveWhatsapp($this->connection, '5215512345678', '7');
    receiveWhatsapp($this->connection, '5215512345678', '¿hola?');
    $conversation->refresh();
    $event = $conversation->events()->where('type', ConversationEventType::ChatbotRouted)->sole();

    expect(chatbotKinds($conversation))->toBe(['chatbot_menu', 'chatbot_retry', 'chatbot_retry'])
        ->and($conversation->department_id)->toBe($this->reception->id)
        ->and($event->metadata)->toMatchArray(['outcome' => 'fallback', 'reason' => 'no_valid_option'])
        ->and((new ConversationEventResource($event))->toArray(Request::create('/'))['label'])->toBe('El chatbot no recibió una opción válida')
        ->and(ChatbotSession::query()->sole()->status)->toBe(ChatbotSessionStatus::Fallback)
        ->and(DatabaseNotification::query()->where('notifiable_id', $acceptor->id)->sole()->kind)->toBe(NotificationKind::ConversationPending->value);

    // Later messages are ordinary customer messages.
    receiveWhatsapp($this->connection, '5215512345678', '1');

    expect($conversation->refresh()->department_id)->toBe($this->reception->id)
        ->and(chatbotKinds($conversation))->toHaveCount(3);
});

test('a contact with a department of their own skips the menu and gets the usual welcome', function () {
    // A known contact (first conversation already closed) with a department chosen by hand.
    $this->menu->forceFill(['is_enabled' => false])->save();
    $first = receiveWhatsapp($this->connection, '5215599998888', 'Hola');
    $first->conversation->forceFill(['status' => ConversationStatus::Closed, 'closed_at' => now()->subHour()])->save();
    $first->registration->contact->forceFill(['department_id' => $this->orizaba->id])->save();
    $this->menu->forceFill(['is_enabled' => true])->save();

    $receipt = receiveWhatsapp($this->connection, '5215599998888', 'Hola de nuevo');

    expect($receipt->conversation->id)->not->toBe($first->conversation->id)
        ->and($receipt->conversation->department_id)->toBe($this->orizaba->id)
        ->and(ChatbotSession::query()->count())->toBe(0)
        ->and(chatbotKinds($receipt->conversation))->toBe(['welcome']);
});

test('an option whose department is inactive is skipped and the rest are renumbered', function () {
    $this->control->forceFill(['is_active' => false])->save();

    $receipt = receiveWhatsapp($this->connection, '5215512345678', 'Hola');

    expect(chatbotLast($receipt->conversation)->body)->toEndWith("\n\n1 - Atención de solicitudes Orizaba");

    receiveWhatsapp($this->connection, '5215512345678', '1');

    expect($receipt->conversation->refresh()->department_id)->toBe($this->orizaba->id);
});

test('without a usable option, or with the menu off, the conversation gets the usual welcome and distribution', function () {
    $this->menu->forceFill(['is_enabled' => false])->save();

    $receipt = receiveWhatsapp($this->connection, '5215512345678', 'Hola');

    expect(chatbotKinds($receipt->conversation))->toBe(['welcome'])
        ->and(ChatbotSession::query()->count())->toBe(0);
});

test('outside business hours the away message goes first and then the menu', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-19 21:00', 'America/Mexico_City'));

    $receipt = receiveWhatsapp($this->connection, '5215512345678', 'Hola');

    expect(chatbotKinds($receipt->conversation))->toBe(['away', 'chatbot_menu'])
        ->and(chatbotLast($receipt->conversation)->body)->toStartWith("¡Hola Laura Pérez! 👋\nBuenas noches");
});

test('repeated webhooks and re-dispatched events never send the menu twice nor open a second session', function () {
    $payload = whatsappWebhook($this->connection, [whatsappTextMessage('5215512345678', 'Hola', id: 'wamid.same')], ['5215512345678' => 'Laura Pérez']);

    app(ProcessInboundMessages::class)->handle($payload);
    app(ProcessInboundMessages::class)->handle($payload);

    $conversation = Conversation::query()->sole();
    $created = $conversation->events()->where('type', ConversationEventType::Created)->sole();
    ConversationChanged::dispatch($conversation->id, ConversationEventType::Created->value, null, $created->id);

    expect(chatbotKinds($conversation))->toBe(['chatbot_menu'])
        ->and(ChatbotSession::query()->count())->toBe(1);
});

test('a person who takes the conversation before the choice ends the menu', function () {
    $agent = User::factory()->agent()->inDepartments([$this->reception])->create();
    $receipt = receiveWhatsapp($this->connection, '5215512345678', 'Hola');

    app(AcceptConversation::class)->handle($receipt->conversation->refresh(), $agent);
    receiveWhatsapp($this->connection, '5215512345678', '2');
    $conversation = $receipt->conversation->refresh();

    expect($conversation->department_id)->toBe($this->reception->id)
        ->and($conversation->assigned_user_id)->toBe($agent->id)
        ->and(ChatbotSession::query()->sole()->status)->toBe(ChatbotSessionStatus::Cancelled)
        ->and(chatbotKinds($conversation))->toBe(['chatbot_menu'])
        ->and($conversation->events()->where('type', ConversationEventType::ChatbotRouted)->count())->toBe(0);
});

test('a conversation reopened by the customer never gets the menu again', function () {
    $this->connection->forceFill(['reopen_window_minutes' => 120])->save();
    $agent = User::factory()->administrator()->create();
    $receipt = receiveWhatsapp($this->connection, '5215512345678', 'Hola');
    receiveWhatsapp($this->connection, '5215512345678', '2');

    app(CloseConversation::class)->handle($receipt->conversation->refresh(), $agent);
    $this->travel(5)->minutes();
    $reopened = receiveWhatsapp($this->connection, '5215512345678', 'Una cosa más');

    expect($reopened->conversation->id)->toBe($receipt->conversation->id)
        ->and(ChatbotSession::query()->count())->toBe(1)
        // The reopening greets as usual (Orizaba's welcome), never with the menu.
        ->and(chatbotKinds($reopened->conversation))->toBe(['chatbot_menu', 'chatbot_reply', 'welcome']);
});

test('a chatbot without options greets every cycle the customer starts, reopened conversations included', function () {
    $this->menu->allOptions()->delete();
    $this->menu->forceFill(['greeting' => 'Hemos recibido tu solicitud, {{userName}}.'])->save();
    $this->connection->forceFill(['reopen_window_minutes' => 120])->save();
    $agent = User::factory()->administrator()->create();

    $receipt = receiveWhatsapp($this->connection, '5215512345678', 'Hola');

    expect(chatbotKinds($receipt->conversation))->toBe(['chatbot_menu'])
        ->and(chatbotLast($receipt->conversation)->body)->toBe('Hemos recibido tu solicitud, Laura Pérez.')
        ->and(ChatbotSession::query()->count())->toBe(0);

    app(CloseConversation::class)->handle($receipt->conversation->refresh(), $agent);
    $this->travel(5)->minutes();
    $reopened = receiveWhatsapp($this->connection, '5215512345678', 'Otra solicitud');

    expect($reopened->conversation->id)->toBe($receipt->conversation->id)
        ->and($reopened->conversation->refresh()->reopen_count)->toBe(1)
        ->and(chatbotKinds($reopened->conversation))->toBe(['chatbot_menu', 'chatbot_menu']);

    // More messages in the same cycle are not greeted again.
    receiveWhatsapp($this->connection, '5215512345678', 'Te mando los papeles');

    expect(chatbotKinds($reopened->conversation))->toBe(['chatbot_menu', 'chatbot_menu']);
});

test('a chatbot without options greets outside business hours too: after the away message, or alone when there is none', function () {
    $this->menu->allOptions()->delete();
    $this->travelTo(CarbonImmutable::parse('2026-10-19 21:00', 'America/Mexico_City'));

    $withAway = receiveWhatsapp($this->connection, '5215512345678', 'Hola');

    $this->connection->forceFill(['away_enabled' => false])->save();
    $withoutAway = receiveWhatsapp($this->connection, '5215587654321', 'Hola');

    expect(chatbotKinds($withAway->conversation))->toBe(['away', 'chatbot_menu'])
        ->and(chatbotKinds($withoutAway->conversation))->toBe(['chatbot_menu']);
});

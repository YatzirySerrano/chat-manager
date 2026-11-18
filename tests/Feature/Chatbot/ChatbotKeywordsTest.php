<?php

use App\Actions\Conversations\AcceptConversation;
use App\Models\ChatbotKeywordRule;
use App\Models\ChatbotMenu;
use App\Models\Connection;
use App\Models\Conversation;
use App\Models\Department;
use App\Models\Message;
use App\Models\User;
use App\Notifications\NotificationKind;
use App\Notifications\NotificationPresenter;
use App\Services\Chatbot\ChatbotKeywords;
use App\Services\PlatformSettings;
use Carbon\CarbonImmutable;
use Illuminate\Notifications\DatabaseNotification;

/*
| «Chatbot · Palabras clave»: a customer message with a keyword is answered
| automatically (as an automatic message) and the collaborators are told,
| once per rule and conversation within its waiting time.
*/

/**
 * @return list<string>
 */
function keywordReplies(Conversation $conversation): array
{
    return Message::query()
        ->where('conversation_id', $conversation->id)
        ->where('metadata->automatic_kind', 'keyword_reply')
        ->orderBy('id')
        ->pluck('body')
        ->all();
}

/**
 * @param  array<string, mixed>  $attributes
 * @param  list<int>  $connections
 * @param  list<int>  $departments
 */
function keywordRule(array $attributes = [], array $connections = [], array $departments = []): ChatbotKeywordRule
{
    $rule = ChatbotKeywordRule::query()->create([
        'name' => 'Horario',
        'keywords' => ['horario', 'horario de atención', 'a qué hora'],
        'reply_message' => '{{saludo}}, {{nombre}}. Nuestro horario es de 9:00 a 16:00 hrs.',
        ...$attributes,
    ]);
    $rule->connections()->sync($connections);
    $rule->departments()->sync($departments);

    return $rule;
}

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-19 10:00', 'America/Mexico_City'));
    app(PlatformSettings::class)->update(['general' => ['timezone' => 'America/Mexico_City']]);

    $this->department = Department::factory()->create();
    $this->connection = Connection::factory()->create(['default_department_id' => $this->department->id]);
});

test('keywords match whole words ignoring case and accents, never inside another word', function () {
    $keywords = app(ChatbotKeywords::class);
    $rule = ['horario', 'horario de atención', 'a qué hora'];

    expect($keywords->match($rule, '¿Cuál es su HORARIO?'))->toBe('horario')
        ->and($keywords->match($rule, 'el horario de atencion por favor'))->toBe('horario')
        ->and($keywords->match($rule, 'A QUE HORA abren'))->toBe('a qué hora')
        ->and($keywords->match($rule, 'sus horarios'))->toBeNull()
        ->and($keywords->match($rule, 'ahora no'))->toBeNull()
        ->and(ChatbotKeywords::parse(' Horario, horario ,, Precio;  envío '))->toBe(['Horario', 'Precio', 'envío']);
});

test('a matching message is answered automatically with the variables and the attending agent is told', function () {
    $rule = keywordRule();
    $agent = User::factory()->agent()->inDepartments($this->department)->create();
    $receipt = receiveWhatsapp($this->connection, '5215512345678', 'Hola');
    app(AcceptConversation::class)->handle($receipt->conversation->refresh(), $agent);

    receiveWhatsapp($this->connection, '5215512345678', '¿Cuál es su horario?');

    $reply = Message::query()->where('metadata->automatic_kind', 'keyword_reply')->sole();
    $notification = DatabaseNotification::query()->where('notifiable_id', $agent->id)->where('kind', NotificationKind::ChatbotReply->value)->sole();
    $presented = app(NotificationPresenter::class)->present($notification, $agent);

    expect($reply)
        ->body->toBe('Buenos días, Laura Pérez. Nuestro horario es de 9:00 a 16:00 hrs.')
        ->sender_user_id->toBeNull()
        ->and($reply->metadata)->toMatchArray(['automatic' => true, 'automatic_source' => 'keyword', 'chatbot_rule_id' => $rule->id, 'keyword' => 'horario'])
        ->and($presented['title'])->toBe('El chatbot respondió a Laura Pérez')
        ->and($presented['body'])->toBe('Respuesta automática por la palabra clave «Horario».')
        ->and($presented['toast'])->toBeTrue();
});

test('without an agent, the people who can take it from its queue are told', function () {
    keywordRule();
    $acceptor = User::factory()->agent()->inDepartments($this->department)->create();

    receiveWhatsapp($this->connection, '5215512345678', 'Buenas, ¿horario?');

    expect(DatabaseNotification::query()->where('notifiable_id', $acceptor->id)->where('kind', NotificationKind::ChatbotReply->value)->count())->toBe(1);
});

test('the same rule answers the same conversation once per waiting time, and again after it', function () {
    keywordRule(['throttle_minutes' => 30]);

    $receipt = receiveWhatsapp($this->connection, '5215512345678', 'horario');
    receiveWhatsapp($this->connection, '5215512345678', 'horario?? horario!!');

    expect(keywordReplies($receipt->conversation))->toHaveCount(1);

    $this->travel(31)->minutes();
    receiveWhatsapp($this->connection, '5215512345678', 'de nuevo: horario');

    expect(keywordReplies($receipt->conversation))->toHaveCount(2);
});

test('rules apply only to their connections and departments, inactive ones never, and the first matching rule wins', function () {
    $other = Connection::factory()->create();
    keywordRule(['name' => 'Solo otra conexión', 'reply_message' => 'Otra conexión'], connections: [$other->id]);
    keywordRule(['name' => 'Otro departamento', 'reply_message' => 'Otro departamento'], departments: [Department::factory()->create()->id]);
    keywordRule(['name' => 'Inactiva', 'reply_message' => 'Inactiva', 'is_active' => false]);
    keywordRule(['name' => 'Primera', 'reply_message' => 'Primera', 'position' => 1], connections: [$this->connection->id], departments: [$this->department->id]);
    keywordRule(['name' => 'Segunda', 'reply_message' => 'Segunda', 'position' => 2]);

    $receipt = receiveWhatsapp($this->connection, '5215512345678', 'horario');

    expect(keywordReplies($receipt->conversation))->toBe(['Primera']);
});

test('a message without the keyword, or answering the welcome menu, gets no keyword reply', function () {
    keywordRule(['keywords' => ['horario', '1']]);
    $menu = new ChatbotMenu(['is_enabled' => true, 'greeting' => 'Elige:']);
    $menu->forceFill(['connection_id' => $this->connection->id])->save();
    $menu->options()->create(['department_id' => $this->department->id, 'position' => 0]);

    // The first message opens the menu; «1» answers it; neither is a keyword reply.
    $receipt = receiveWhatsapp($this->connection, '5215512345678', '¿horario?');
    receiveWhatsapp($this->connection, '5215512345678', '1');
    receiveWhatsapp($this->connection, '5215512345678', 'gracias');

    expect(keywordReplies($receipt->conversation))->toBe([]);

    receiveWhatsapp($this->connection, '5215512345678', 'y el horario?');

    expect(keywordReplies($receipt->conversation))->toHaveCount(1);
});

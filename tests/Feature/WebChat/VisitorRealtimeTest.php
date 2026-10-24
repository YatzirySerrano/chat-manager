<?php

use App\Events\WebChat\VisitorSessionSignal;
use App\Models\Connection;
use App\Models\Conversation;
use App\Models\Department;
use App\Models\Message;
use App\Models\User;
use App\Models\WebChatSession;
use App\Services\WebChat\VisitorCursor;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Tests\Feature\Realtime\RealtimeTestSupport;
use Tests\Support\WebChatFixtures;
use Tests\Support\WebChatVisitor;
use Tests\Support\WhatsAppFixtures;

/*
| Real-time for web chat visitors: a private channel per session, signed
| only by the visitor API for the exact channel of the bearer's session
| (never by the agents' /broadcasting/auth), and signals that only say
| "refetch", never carry content.
*/

beforeEach(function () {
    RealtimeTestSupport::useReverb();
    $this->department = Department::factory()->create();
    $this->agent = User::factory()->agent()->available()->inDepartments($this->department)->create();
    $this->connection = WebChatFixtures::connection(connection: ['default_department_id' => $this->department->id]);
    ['session' => $this->session, 'token' => $this->token] = WebChatFixtures::session($this->connection);
});

test('the session exposes its private channel and the visitor auth endpoint', function () {
    $this->withToken($this->token)->getJson(route('api.v1.widget.session.show'))
        ->assertOk()
        ->assertJsonPath('data.realtime.enabled', true)
        ->assertJsonPath('data.realtime.key', RealtimeTestSupport::KEY)
        ->assertJsonPath('data.realtime.channel', 'private-webchat.sessions.'.$this->session->public_id)
        ->assertJsonPath('data.realtime.auth_endpoint', route('api.v1.widget.broadcasting.auth'))
        ->assertJsonMissingPath('data.realtime.secret');
});

test('the visitor auth endpoint signs only the channel of the session', function () {
    $channel = 'private-webchat.sessions.'.$this->session->public_id;

    $this->withToken($this->token)
        ->post(route('api.v1.widget.broadcasting.auth'), ['socket_id' => '1234.5678', 'channel_name' => $channel])
        ->assertOk()
        ->assertExactJson(['auth' => RealtimeTestSupport::KEY.':'.hash_hmac('sha256', '1234.5678:'.$channel, RealtimeTestSupport::SECRET)]);
});

test('any other channel is refused by the visitor auth endpoint', function (Closure $channel) {
    $this->withToken($this->token)
        ->postJson(route('api.v1.widget.broadcasting.auth'), ['socket_id' => '1234.5678', 'channel_name' => $channel($this)])
        ->assertForbidden();
})->with([
    'agent conversation channel' => [fn () => 'private-conversations.1'],
    'another session' => [fn ($test) => 'private-webchat.sessions.'.WebChatFixtures::session($test->connection)['session']->public_id],
    'without private prefix' => [fn ($test) => 'webchat.sessions.'.$test->session->public_id],
    'presence channel' => [fn ($test) => 'presence-webchat.sessions.'.$test->session->public_id],
]);

test('a malformed socket id or a missing visitor token is refused', function () {
    $this->withToken($this->token)
        ->postJson(route('api.v1.widget.broadcasting.auth'), ['socket_id' => 'abc', 'channel_name' => 'private-webchat.sessions.'.$this->session->public_id])
        ->assertUnprocessable();

    $this->withoutToken()
        ->postJson(route('api.v1.widget.broadcasting.auth'), ['socket_id' => '1.2', 'channel_name' => 'private-webchat.sessions.'.$this->session->public_id])
        ->assertUnauthorized();
});

test('the agents channel authorization never signs a visitor channel nor accepts the visitor token', function () {
    $channel = ['socket_id' => '1234.5678', 'channel_name' => 'private-webchat.sessions.'.$this->session->public_id];

    $this->actingAs($this->agent)->postJson('/broadcasting/auth', $channel)->assertForbidden();
    $this->app['auth']->forgetGuards();

    $response = $this->withToken($this->token)->postJson('/broadcasting/auth', $channel);
    expect($response->getStatusCode())->toBeIn([401, 403]);

    expect(glob(base_path('routes/channels/webchat*.php')))->toBe([]);
});

test('an accepted agent reply signals every usable session of the visitor without content', function () {
    WebChatVisitor::send($this, $this->token, 'Hola')->assertCreated();
    $conversation = Conversation::query()->sole();
    $conversation->forceFill(['assigned_user_id' => $this->agent->id, 'status' => 'open'])->save();
    $otherTab = WebChatSession::factory()->create(['connection_id' => $this->connection->id, 'contact_id' => $conversation->contact_id]);
    WebChatSession::factory()->revoked()->create(['connection_id' => $this->connection->id, 'contact_id' => $conversation->contact_id]);
    Event::fake([VisitorSessionSignal::class]);

    $this->actingAs($this->agent)
        ->postJson(route('conversations.messages.store', $conversation), ['body' => 'Texto confidencial de la respuesta', 'client_reference' => (string) Str::uuid()])
        ->assertStatus(202);

    $reply = Message::query()->where('sender_user_id', $this->agent->id)->sole();
    $expectedChannels = ['webchat.sessions.'.$otherTab->public_id, 'webchat.sessions.'.$this->session->public_id];

    Event::assertDispatched(VisitorSessionSignal::class, fn (VisitorSessionSignal $signal): bool => $signal->type === 'messages'
        && $signal->broadcastAs() === 'webchat.signal'
        && $signal->channels === $expectedChannels
        && $signal->broadcastWith() === ['type' => 'messages', 'cursor' => app(VisitorCursor::class)->encode($reply->id)]
        && ! str_contains(json_encode($signal->broadcastWith()), 'confidencial'));
});

test('accepting or closing the conversation signals the visitor', function () {
    WebChatVisitor::send($this, $this->token, 'Hola')->assertCreated();
    $conversation = Conversation::query()->sole();
    Event::fake([VisitorSessionSignal::class]);

    $this->actingAs($this->agent)->post(route('conversations.accept', $conversation))->assertSessionHasNoErrors();

    Event::assertDispatched(VisitorSessionSignal::class, fn (VisitorSessionSignal $signal): bool => $signal->type === 'conversation'
        && $signal->broadcastWith() === ['type' => 'conversation']);
});

test('WhatsApp conversations never signal visitor sessions', function () {
    Event::fake([VisitorSessionSignal::class]);
    WhatsAppFixtures::transport();

    receiveWhatsapp(Connection::factory()->create(['default_department_id' => $this->department->id]));

    Event::assertNotDispatched(VisitorSessionSignal::class);
});

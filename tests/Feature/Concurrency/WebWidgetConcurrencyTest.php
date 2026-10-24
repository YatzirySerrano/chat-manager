<?php

/*
| The web chat widget under REAL concurrency: every visitor, tab or agent is
| a separate PHP process (tests/Support/Concurrency/worker.php) with its own
| database connection, released at the same instant, going through the real
| HTTP kernel of /api/v1/widget (anonymous requests with a Bearer token, as
| the iframe sends them). Rate limits and identity nonces live in a cache
| shared by the processes (CACHE_STORE=database), like Redis in production.
|
| Data is prepared and read back through the autocommitted "committed"
| connection, so the testing database is rebuilt before the next test.
| Nothing is sent to Meta: the web chat transport is local and WhatsApp
| messages arrive as simulated webhooks.
*/

use App\Enums\ConnectionChannel;
use App\Enums\ContactSource;
use App\Enums\ConversationEventType;
use App\Enums\ConversationStatus;
use App\Enums\MessageDirection;
use App\Models\Connection;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\ConversationEvent;
use App\Models\Department;
use App\Models\Message;
use App\Models\User;
use App\Models\WebChatSession;
use App\Models\WebChatWidget;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\Concurrency\ConcurrentProcess;
use Tests\Support\WebChatFixtures;
use Tests\Support\WebChatVisitor;

/** Every racer shares the rate limiters and nonces, as in production. */
const WIDGET_RACE_ENVIRONMENT = ['CACHE_STORE' => 'database'];

/**
 * Run the callback through the autocommitted connection shared with the processes.
 *
 * @template TReturn
 *
 * @param  Closure(): TReturn  $callback
 * @return TReturn
 */
function widgetCommitted(Closure $callback): mixed
{
    if (! array_key_exists('committed', (array) config('database.connections'))) {
        config(['database.connections.committed' => config('database.connections.mariadb')]);
    }

    $default = DB::getDefaultConnection();
    DB::setDefaultConnection('committed');

    try {
        return $callback();
    } finally {
        DB::setDefaultConnection($default);
    }
}

/**
 * @param  list<array<string, mixed>>  $results
 * @return list<array<string, mixed>>
 */
function widgetRaceFailures(array $results): array
{
    return array_values(array_filter($results, fn (array $result): bool => ! $result['ok']));
}

/**
 * HTTP statuses answered to a "visitor-message" process, in order.
 *
 * @param  array<string, mixed>  $result
 * @return list<int>
 */
function widgetStatuses(array $result): array
{
    $statuses = $result['value'] ?? null;

    return is_array($statuses) ? array_values(array_filter($statuses, 'is_int')) : [];
}

/**
 * An active web chat connection routed to the department, authorized for
 * https://tienda.test.
 */
function widgetConnection(Department $department, string $name): Connection
{
    return WebChatFixtures::connection(connection: ['name' => $name, 'default_department_id' => $department->id]);
}

/**
 * A visitor process: a new session on the widget plus its messages.
 *
 * @param  list<string>  $bodies
 */
function widgetVisitor(WebChatWidget $widget, string $ip, array $bodies, ?string $name = null): ConcurrentProcess
{
    return ConcurrentProcess::start('visitor-session', [
        'public_key' => $widget->public_key,
        'ticket' => WebChatVisitor::ticket($widget),
        'ip' => $ip,
        ...($name === null ? [] : ['profile' => ['name' => $name]]),
        'messages' => array_map(fn (string $body): array => ['client_message_id' => (string) Str::uuid(), 'body' => $body], $bodies),
    ], WIDGET_RACE_ENVIRONMENT);
}

afterEach(function () {
    DB::purge('committed');
    RefreshDatabaseState::$migrated = false;
});

test('fourteen visitors on two web connections at once each get their own contact and conversation, with nothing mixed', function () {
    [$sales, $support] = widgetCommitted(function (): array {
        $department = Department::factory()->create(['name' => 'Ventas']);

        return [widgetConnection($department, 'Tienda'), widgetConnection($department, 'Soporte web')];
    });
    $visitors = collect(range(1, 14))->map(fn (int $i): array => [
        'connection' => $i % 2 === 0 ? $sales : $support,
        'bodies' => ["Hola, soy el visitante {$i}", "Segundo mensaje del visitante {$i}"],
    ]);

    $results = ConcurrentProcess::race(array_values($visitors->map(fn (array $visitor, int $index) => widgetVisitor(
        $visitor['connection']->webChatWidget,
        '10.20.0.'.($index + 1),
        $visitor['bodies'],
        'Visitante '.($index + 1),
    ))->all()), 90);

    expect(widgetRaceFailures($results))->toBe([])
        ->and(collect($results)->pluck('value.status')->unique()->all())->toBe([201])
        ->and(collect($results)->flatMap(fn (array $result): array => array_column($result['value']['messages'], 'status'))->unique()->all())->toBe([201]);

    $sessions = widgetCommitted(fn () => WebChatSession::query()->get()->keyBy('public_id'));
    $conversations = widgetCommitted(fn () => Conversation::query()->with('messages')->get()->keyBy('contact_id'));

    expect($sessions)->toHaveCount(14)
        ->and($sessions->pluck('contact_id')->filter()->unique())->toHaveCount(14)
        ->and(widgetCommitted(fn () => Contact::query()->where('source', ContactSource::WebChat)->count()))->toBe(14)
        ->and($conversations)->toHaveCount(14)
        ->and(widgetCommitted(fn () => Message::query()->count()))->toBe(28);

    foreach ($results as $index => $result) {
        $session = $sessions[$result['value']['session']];
        $conversation = $conversations[$session->contact_id];

        expect($session->connection_id)->toBe($visitors[$index]['connection']->id)
            ->and($conversation->connection_id)->toBe($session->connection_id)
            ->and($conversation->status)->toBe(ConversationStatus::Pending)
            ->and($conversation->messages->pluck('body')->sort()->values()->all())->toBe(collect($visitors[$index]['bodies'])->sort()->values()->all())
            ->and($conversation->messages->every(fn (Message $message): bool => $message->direction === MessageDirection::Inbound
                && str_starts_with((string) $message->whatsapp_message_id, 'web.in.'.$session->public_id.'.')))->toBeTrue();
    }
});

test('WhatsApp webhooks and widget messages racing into the same department: nothing is lost and nothing is duplicated', function () {
    [$department, $whatsapp, $web] = widgetCommitted(function (): array {
        $department = Department::factory()->create(['name' => 'Ventas']);

        return [
            $department,
            Connection::factory()->operational()->create(['default_department_id' => $department->id]),
            widgetConnection($department, 'Tienda'),
        ];
    });
    $inbound = fn (int $i, string $wamid) => ConcurrentProcess::start('inbound', [
        'connection_id' => $whatsapp->id,
        'from' => '52155100000'.str_pad((string) $i, 2, '0', STR_PAD_LEFT),
        'wamid' => $wamid,
        'body' => "WhatsApp {$i}",
    ], WIDGET_RACE_ENVIRONMENT);

    // Four WhatsApp customers (Meta repeats one delivery) and four visitors.
    $results = ConcurrentProcess::race([
        $inbound(1, 'wamid.mixed.1'),
        widgetVisitor($web->webChatWidget, '10.30.0.1', ['Web 1']),
        $inbound(2, 'wamid.mixed.2'),
        widgetVisitor($web->webChatWidget, '10.30.0.2', ['Web 2']),
        $inbound(3, 'wamid.mixed.3'),
        widgetVisitor($web->webChatWidget, '10.30.0.3', ['Web 3']),
        $inbound(4, 'wamid.mixed.4'),
        widgetVisitor($web->webChatWidget, '10.30.0.4', ['Web 4']),
        $inbound(4, 'wamid.mixed.4'),
    ], 90);

    expect(widgetRaceFailures($results))->toBe([]);

    $conversations = widgetCommitted(fn () => Conversation::query()->with(['messages', 'channelConnection'])->get());
    $bodies = $conversations->flatMap(fn (Conversation $conversation): Collection => $conversation->messages->pluck('body'))->sort()->values()->all();

    expect($conversations)->toHaveCount(8)
        ->and($conversations->pluck('department_id')->unique()->all())->toBe([$department->id])
        ->and($conversations->pluck('contact_id')->unique())->toHaveCount(8)
        ->and($conversations->groupBy(fn (Conversation $conversation): string => $conversation->channelConnection->channel->value)->map->count()->sortKeys()->all())
        ->toBe([ConnectionChannel::WebChat->value => 4, ConnectionChannel::Whatsapp->value => 4])
        ->and($bodies)->toBe(['Web 1', 'Web 2', 'Web 3', 'Web 4', 'WhatsApp 1', 'WhatsApp 2', 'WhatsApp 3', 'WhatsApp 4'])
        ->and(widgetCommitted(fn () => ConversationEvent::query()->where('type', ConversationEventType::Created)->count()))->toBe(8);
});

test('two agents accepting the same web chat at once: one is redirected, the other gets a 409, and the visitor is signalled once', function () {
    [$department, $web, $agents] = widgetCommitted(function (): array {
        $department = Department::factory()->create(['name' => 'Ventas']);

        return [
            $department,
            widgetConnection($department, 'Tienda'),
            User::factory()->agent()->available()->inDepartments($department)->count(2)->create(),
        ];
    });

    $visitor = ConcurrentProcess::race([widgetVisitor($web->webChatWidget, '10.40.0.1', ['Necesito ayuda con mi pedido'])])[0];
    $conversation = widgetCommitted(fn () => Conversation::query()->sole());
    $uri = route('conversations.accept', $conversation->id, false);

    $results = ConcurrentProcess::race(array_values($agents->map(fn (User $agent) => ConcurrentProcess::start(
        'http-json',
        ['user_id' => $agent->id, 'uri' => $uri, 'method' => 'POST'],
        [...WIDGET_RACE_ENVIRONMENT, 'BROADCAST_CONNECTION' => 'log'],
    ))->all()));

    expect($visitor['ok'])->toBeTrue((string) json_encode($visitor))
        ->and($conversation->status)->toBe(ConversationStatus::Pending)
        ->and(widgetRaceFailures($results))->toBe([])
        ->and(collect($results)->pluck('value.status')->sort()->values()->all())->toBe([302, 409]);

    $winnerIndex = (int) collect($results)->search(fn (array $result): bool => $result['value']['status'] === 302);
    $winner = $agents->values()->get($winnerIndex);
    $signals = collect($results)->flatMap(fn (array $result): array => $result['value']['visitor_signals'])->where('type', 'conversation')->values();

    expect(widgetCommitted(fn () => Conversation::query()->findOrFail($conversation->id)->assigned_user_id))->toBe($winner?->id)
        ->and(widgetCommitted(fn () => ConversationEvent::query()->where('type', ConversationEventType::Accepted)->count()))->toBe(1)
        ->and($signals)->toHaveCount(1)
        ->and($signals[0]['channels'])->toBe(['webchat.sessions.'.$visitor['value']['session']]);
});

test('three tabs of the same visitor writing at once share one conversation', function () {
    $web = widgetCommitted(fn () => widgetConnection(Department::factory()->create(), 'Tienda'));
    $visitor = ConcurrentProcess::race([widgetVisitor($web->webChatWidget, '10.50.0.1', [])])[0];
    $token = (string) $visitor['value']['token'];

    $results = ConcurrentProcess::race(array_map(fn (int $tab) => ConcurrentProcess::start('visitor-message', [
        'token' => $token,
        'ip' => '10.50.0.1',
        'messages' => [['client_message_id' => (string) Str::uuid(), 'body' => "Desde la pestaña {$tab}"]],
    ], WIDGET_RACE_ENVIRONMENT), [1, 2, 3]));

    expect($visitor['value']['status'])->toBe(201)
        ->and(widgetRaceFailures($results))->toBe([])
        ->and(collect($results)->pluck('value.0')->all())->toBe([201, 201, 201])
        ->and(widgetCommitted(fn () => [Contact::query()->count(), Conversation::query()->count(), Message::query()->count()]))->toBe([1, 1, 3])
        ->and(widgetCommitted(fn () => WebChatSession::query()->sole()->contact_id))->toBe(widgetCommitted(fn () => Conversation::query()->sole()->contact_id));
});

test('the same client_message_id sent four times at once is stored once', function () {
    $web = widgetCommitted(fn () => widgetConnection(Department::factory()->create(), 'Tienda'));
    $visitor = ConcurrentProcess::race([widgetVisitor($web->webChatWidget, '10.60.0.1', [])])[0];
    $retry = ['client_message_id' => (string) Str::uuid(), 'body' => 'Hola, ¿siguen abiertos?'];

    $results = ConcurrentProcess::race(array_map(fn () => ConcurrentProcess::start('visitor-message', [
        'token' => (string) $visitor['value']['token'],
        'ip' => '10.60.0.1',
        'messages' => [$retry],
    ], WIDGET_RACE_ENVIRONMENT), range(1, 4)));

    expect(widgetRaceFailures($results))->toBe([])
        ->and(collect($results)->pluck('value.0')->countBy()->sortKeys()->all())->toBe([200 => 3, 201 => 1])
        ->and(widgetCommitted(fn () => [Conversation::query()->count(), Message::query()->count()]))->toBe([1, 1])
        ->and(widgetCommitted(fn () => Message::query()->sole()->body))->toBe($retry['body']);
});

test('the per-session send limit is shared by every process: 30 sends, exactly 20 accepted, and nothing more while it lasts', function () {
    $web = widgetCommitted(fn () => widgetConnection(Department::factory()->create(), 'Tienda'));
    $visitor = ConcurrentProcess::race([widgetVisitor($web->webChatWidget, '10.70.0.1', [])])[0];
    $limit = (int) config('webchat.limits.messages_per_session_per_minute');
    $tab = fn (int $tab, int $sends) => ConcurrentProcess::start('visitor-message', [
        'token' => (string) $visitor['value']['token'],
        'ip' => '10.70.0.1',
        'messages' => array_map(fn (int $i): array => ['client_message_id' => (string) Str::uuid(), 'body' => "Pestaña {$tab}, mensaje {$i}"], range(1, $sends)),
    ], WIDGET_RACE_ENVIRONMENT);

    // Two tabs (two processes, two PHP memories) sending 15 each: the
    // counter lives in the shared cache, so the second tab only gets 5.
    $first = ConcurrentProcess::race([$tab(1, 15)], 90)[0];
    $second = ConcurrentProcess::race([$tab(2, 15)], 90)[0];

    // Then ten tabs at the same instant: the limit is already spent.
    $burst = ConcurrentProcess::race(array_map(fn (int $i) => $tab($i, 1), range(3, 12)), 90);

    expect($limit)->toBe(20)
        ->and(widgetRaceFailures([$first, $second, ...$burst]))->toBe([])
        ->and(collect(widgetStatuses($first))->countBy()->all())->toBe([201 => 15])
        ->and(collect(widgetStatuses($second))->countBy()->sortKeys()->all())->toBe([201 => 5, 429 => 10])
        ->and(collect($burst)->flatMap(fn (array $result): array => widgetStatuses($result))->countBy()->all())->toBe([429 => 10])
        ->and(widgetCommitted(fn () => Message::query()->count()))->toBe(20)
        ->and(widgetCommitted(fn () => Conversation::query()->count()))->toBe(1);
});

test('four integration retries with the same Idempotency-Key at once store one message; the key in another conversation is a conflict', function () {
    [$first, $second, $plain] = widgetCommitted(function (): array {
        $web = widgetConnection(Department::factory()->create(), 'Tienda');
        ['plain' => $plain] = WebChatFixtures::apiToken(['connection_ids' => [$web->id]]);

        return [
            Conversation::factory()->create(['connection_id' => $web->id, 'last_inbound_at' => now()]),
            Conversation::factory()->create(['connection_id' => $web->id, 'last_inbound_at' => now()]),
            $plain,
        ];
    });
    $send = fn (Conversation $conversation, string $key) => ConcurrentProcess::start('http-guest', [
        'method' => 'POST',
        'uri' => route('api.v1.conversations.messages.store', ['conversation' => $conversation->id], false),
        'headers' => ['Authorization' => 'Bearer '.$plain, 'Idempotency-Key' => $key],
        'json' => ['text' => 'Su pedido va en camino'],
    ], ['CACHE_STORE' => 'file']);

    $retries = ConcurrentProcess::race(array_map(fn () => $send($first, 'pedido-race-1'), range(1, 4)), 90);
    $reused = ConcurrentProcess::race([$send($first, 'pedido-race-2'), $send($second, 'pedido-race-2')], 90);

    expect(widgetRaceFailures([...$retries, ...$reused]))->toBe([])
        ->and(collect($retries)->pluck('value.status')->countBy()->sortKeys()->all())->toBe([200 => 3, 201 => 1])
        ->and(collect($retries)->pluck('value.json.data.id')->unique())->toHaveCount(1)
        ->and(collect($reused)->pluck('value.status')->sort()->values()->all())->toBe([201, 409])
        ->and(collect($reused)->firstWhere('value.status', 409)['value']['json']['code'])->toBe('idempotency_key_reused')
        ->and(widgetCommitted(fn () => Message::query()->count()))->toBe(2)
        ->and(widgetCommitted(fn () => Message::query()->where('body', 'Su pedido va en camino')->distinct()->count('client_reference')))->toBe(2);
});

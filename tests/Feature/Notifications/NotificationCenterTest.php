<?php

use App\Actions\Conversations\AcceptConversation;
use App\Actions\Conversations\AssignConversation;
use App\Models\Connection;
use App\Models\Department;
use App\Models\User;
use App\Notifications\NotificationKind;
use Illuminate\Database\QueryException;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

/*
| The bell endpoints and the preferences page: own notifications only, text
| built with fresh access checks, read markers and validation.
*/

beforeEach(function () {
    $this->department = Department::factory()->create();
    $this->connection = Connection::factory()->create(['default_department_id' => $this->department->id]);
    $this->agent = User::factory()->agent()->available()->inDepartments($this->department)->create();
    $this->receipt = receiveWhatsapp($this->connection, profileName: 'Laura Pérez');
});

function onlyNotificationOf(User $user): DatabaseNotification
{
    return DatabaseNotification::query()->where('notifiable_id', $user->id)->where('notifiable_type', $user->getMorphClass())->sole();
}

test('guests cannot read notifications', function () {
    $this->getJson(route('notifications.index'))->assertUnauthorized();
    $this->postJson(route('notifications.read-all'))->assertUnauthorized();
});

test('the list shows my notifications with text built from records I can see', function () {
    $other = User::factory()->agent()->available()->inDepartments(Department::factory()->create())->create();

    $response = $this->actingAs($this->agent)->getJson(route('notifications.index'))->assertOk();

    expect($response->json('data'))->toHaveCount(1)
        ->and($response->json('data.0'))->toMatchArray([
            'kind' => NotificationKind::ConversationPending->value,
            'level' => 'urgent',
            'accessible' => true,
            'conversation_id' => $this->receipt->conversation->id,
            'url' => route('conversations.show', $this->receipt->conversation),
        ])
        ->and($response->json('data.0.title'))->toBe("Nueva conversación pendiente en {$this->department->name}")
        ->and($response->json('unread'))->toBe(['urgent' => 1, 'total' => 1]);

    $this->actingAs($other)->getJson(route('notifications.index'))->assertOk()->assertJsonCount(0, 'data');
});

test('a notification about a conversation I can no longer see shows no details', function () {
    $supervisor = User::factory()->supervisor()->inDepartments($this->department)->create();
    $colleague = User::factory()->agent()->available()->inDepartments($this->department)->create();
    app(AssignConversation::class)->handle($this->receipt->conversation, $colleague, $supervisor);

    $item = $this->actingAs($this->agent)->getJson(route('notifications.index'))->assertOk()->json('data.0');

    expect($item)->toMatchArray(['accessible' => false, 'url' => null, 'conversation_id' => null])
        ->and($item['body'])->toBe('Ya no tienes acceso a estas conversaciones o ya fueron atendidas.')
        ->and(json_encode($item))->not->toContain('Laura');
});

test('assignment notifications name the conversation for its responsible agent', function () {
    $supervisor = User::factory()->supervisor()->inDepartments($this->department)->create();
    app(AssignConversation::class)->handle($this->receipt->conversation, $this->agent, $supervisor);

    $items = collect($this->actingAs($this->agent)->getJson(route('notifications.index'))->json('data'));
    $assigned = $items->firstWhere('kind', NotificationKind::ConversationAssigned->value);

    expect($assigned['title'])->toBe('Se te asignó una conversación')
        ->and($assigned['body'])->toContain($supervisor->name)
        ->and($assigned['url'])->toBe(route('conversations.show', $this->receipt->conversation));
});

test('marking read only affects my own notifications', function () {
    $other = User::factory()->agent()->available()->inDepartments($this->department)->create();
    receiveWhatsapp($this->connection, '5215599999999');
    $mine = onlyNotificationOf($this->agent);
    $theirs = onlyNotificationOf($other);

    $this->actingAs($this->agent)->postJson(route('notifications.read', $theirs->id))
        ->assertOk()
        ->assertJson(['changed' => false]);
    expect($theirs->refresh()->read_at)->toBeNull();

    $this->actingAs($this->agent)->postJson(route('notifications.read', $mine->id))
        ->assertOk()
        ->assertJson(['changed' => true, 'unread' => ['total' => 0, 'urgent' => 0]]);
    expect($mine->refresh()->read_at)->not->toBeNull();

    $this->actingAs($this->agent)->postJson(route('notifications.read', $mine->id))->assertJson(['changed' => false]);
});

test('mark all read and conversation read touch only my unread notifications', function () {
    $other = User::factory()->agent()->available()->inDepartments($this->department)->create();
    app(AcceptConversation::class)->handle($this->receipt->conversation, $this->agent);
    receiveWhatsapp($this->connection, body: 'Más detalles');
    receiveWhatsapp($this->connection, '5215599999999');

    $this->actingAs($this->agent)->postJson(route('notifications.conversations.read', $this->receipt->conversation->id))
        ->assertOk()
        ->assertJson(['changed' => 1]);

    $this->actingAs($this->agent)->postJson(route('notifications.read-all'))->assertOk()->assertJson(['unread' => ['total' => 0]]);

    expect(DatabaseNotification::query()->where('notifiable_id', $other->id)->whereNull('read_at')->count())->toBeGreaterThan(0);
});

test('a deadlock while marking a conversation read is retried instead of failing the request', function () {
    app(AcceptConversation::class)->handle($this->receipt->conversation, $this->agent);
    receiveWhatsapp($this->connection, body: 'Más detalles');
    $deadlocks = 0;

    // MariaDB picks one of two concurrent "mark read" updates as a deadlock
    // victim (the bell and the open conversation mark it read at once).
    DB::beforeExecuting(function (string $query) use (&$deadlocks): void {
        if ($deadlocks === 0 && str_starts_with($query, 'update `notifications`')) {
            $deadlocks++;

            throw new QueryException('mariadb', $query, [], new PDOException('SQLSTATE[40001]: Serialization failure: 1213 Deadlock found when trying to get lock; try restarting transaction', 40001));
        }
    });

    $this->actingAs($this->agent)->postJson(route('notifications.conversations.read', $this->receipt->conversation->id))
        ->assertOk()
        ->assertJson(['changed' => 1]);

    expect($deadlocks)->toBe(1);
});

test('the list paginates by last update', function () {
    $notification = onlyNotificationOf($this->agent);

    foreach (range(1, 21) as $index) {
        DatabaseNotification::query()->create([
            'id' => (string) str()->uuid(), 'type' => 'test', 'notifiable_type' => $this->agent->getMorphClass(), 'notifiable_id' => $this->agent->id,
            'kind' => NotificationKind::AccessChanged->value, 'level' => 'informative', 'data' => ['reason' => 'role'],
            'created_at' => now()->subMinutes($index), 'updated_at' => now()->subMinutes($index),
        ]);
    }

    $first = $this->actingAs($this->agent)->getJson(route('notifications.index'))->assertJsonCount(20, 'data');
    $next = $first->json('next_before');

    expect($next)->not->toBeNull();

    $this->actingAs($this->agent)->getJson(route('notifications.index', ['before' => $next]))->assertJsonCount(2, 'data');
    expect($notification->exists)->toBeTrue();
});

test('notifications updated in the same second are not skipped between pages', function () {
    DatabaseNotification::query()->where('notifiable_id', $this->agent->id)->delete();
    $moment = now()->startOfSecond();

    foreach (range(1, 22) as $index) {
        DatabaseNotification::query()->create([
            'id' => (string) str()->uuid(), 'type' => 'test', 'notifiable_type' => $this->agent->getMorphClass(), 'notifiable_id' => $this->agent->id,
            'kind' => NotificationKind::AccessChanged->value, 'level' => 'informative', 'data' => ['reason' => 'role'],
            'created_at' => $moment, 'updated_at' => $moment,
        ]);
    }

    $first = $this->actingAs($this->agent)->getJson(route('notifications.index'))->assertJsonCount(20, 'data');
    $second = $this->actingAs($this->agent)->getJson(route('notifications.index', [
        'before' => $first->json('next_before'),
        'before_id' => $first->json('next_before_id'),
    ]))->assertJsonCount(2, 'data')->assertJsonPath('next_before', null);

    $ids = [...$first->json('data.*.id'), ...$second->json('data.*.id')];

    expect(array_unique($ids))->toHaveCount(22);
});

test('the preferences page lists only kinds that concern me', function () {
    $this->actingAs($this->agent)->get(route('notifications.preferences.edit'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('settings/Notifications')
            ->where('kinds', fn ($kinds) => collect($kinds)->pluck('value')->contains(NotificationKind::ConversationPending->value)
                && ! collect($kinds)->pluck('value')->contains(NotificationKind::ConnectionProblem->value))
            ->where('kinds.0.preferences', ['in_app' => true, 'browser' => false, 'sound' => true]));
});

test('saving preferences validates kinds and reports when nothing changed', function () {
    $this->actingAs($this->agent)->put(route('notifications.preferences.update'), ['preferences' => [
        ['kind' => 'unknown.kind', 'in_app' => true, 'browser' => true, 'sound' => true],
    ]])->assertSessionHasErrors('preferences.0.kind');

    $payload = ['preferences' => [
        ['kind' => NotificationKind::ConversationMessage->value, 'in_app' => true, 'browser' => true, 'sound' => false],
    ]];

    $this->actingAs($this->agent)->put(route('notifications.preferences.update'), $payload)
        ->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'Preferencias de notificación guardadas.']);
    $this->actingAs($this->agent)->put(route('notifications.preferences.update'), $payload)
        ->assertInertiaFlash('toast', ['type' => 'info', 'message' => 'No había cambios por guardar.']);

    $preferences = $this->actingAs($this->agent)->getJson(route('notifications.index'))->json('preferences');

    expect($preferences[NotificationKind::ConversationMessage->value])->toBe(['in_app' => true, 'browser' => true, 'sound' => false]);
});

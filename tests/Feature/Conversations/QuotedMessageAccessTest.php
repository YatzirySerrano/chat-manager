<?php

use App\Enums\DataScope;
use App\Enums\MessageType;
use App\Enums\Permission;
use App\Models\Conversation;
use App\Models\ConversationRead;
use App\Models\Department;
use App\Models\Message;
use App\Models\MessageAttachment;
use App\Models\Role;
use App\Models\User;
use App\Services\Conversations\ConversationTimeline;
use Illuminate\Support\Facades\DB;
use Tests\Support\WebChatFixtures;

/*
| Quoted messages. A quote of the same conversation is shown as is. A
| customer may also reply to a message of an earlier conversation with the
| same contact and connection: its excerpt and a link to open it are shown
| only to viewers who may open that conversation; anyone else sees only
| that it quotes an earlier conversation, with no text, ids, link or file
| data. Rendering quotes changes nothing.
*/

const QUOTE_SECRET = 'Mi número de tarjeta termina en 4242';

beforeEach(function () {
    $this->sales = Department::factory()->create();
    $this->billing = Department::factory()->create();
    $this->agent = User::factory()->agent()->available()->inDepartments($this->sales)->create();
    $this->current = Conversation::factory()->inDepartment($this->sales)->assignedTo($this->agent)->create();
});

/**
 * An earlier conversation of the same contact and connection, with the
 * message the customer quotes now.
 *
 * @return array{conversation: Conversation, quoted: Message, reply: Message}
 */
function quotePreviousConversation(Conversation $current, Department $department, bool $closed = true, array $quoted = []): array
{
    $factory = Conversation::factory()->inDepartment($department);
    $previous = ($closed ? $factory->closed() : $factory)->create([
        'contact_id' => $current->contact_id,
        'connection_id' => $current->connection_id,
    ]);
    $quotedMessage = Message::factory()->create(['conversation_id' => $previous->id, 'body' => QUOTE_SECRET, ...$quoted]);
    $reply = Message::factory()->create([
        'conversation_id' => $current->id,
        'body' => '¿Siguen teniendo mis datos?',
        'reply_to_message_id' => $quotedMessage->id,
    ]);

    return ['conversation' => $previous, 'quoted' => $quotedMessage, 'reply' => $reply];
}

/**
 * The reply_to of a message as the inbox chat receives it.
 *
 * @return array<string, mixed>|null
 */
function quoteSeenBy(object $test, User $viewer, Conversation $conversation, Message $message): ?array
{
    $response = $test->actingAs($viewer)->getJson(route('conversations.timeline', $conversation))->assertOk();

    return collect($response->json('messages'))->firstWhere('id', $message->id)['reply_to'] ?? null;
}

function quoteViewer(DataScope $scope, array $permissions, Department ...$departments): User
{
    $role = Role::factory()->scope($scope)->withPermissions([Permission::ViewConversations, ...$permissions])->create();

    return User::factory()->withRole($role)->inDepartments($departments)->create();
}

const QUOTE_RESTRICTED = [
    'id' => null,
    'direction' => null,
    'type' => null,
    'body' => null,
    'origin' => 'restricted',
    'conversation_id' => null,
    'url' => null,
    'preview' => '',
    'from_me' => false,
];

test('a quote of the same conversation is shown with its excerpt and id to jump to', function () {
    $quoted = Message::factory()->create(['conversation_id' => $this->current->id, 'body' => str_repeat('a', 200)]);
    $reply = Message::factory()->create(['conversation_id' => $this->current->id, 'reply_to_message_id' => $quoted->id]);

    expect(quoteSeenBy($this, $this->agent, $this->current, $reply))->toMatchArray([
        'id' => $quoted->id,
        'origin' => 'conversation',
        'body' => str_repeat('a', 140).'...',
        'conversation_id' => null,
        'url' => null,
    ]);
});

test('a quote of an older page is still shown in full: the original need not be loaded', function () {
    $quoted = Message::factory()->create(['conversation_id' => $this->current->id, 'body' => 'El primero']);
    Message::factory()->count(ConversationTimeline::PAGE_SIZE + 2)->create(['conversation_id' => $this->current->id]);
    $reply = Message::factory()->create(['conversation_id' => $this->current->id, 'reply_to_message_id' => $quoted->id]);

    expect(quoteSeenBy($this, $this->agent, $this->current, $reply))->toMatchArray(['id' => $quoted->id, 'body' => 'El primero', 'origin' => 'conversation']);
});

test('a quote of a closed earlier conversation the viewer cannot read shows only a neutral text', function () {
    ['reply' => $reply] = quotePreviousConversation($this->current, $this->sales);
    $viewer = quoteViewer(DataScope::Departments, [], $this->sales);
    $this->current->forceFill(['assigned_user_id' => $viewer->id])->save();

    $response = $this->actingAs($viewer)->getJson(route('conversations.timeline', $this->current))->assertOk();

    expect(collect($response->json('messages'))->firstWhere('id', $reply->id)['reply_to'])->toBe(QUOTE_RESTRICTED)
        ->and($response->getContent())->not->toContain(QUOTE_SECRET);
});

test('with the history permission the closed earlier conversation is quoted with a link to open it', function () {
    ['conversation' => $previous, 'quoted' => $quoted, 'reply' => $reply] = quotePreviousConversation($this->current, $this->sales);
    $viewer = quoteViewer(DataScope::Departments, [Permission::ViewConversationHistory], $this->sales);

    expect(quoteSeenBy($this, $viewer, $this->current, $reply))->toBe([
        'id' => $quoted->id,
        'direction' => 'inbound',
        'type' => 'text',
        'body' => QUOTE_SECRET,
        'origin' => 'previous_conversation',
        'conversation_id' => $previous->id,
        'url' => route('conversations.show', $previous),
        'preview' => QUOTE_SECRET,
        'from_me' => false,
    ]);
});

test('an earlier conversation of another department is neutral for a department-scoped viewer and shown to a global one', function () {
    ['conversation' => $previous, 'reply' => $reply] = quotePreviousConversation($this->current, $this->billing);
    $departmental = quoteViewer(DataScope::Departments, [Permission::ViewConversationHistory], $this->sales);
    $global = quoteViewer(DataScope::Global, [Permission::ViewConversationHistory]);

    $response = $this->actingAs($departmental)->getJson(route('conversations.timeline', $this->current))->assertOk();

    expect(collect($response->json('messages'))->firstWhere('id', $reply->id)['reply_to'])->toBe(QUOTE_RESTRICTED)
        ->and($response->getContent())->not->toContain(QUOTE_SECRET)
        ->and($response->getContent())->not->toContain($this->billing->name)
        ->and($response->getContent())->not->toContain("conversations/{$previous->id}")
        ->and(quoteSeenBy($this, $global, $this->current, $reply))->toMatchArray(['origin' => 'previous_conversation', 'conversation_id' => $previous->id]);
});

test('a quoted file of an unreachable earlier conversation leaks no attachment data', function () {
    ['conversation' => $previous, 'quoted' => $quoted, 'reply' => $reply] = quotePreviousConversation($this->current, $this->billing, quoted: ['type' => MessageType::Image, 'body' => null]);
    (new MessageAttachment(['disk' => 'local', 'path' => 'media/2026/11/comprobante-secreto', 'mime_type' => 'image/jpeg', 'file_name' => 'comprobante-secreto.jpg', 'size_bytes' => 10]))
        ->forceFill(['message_id' => $quoted->id])->save();

    $response = $this->actingAs($this->agent)->getJson(route('conversations.timeline', $this->current))->assertOk();

    expect(collect($response->json('messages'))->firstWhere('id', $reply->id)['reply_to'])->toBe(QUOTE_RESTRICTED)
        ->and($response->getContent())->not->toContain('comprobante-secreto')
        ->and($response->getContent())->not->toContain("conversations\/{$previous->id}\/messages");
    $this->actingAs($this->agent)
        ->get(route('conversations.messages.attachments.show', ['conversation' => $previous->id, 'message' => $quoted->id, 'attachment' => $quoted->attachments()->sole()->id]))
        ->assertNotFound();
});

test('a quote of archived history is shown only with the archive permission, linking to the archive', function () {
    ['conversation' => $previous, 'reply' => $reply] = quotePreviousConversation($this->current, $this->sales);
    $previous->forceFill(['origin' => 'whaticket', 'history_only_at' => now()])->save();
    $global = quoteViewer(DataScope::Global, [Permission::ViewConversationHistory]);
    $archivist = quoteViewer(DataScope::Global, [Permission::ViewConversationHistory, Permission::ViewArchivedHistory]);

    expect(quoteSeenBy($this, $global, $this->current, $reply))->toBe(QUOTE_RESTRICTED)
        ->and(quoteSeenBy($this, $archivist, $this->current, $reply))->toMatchArray([
            'origin' => 'previous_conversation',
            'body' => QUOTE_SECRET,
            'url' => route('admin.history.conversations.show', $previous),
        ]);
});

test('a deleted original leaves the reply without a quote and the chat keeps working', function () {
    ['quoted' => $quoted, 'reply' => $reply] = quotePreviousConversation($this->current, $this->sales);
    $quoted->delete();

    expect(quoteSeenBy($this, $this->agent, $this->current, $reply))->toBeNull();
});

test('showing quotes writes nothing and moves no read marker', function () {
    quotePreviousConversation($this->current, $this->sales);
    quotePreviousConversation($this->current, $this->billing);

    $writes = databaseWritesDuring(fn () => $this->actingAs($this->agent)
        ->getJson(route('conversations.timeline', $this->current))
        ->assertOk());

    expect($writes)->toBe([])
        ->and(ConversationRead::query()->count())->toBe(0);
});

test('authorizing quotes of earlier conversations costs a constant number of queries', function () {
    $viewer = quoteViewer(DataScope::Global, [Permission::ViewConversationHistory]);
    $queries = function () use ($viewer): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->actingAs($viewer)->getJson(route('conversations.timeline', $this->current))->assertOk();
        DB::disableQueryLog();

        return count(DB::getQueryLog());
    };

    quotePreviousConversation($this->current, $this->sales);
    // The first request also warms the settings and permission caches.
    $queries();
    $one = $queries();
    foreach (range(1, 5) as $ignored) {
        quotePreviousConversation($this->current, $this->billing);
    }

    expect($queries())->toBe($one);
});

test('the integration API exposes no quoted data', function () {
    ['token' => $token, 'plain' => $plain] = WebChatFixtures::apiToken(['connection_ids' => [$this->current->connection_id]]);
    ['reply' => $reply] = quotePreviousConversation($this->current, $this->billing);

    $response = $this->withToken($plain)->getJson(route('api.v1.conversations.messages.index', ['conversation' => $this->current->id]))->assertOk();

    expect(collect($response->json('data'))->firstWhere('id', $reply->id))->not->toHaveKey('reply_to')
        ->and($response->getContent())->not->toContain(QUOTE_SECRET);
});

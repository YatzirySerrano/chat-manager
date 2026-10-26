<?php

use App\Enums\MessageStatus;
use App\Enums\Permission;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Department;
use App\Models\Message;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Realtime\RealtimeTestSupport;
use Tests\Support\WhatsAppFixtures;

/*
| Conversations imported from Whaticket. Those kept as operational history
| (destination «Importar») are visible like any closed conversation but are
| read-only for good: every operation answers 403 with its own explanation.
| Those archived as read-only history (history_only) do not exist for the
| operational routes (404 for everyone); they are consulted only from
| «Historial de Whaticket» (admin.history.*).
*/

const IMPORTED_TEXT = 'Esta conversación es un historial importado y es de solo consulta.';

function importedConversation(Department $department, User $agent, bool $historyOnly = false): Conversation
{
    $conversation = Conversation::factory()->inDepartment($department)->assignedTo($agent)->closed()->create();
    $conversation->forceFill(['origin' => 'whaticket', 'history_only_at' => $historyOnly ? now() : null])->save();

    return $conversation->refresh();
}

beforeEach(function () {
    $this->sales = Department::factory()->create();
    $this->admin = User::factory()->administrator()->create();
    $this->agent = User::factory()->agent()->inDepartments($this->sales)->create();
    $this->imported = importedConversation($this->sales, $this->agent);
    $this->archived = importedConversation($this->sales, $this->agent, historyOnly: true);
});

test('every operation on an imported conversation is a 403 that says it is read-only history', function (string $route, array $input) {
    $input = array_map(fn (mixed $value): mixed => match ($value) {
        'agent' => $this->agent->id,
        'department' => $this->sales->id,
        default => $value,
    }, $input);

    $this->actingAs($this->admin)
        ->postJson(route($route, $this->imported), [...$input, 'version' => $this->imported->version])
        ->assertForbidden()
        ->assertExactJson(['message' => IMPORTED_TEXT]);

    expect($this->imported->refresh()->status->value)->toBe('closed')
        ->and($this->imported->events()->count())->toBe(0);
})->with([
    'reopen' => ['conversations.reopen', []],
    'close' => ['conversations.close', []],
    'release' => ['conversations.release', []],
    'assign' => ['conversations.assign', ['user_id' => 'agent']],
    'transfer' => ['conversations.transfer', ['department_id' => 'department']],
    'accept' => ['conversations.accept', []],
    'reply' => ['conversations.messages.store', ['body' => 'Hola', 'client_reference' => '1b4e28ba-2fa1-4d3b-8c5e-1234567890ab']],
    'note' => ['conversations.notes.store', ['body' => 'Nota']],
]);

test('tags and templates are refused on imported conversations too, and Inertia visits get the explanation', function () {
    $this->actingAs($this->admin)
        ->putJson(route('conversations.tags.update', $this->imported), ['tag_ids' => []])
        ->assertForbidden()
        ->assertExactJson(['message' => IMPORTED_TEXT]);

    $this->actingAs($this->admin)
        ->getJson(route('conversations.templates.index', $this->imported))
        ->assertForbidden()
        ->assertExactJson(['message' => IMPORTED_TEXT]);

    $this->actingAs($this->admin)
        ->withHeaders(['X-Inertia' => 'true'])
        ->from(route('conversations.index'))
        ->post(route('conversations.reopen', $this->imported), ['version' => 0])
        ->assertRedirect(route('conversations.index'))
        ->assertInertiaFlash('toast', ['type' => 'error', 'message' => IMPORTED_TEXT]);
});

test('the interface offers nothing on an imported conversation', function () {
    foreach (['accept', 'assign', 'transfer', 'release', 'close', 'reopen', 'addNote', 'tag', 'reply', 'sendTemplate'] as $ability) {
        expect($this->admin->can($ability, $this->imported))->toBeFalse("imported must refuse {$ability}");
    }

    expect($this->admin->can('view', $this->imported))->toBeTrue();
});

test('history archived as read-only does not exist for the operational routes, for anyone', function (string $route) {
    $viewer = User::factory()->administrator()->create();

    expect($viewer->holdsPermission(Permission::ViewArchivedHistory))->toBeTrue();

    $this->actingAs($viewer);
    $response = match ($route) {
        'page' => $this->get(route('conversations.show', $this->archived)),
        'timeline' => $this->getJson(route('conversations.timeline', $this->archived)),
        'reopen' => $this->postJson(route('conversations.reopen', $this->archived), ['version' => 0]),
        'accept' => $this->postJson(route('conversations.accept', $this->archived)),
        'dossier' => $this->post(route('exports.generate', ['conversation-dossier', 'pdf', 'conversation' => $this->archived->id])),
    };

    $response->assertNotFound();
})->with(['page', 'timeline', 'reopen', 'accept', 'dossier']);

test('nobody subscribes to the real-time channel of archived history', function () {
    RealtimeTestSupport::useReverb();

    $this->actingAs($this->admin)
        ->postJson('/broadcasting/auth', ['socket_id' => '1234.5678', 'channel_name' => "private-conversations.{$this->archived->id}"])
        ->assertForbidden();
    $this->actingAs($this->admin)
        ->postJson('/broadcasting/auth', ['socket_id' => '1234.5678', 'channel_name' => "private-conversations.{$this->imported->id}"])
        ->assertOk();
});

test('a queue agent cannot learn anything about archived history through accept', function () {
    $queueAgent = User::factory()->agent()->inDepartments($this->sales)->create();

    $this->actingAs($queueAgent)->postJson(route('conversations.accept', $this->archived))->assertNotFound();
});

test('retrying a message brought from another system is refused', function () {
    $live = Conversation::factory()->inDepartment($this->sales)->assignedTo($this->agent)->create(['last_inbound_at' => now()]);
    $message = WhatsAppFixtures::outbound($live, [
        'status' => MessageStatus::Failed,
        'error_code' => 'provider_unreachable',
        'failed_at' => now(),
        'sender_user_id' => $this->agent->id,
        'metadata' => ['import' => ['source' => 'whaticket', 'from_me' => true]],
    ]);

    $this->actingAs($this->agent)
        ->postJson(route('conversations.messages.retry', [$live, $message]))
        ->assertForbidden()
        ->assertExactJson(['message' => IMPORTED_TEXT]);

    expect($message->refresh()->status)->toBe(MessageStatus::Failed);

    $importedMessage = WhatsAppFixtures::outbound($this->imported, ['status' => MessageStatus::Failed, 'failed_at' => now(), 'sender_user_id' => $this->agent->id]);

    $this->actingAs($this->agent)
        ->postJson(route('conversations.messages.retry', [$this->imported, $importedMessage]))
        ->assertForbidden()
        ->assertExactJson(['message' => IMPORTED_TEXT]);
});

test('imported messages tell the chat where they come from', function () {
    Message::factory()->create([
        'conversation_id' => $this->imported->id,
        'direction' => 'inbound',
        'type' => 'text',
        'status' => 'received',
        'body' => 'Hola',
        'metadata' => ['import' => ['source' => 'whaticket', 'sender_name' => 'Laura', 'from_me' => false, 'batch_id' => 4]],
    ]);
    Message::factory()->create([
        'conversation_id' => $this->imported->id,
        'direction' => 'inbound',
        'type' => 'text',
        'status' => 'received',
        'body' => 'Sin metadatos',
    ]);

    $messages = collect($this->actingAs($this->admin)->getJson(route('conversations.timeline', $this->imported))->assertOk()->json('messages'));

    expect($messages->firstWhere('body', 'Hola')['imported'])->toBe(['source' => 'whaticket', 'sender_name' => 'Laura', 'from_me' => false])
        ->and($messages->firstWhere('body', 'Sin metadatos')['imported'])->toBeNull();
});

test('the contact page lists archived history, with its link, only to whoever consults the history', function () {
    $contact = Contact::query()->findOrFail($this->archived->contact_id);
    $this->imported->forceFill(['contact_id' => $contact->id])->save();
    $live = Conversation::factory()->inDepartment($this->sales)->assignedTo($this->agent)->create(['contact_id' => $contact->id]);
    $withoutArchive = User::factory()->supervisor()->inDepartments($this->sales)->create();

    expect($withoutArchive->holdsPermission(Permission::ViewArchivedHistory))->toBeFalse();

    $this->actingAs($this->admin)
        ->get(route('contacts.show', $contact))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('conversations', fn ($rows) => collect($rows)->pluck('id')->sort()->values()->all() === collect([$live->id, $this->imported->id, $this->archived->id])->sort()->values()->all())
            ->where('conversations', fn ($rows) => collect($rows)->firstWhere('id', $this->archived->id)['history_only'] === true
                && collect($rows)->firstWhere('id', $this->archived->id)['history_url'] === route('admin.history.conversations.show', $this->archived)
                && collect($rows)->firstWhere('id', $this->imported->id)['origin'] === 'whaticket'
                && collect($rows)->firstWhere('id', $live->id)['origin'] === 'live'
                && collect($rows)->firstWhere('id', $live->id)['history_url'] === null));

    $this->actingAs($withoutArchive)
        ->get(route('contacts.show', $contact))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('conversations', fn ($rows) => collect($rows)->pluck('id')->sort()->values()->all() === collect([$live->id, $this->imported->id])->sort()->values()->all()
                && collect($rows)->every(fn (array $row): bool => $row['history_url'] === null)));
});

test('the details panel of a live conversation lists the contact\'s history the same way', function () {
    $live = Conversation::factory()->inDepartment($this->sales)->assignedTo($this->agent)->create(['contact_id' => $this->archived->contact_id]);

    $this->actingAs($this->admin)
        ->get(route('conversations.index', ['c' => $live->id]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('details.history', fn ($rows) => collect($rows)->firstWhere('id', $this->archived->id)['history_url'] === route('admin.history.conversations.show', $this->archived)));
});

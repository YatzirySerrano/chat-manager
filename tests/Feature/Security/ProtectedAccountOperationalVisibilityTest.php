<?php

use App\Enums\AuditAction;
use App\Enums\ConversationEventType;
use App\Enums\MessageDirection;
use App\Enums\Permission;
use App\Models\AuditLog;
use App\Models\Connection;
use App\Models\Conversation;
use App\Models\ConversationNote;
use App\Models\Department;
use App\Models\Message;
use App\Models\TeamChat\TeamMessage;
use App\Models\TeamChat\TeamThread;
use App\Models\User;
use App\Reports\Rendering\PdfRenderer;
use App\Reports\ReportRegistry;
use App\Services\Exports\ReportExporter;
use App\Support\ProtectedAccounts;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\WebChatFixtures;

/*
| A protected account (root role) does not exist for anyone else in the
| operational modules: it is never offered in filters, destinations or the
| team chat directory, operations naming it fail as for an unknown id, and
| the records it took part in are kept but presented as «Administración del
| sistema». The root authority keeps seeing itself normally.
*/

beforeEach(function () {
    $this->department = Department::factory()->create();
    $this->connection = Connection::factory()->verified()->create();
    $this->root = User::factory()->superAdministrator()->create(['name' => 'Cuenta Técnica', 'email' => 'tecnica@empresa.test']);
    $this->administrator = User::factory()->administrator()->create(['name' => 'Adela Admin']);
    $this->agent = User::factory()->agent()->inDepartments($this->department)->create(['name' => 'Ana Agente']);
    $this->conversation = Conversation::factory()
        ->for($this->connection, 'channelConnection')
        ->inDepartment($this->department)
        ->assignedTo($this->root)
        ->create();
});

/**
 * Rendered HTML (PDF source) of a report for the actor.
 *
 * @param  array<string, mixed>  $input
 */
function operationalReportHtml(string $key, User $actor, array $input = []): string
{
    $exporter = app(ReportExporter::class);
    $definition = app(ReportRegistry::class)->find($key);

    return app(PdfRenderer::class)->html($definition, $exporter->prepare($definition, $actor, $input));
}

/**
 * @return list<int>
 */
function offeredDestinationIds(User $actor, Conversation $conversation): array
{
    $destinations = test()->actingAs($actor)->getJson(route('conversations.destinations', $conversation))->assertOk()->json();

    return collect($destinations['departments'])->flatMap(fn (array $department): array => $department['agents'])
        ->merge($destinations['unrouted_agents'])
        ->pluck('id')
        ->unique()
        ->values()
        ->all();
}

test('the inbox agent filter never offers the root account to an administrator, but does to root', function () {
    $this->actingAs($this->administrator)
        ->get(route('conversations.index'))
        ->assertInertia(fn (Assert $page) => $page->where('agents', fn ($agents): bool => collect($agents)->pluck('id')->doesntContain($this->root->id)
            && collect($agents)->pluck('id')->contains($this->agent->id)));

    $this->actingAs($this->root)
        ->get(route('conversations.index'))
        ->assertInertia(fn (Assert $page) => $page->where('agents', fn ($agents): bool => collect($agents)->pluck('id')->contains($this->root->id)));
});

test('filtering the inbox by the root id matches nothing for anyone else', function () {
    $this->actingAs($this->administrator)
        ->getJson(route('conversations.page', ['agent' => $this->root->id, 'view' => 'all', 'status' => 'all']))
        ->assertOk()
        ->assertJsonCount(0, 'data');

    $this->actingAs($this->root)
        ->getJson(route('conversations.page', ['agent' => $this->root->id, 'view' => 'all', 'status' => 'all']))
        ->assertOk()
        ->assertJsonPath('data.0.id', $this->conversation->id);
});

test('the open conversation attended by root shows the system administration as its agent to an administrator', function () {
    $this->actingAs($this->administrator)
        ->get(route('conversations.index', ['c' => $this->conversation->id]))
        ->assertInertia(fn (Assert $page) => $page->where('conversation.assignee.name', ProtectedAccounts::SYSTEM_NAME));
});

test('the timeline presents messages, notes and events of root as the system administration to an administrator', function () {
    Message::factory()->create([
        'conversation_id' => $this->conversation->id,
        'direction' => MessageDirection::Outbound,
        'sender_user_id' => $this->root->id,
        'body' => 'Respuesta técnica',
    ]);
    ConversationNote::factory()->create(['conversation_id' => $this->conversation->id, 'user_id' => $this->root->id, 'body' => 'Nota técnica']);
    $this->conversation->events()->create([
        'type' => ConversationEventType::Assigned,
        'actor_user_id' => $this->root->id,
        'to_user_id' => $this->root->id,
        'created_at' => now(),
    ]);

    $timeline = $this->actingAs($this->administrator)->getJson(route('conversations.timeline', $this->conversation))->assertOk();

    expect($timeline->json('messages.0.sender.name'))->toBe(ProtectedAccounts::SYSTEM_NAME)
        ->and($timeline->json('notes.0.author.name'))->toBe(ProtectedAccounts::SYSTEM_NAME)
        ->and($timeline->json('events.0.actor'))->toBe(ProtectedAccounts::SYSTEM_NAME)
        ->and($timeline->json('events.0.to_user'))->toBe(ProtectedAccounts::SYSTEM_NAME)
        ->and(json_encode($timeline->json(), JSON_UNESCAPED_UNICODE))->not->toContain('Cuenta Técnica');

    $own = $this->actingAs($this->root)->getJson(route('conversations.timeline', $this->conversation))->assertOk();

    expect($own->json('messages.0.sender.name'))->toBe('Cuenta Técnica')
        ->and($own->json('events.0.actor'))->toBe('Cuenta Técnica');
});

test('the conversation list presents a root assignee as the system administration to an administrator', function () {
    $this->actingAs($this->administrator)
        ->getJson(route('conversations.page', ['view' => 'all', 'status' => 'all']))
        ->assertOk()
        ->assertJsonPath('data.0.assignee.name', ProtectedAccounts::SYSTEM_NAME);

    $this->actingAs($this->root)
        ->getJson(route('conversations.page', ['view' => 'all', 'status' => 'all']))
        ->assertOk()
        ->assertJsonPath('data.0.assignee.name', 'Cuenta Técnica');
});

test('root is never offered as a transfer destination to an administrator or an agent, but is to root', function () {
    $pending = Conversation::factory()->for($this->connection, 'channelConnection')->inDepartment($this->department)->assignedTo($this->agent)->create();

    expect(offeredDestinationIds($this->administrator, $pending))->not->toContain($this->root->id)
        ->and(offeredDestinationIds($this->agent, $pending))->not->toContain($this->root->id)
        ->and(offeredDestinationIds($this->root, $pending))->toContain($this->root->id);
});

test('assigning or transferring to the root id fails as an unknown agent for anyone else', function (string $route) {
    $pending = Conversation::factory()->for($this->connection, 'channelConnection')->inDepartment($this->department)->create();

    $this->actingAs($this->administrator)
        ->from(route('conversations.show', $pending))
        ->post(route($route, $pending), ['user_id' => $this->root->id, 'version' => 0])
        ->assertSessionHasErrors(['conversation' => 'El agente elegido no existe.']);

    expect($pending->refresh()->assigned_user_id)->toBeNull();
})->with(['conversations.assign', 'conversations.transfer']);

test('the team chat directory leaves root out for an administrator and an agent', function (string $viewer) {
    $this->actingAs($this->{$viewer})
        ->getJson(route('team-chat.people', ['search' => 'Técnica']))
        ->assertOk()
        ->assertJsonCount(0, 'data');

    $this->actingAs($this->{$viewer})
        ->post(route('team-chat.direct.store'), ['user_id' => $this->root->id])
        ->assertSessionHasErrors(['user_id' => 'No puedes iniciar un chat con esta persona.']);

    expect(TeamThread::query()->count())->toBe(0);
})->with(['administrator', 'agent']);

test('a group can not be created with root by an administrator', function () {
    $this->actingAs($this->administrator)
        ->post(route('team-chat.groups.store'), ['name' => 'Grupo', 'participant_ids' => [$this->root->id]])
        ->assertSessionHasErrors('participant_ids');

    expect(TeamThread::query()->count())->toBe(0);
});

test('root still finds people in the team chat directory', function () {
    $this->actingAs($this->root)
        ->getJson(route('team-chat.people', ['search' => 'Adela']))
        ->assertOk()
        ->assertJsonPath('data.0.id', $this->administrator->id);
});

test('an existing chat with root keeps working and presents root as the system administration', function () {
    $thread = TeamThread::factory()->direct($this->root, $this->administrator)->create();
    TeamMessage::factory()->in($thread, $this->root)->create(['body' => 'Hola desde soporte']);

    $this->actingAs($this->administrator)
        ->get(route('team-chat.threads.show', $thread))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('activeThread.title', ProtectedAccounts::SYSTEM_NAME)
            ->where('activeThread.partner.name', ProtectedAccounts::SYSTEM_NAME)
            ->where('messages.data.0.author.name', ProtectedAccounts::SYSTEM_NAME)
            ->where('threads.threads.0.title', ProtectedAccounts::SYSTEM_NAME));

    $this->actingAs($this->administrator)
        ->get(route('team-chat.index', ['search' => 'Técnica']))
        ->assertInertia(fn (Assert $page) => $page->where('threads.threads', []));

    $this->actingAs($this->root)
        ->get(route('team-chat.threads.show', $thread))
        ->assertInertia(fn (Assert $page) => $page->where('activeThread.partner.name', 'Adela Admin'));
});

test('the conversations export of an administrator presents root as the system administration', function () {
    $html = operationalReportHtml('conversations', $this->administrator, ['view' => 'all', 'status' => 'all']);
    $filtered = operationalReportHtml('conversations', $this->administrator, ['view' => 'all', 'status' => 'all', 'agent' => (string) $this->root->id]);

    expect($html)->toContain(ProtectedAccounts::SYSTEM_NAME)
        ->not->toContain('Cuenta Técnica')
        ->toContain('Vista: Todas las conversaciones')
        ->and($filtered)->not->toContain('Cuenta Técnica')
        ->toContain('Agente: No disponible');

    expect(operationalReportHtml('conversations', $this->root, ['view' => 'all', 'status' => 'all']))->toContain('Cuenta Técnica');
});

test('the integration API presents a root assignee as the system administration without its id', function () {
    ['plain' => $plain] = WebChatFixtures::apiToken(['connection_ids' => [$this->connection->id]]);

    $this->withToken($plain)
        ->getJson(route('api.v1.conversations.show', $this->conversation->id))
        ->assertOk()
        ->assertJsonPath('data.assigned_agent', ['id' => null, 'name' => ProtectedAccounts::SYSTEM_NAME]);
});

test('unrestricted audit entries of a root account are presented as the system administration and root is no actor option', function () {
    // Root's own actions are restricted when written; an older entry (e.g.
    // written before the account became root) stays readable.
    AuditLog::query()->create([
        'user_id' => $this->root->id,
        'actor_name' => 'Cuenta Técnica',
        'action' => AuditAction::DepartmentUpdated,
        'auditable_type' => $this->department->getMorphClass(),
        'auditable_id' => $this->department->id,
        'auditable_label' => $this->department->name,
        'restricted' => false,
        'created_at' => now(),
    ]);

    $this->actingAs($this->administrator)
        ->get(route('admin.audit.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('logs.data.0.actor.name', ProtectedAccounts::SYSTEM_NAME)
            ->where('logs.data.0.summary', fn (string $summary): bool => ! str_contains($summary, 'Cuenta Técnica'))
            ->where('actors', fn ($actors): bool => collect($actors)->pluck('id')->doesntContain($this->root->id)));

    $this->actingAs($this->administrator)
        ->get(route('admin.audit.index', ['actor' => $this->root->id]))
        ->assertInertia(fn (Assert $page) => $page->where('logs.data', []));

    expect(operationalReportHtml('audit', $this->administrator))->not->toContain('Cuenta Técnica');

    $this->actingAs($this->root)
        ->get(route('admin.audit.index'))
        ->assertInertia(fn (Assert $page) => $page->where('logs.data.0.actor.name', 'Cuenta Técnica'));
});

test('the id of the root account never travels next to the system administration name', function () {
    Message::factory()->create([
        'conversation_id' => $this->conversation->id,
        'direction' => MessageDirection::Outbound,
        'sender_user_id' => $this->root->id,
    ]);
    ConversationNote::factory()->create(['conversation_id' => $this->conversation->id, 'user_id' => $this->root->id]);
    $contact = $this->conversation->contact;
    $contact->forceFill(['created_by_user_id' => $this->root->id])->save();
    $contact->notes()->create(['author_user_id' => $this->root->id, 'body' => 'Nota técnica']);
    $this->administrator->givePermissionTo(Permission::ManageContactNotes->value);

    $list = $this->actingAs($this->administrator)->getJson(route('conversations.page', ['view' => 'all', 'status' => 'all']))->assertOk();
    $timeline = $this->actingAs($this->administrator)->getJson(route('conversations.timeline', $this->conversation))->assertOk();

    expect($list->json('data.0.assignee'))->toMatchArray(['id' => null, 'name' => ProtectedAccounts::SYSTEM_NAME])
        ->and($timeline->json('messages.0.sender'))->toBe(['id' => null, 'name' => ProtectedAccounts::SYSTEM_NAME])
        ->and($timeline->json('notes.0.author'))->toBe(['id' => null, 'name' => ProtectedAccounts::SYSTEM_NAME]);

    $this->actingAs($this->administrator)->get(route('contacts.show', $contact))
        ->assertInertia(fn (Assert $page) => $page
            ->where('contact.creator', ['id' => null, 'name' => ProtectedAccounts::SYSTEM_NAME])
            ->where('notes.0.author', ['id' => null, 'name' => ProtectedAccounts::SYSTEM_NAME])
            ->where('conversations.0.assignee', ['id' => null, 'name' => ProtectedAccounts::SYSTEM_NAME]));

    // The root authority keeps seeing its own account.
    $this->actingAs($this->root)->getJson(route('conversations.timeline', $this->conversation))
        ->assertJsonPath('messages.0.sender.id', $this->root->id)
        ->assertJsonPath('notes.0.author.id', $this->root->id);
});

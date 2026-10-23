<?php

use App\Actions\Conversations\ConversationInboxQuery;
use App\Enums\DataScope;
use App\Enums\Permission;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Department;
use App\Models\Message;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

/*
| The inbox search: by contact (phone, email or name through the FULLTEXT
| index) or by the content of the WhatsApp messages (FULLTEXT). It only ever
| narrows what the viewer may see; closed conversations need the history
| permission.
|
| InnoDB FULLTEXT indexes only see committed rows: the tests that search
| names or messages commit their data (commitForFullTextSearch()).
*/

beforeEach(function () {
    $this->sales = Department::factory()->create(['name' => 'Ventas']);
    $this->support = Department::factory()->create(['name' => 'Soporte']);
    $this->agent = User::factory()->agent()->available()->inDepartments($this->sales)->create();
    $this->colleague = User::factory()->agent()->available()->inDepartments($this->sales)->create();
});

/**
 * Ids listed by the inbox for the viewer with the given query, sorted.
 *
 * @param  array<string, string>  $query
 * @return list<int>
 */
function searchedIds(object $test, User $viewer, array $query): array
{
    $ids = $test->actingAs($viewer)
        ->get(route('conversations.index', $query))
        ->assertOk()
        ->inertiaProps('conversations.data');

    return collect($ids)->pluck('id')->sort()->values()->all();
}

function conversationOf(User $agent, Department $department, array $contact): Conversation
{
    return Conversation::factory()->inDepartment($department)->assignedTo($agent)->create([
        'contact_id' => Contact::factory()->create($contact)->id,
    ]);
}

test('the contact search finds by word prefix, phone and identity, and never by a loose digit', function () {
    $ana = conversationOf($this->agent, $this->sales, ['name' => 'Ana González', 'phone_e164' => '+525512345678']);
    $juan = conversationOf($this->agent, $this->sales, ['name' => 'Juan Pérez', 'phone_e164' => '+525598765432']);
    $alma = conversationOf($this->agent, $this->sales, ['name' => 'Alma Ruiz', 'profile_name' => 'Almita', 'phone_e164' => '+14155552671']);
    $alma->contact->identities()->create(['channel' => 'whatsapp', 'provider' => 'meta_cloud_api', 'external_id' => '14155552671']);
    // A colleague's customer with a matching name is never found by the agent.
    conversationOf($this->colleague, $this->sales, ['name' => 'Ana Gonzaga', 'phone_e164' => '+525511112222']);
    commitForFullTextSearch();

    expect(searchedIds($this, $this->agent, ['search' => 'gonz']))->toBe([$ana->id])
        ->and(searchedIds($this, $this->agent, ['search' => 'Gonzalez ana']))->toBe([$ana->id])
        ->and(searchedIds($this, $this->agent, ['search' => 'Juan 5']))->toBe([$juan->id])
        ->and(searchedIds($this, $this->agent, ['search' => '55 1234 5678']))->toBe([$ana->id])
        ->and(searchedIds($this, $this->agent, ['search' => '+52 55 9876']))->toBe([$juan->id])
        ->and(searchedIds($this, $this->agent, ['search' => '1415555']))->toBe([$alma->id])
        ->and(searchedIds($this, $this->agent, ['search' => 'Al']))->toBe([$alma->id])
        ->and(searchedIds($this, $this->agent, ['search' => 'almi']))->toBe([$alma->id])
        ->and(searchedIds($this, $this->agent, ['search' => '5678']))->toBe([])
        ->and(searchedIds($this, $this->agent, ['search' => '+"gonz*" -(ana) ~<>@']))->toBe([$ana->id]);

    $this->actingAs($this->agent)
        ->get(route('conversations.index', ['search' => 'ana', 'status' => 'all']))
        ->assertInertia(fn (Assert $page) => $page
            ->where('counts', fn ($counts) => collect($counts)->sum() === 1)
            ->where('viewCounts.mine', 1));
});

test('the message search finds conversations by content only within reach, closed ones only with history', function () {
    $supervisor = conversationUser([Permission::ViewConversations], DataScope::Departments, [$this->sales]);
    $historian = conversationUser([Permission::ViewConversations, Permission::ViewConversationHistory], DataScope::Departments, [$this->sales]);

    $open = Conversation::factory()->inDepartment($this->sales)->create();
    $closed = Conversation::factory()->inDepartment($this->sales)->closed()->create();
    $otherDepartment = Conversation::factory()->inDepartment($this->support)->create();
    $unrelated = Conversation::factory()->inDepartment($this->sales)->create();

    foreach ([$open, $closed, $otherDepartment] as $conversation) {
        Message::factory()->create(['conversation_id' => $conversation->id, 'body' => 'Necesito el reembolso de mi pedido']);
    }
    Message::factory()->create(['conversation_id' => $unrelated->id, 'body' => 'Gracias por todo']);
    commitForFullTextSearch();

    $messages = ['search_in' => 'messages', 'status' => 'all'];

    expect(searchedIds($this, $supervisor, [...$messages, 'search' => 'reemb']))->toBe([$open->id])
        ->and(searchedIds($this, $historian, [...$messages, 'search' => 'Reembolso pedido']))->toBe(collect([$open->id, $closed->id])->sort()->values()->all())
        ->and(searchedIds($this, $historian, [...$messages, 'search' => 'the reembolso']))->toBe(collect([$open->id, $closed->id])->sort()->values()->all())
        ->and(searchedIds($this, $historian, [...$messages, 'search' => 're']))->toBe([])
        // Searching the contact does not look in the messages.
        ->and(searchedIds($this, $historian, ['search' => 'reembolso', 'status' => 'all']))->toBe([]);

    $this->actingAs($historian)
        ->get(route('conversations.index', [...$messages, 'search' => 'reembolso']))
        ->assertInertia(fn (Assert $page) => $page->where('filters.search_in', 'messages'));

    // The list endpoint pages the same results.
    $this->actingAs($supervisor)
        ->getJson(route('conversations.page', [...$messages, 'search' => 'reembolso']))
        ->assertOk()
        ->assertJsonPath('data.0.id', $open->id)
        ->assertJsonCount(1, 'data');
});

test('the searches use the FULLTEXT indexes', function () {
    $admin = User::factory()->administrator()->create();
    $inbox = app(ConversationInboxQuery::class);
    $plan = function (array $input) use ($inbox, $admin): array {
        $query = $inbox->handle($admin, $inbox->filters($admin, $input))->limit(25);

        return collect(DB::select('EXPLAIN '.$query->toSql(), $query->getBindings()))->pluck('key')->filter()->values()->all();
    };

    expect($plan(['search' => 'gonz']))->toContain('contacts_name_fulltext')
        ->and($plan(['search' => 'reembolso', 'search_in' => 'messages']))->toContain('messages_body_fulltext');
});

test('the inbox tells when a message search has no word it can look for', function (string $search, string $searchIn, bool $ignored) {
    $this->actingAs($this->agent)
        ->get(route('conversations.index', ['search' => $search, 'search_in' => $searchIn]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('filters.search_ignored', $ignored));
})->with([
    'stopwords only' => ['the for', 'messages', true],
    'short words only' => ['re de', 'messages', true],
    'a searchable word' => ['for reembolso', 'messages', false],
    'contact search' => ['the', 'contact', false],
]);

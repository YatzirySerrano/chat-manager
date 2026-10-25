<?php

use App\Actions\Personalization\SetContactAlias;
use App\Enums\ConversationStatus;
use App\Jobs\DeliverOutboundMessage;
use App\Models\Connection;
use App\Models\Contact;
use App\Models\ContactAlias;
use App\Models\Conversation;
use App\Models\Department;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\WhatsAppFixtures;

/*
| Private aliases: each agent names a contact only for themselves, reached
| from a conversation they can see. The official contact data, the other
| agents' view and the recipient of messages never change.
*/

beforeEach(function () {
    $this->sales = Department::factory()->create();
    $this->agentA = User::factory()->agent()->inDepartments($this->sales)->create();
    $this->agentB = User::factory()->agent()->inDepartments($this->sales)->create();
    $this->contact = Contact::factory()->create(['name' => 'Laura Pérez', 'phone_e164' => '+527771234567']);
    $this->mine = Conversation::factory()->inDepartment($this->sales)->assignedTo($this->agentA)->create(['contact_id' => $this->contact->id]);
    $this->theirs = Conversation::factory()->inDepartment($this->sales)->assignedTo($this->agentB)->create(['contact_id' => $this->contact->id]);
});

test('an agent sets a trimmed alias that only changes what they see', function () {
    $this->actingAs($this->agentA)
        ->putJson(route('conversations.alias.update', $this->mine), ['alias' => '  Proveedora de cajas  '])
        ->assertOk()
        ->assertExactJson([
            'contact_id' => $this->contact->id,
            'alias' => 'Proveedora de cajas',
            'display_name' => 'Proveedora de cajas',
            'name' => 'Laura Pérez',
        ]);

    expect($this->contact->refresh())
        ->name->toBe('Laura Pérez')
        ->phone_e164->toBe('+527771234567')
        ->and(ContactAlias::query()->count())->toBe(1);

    $this->actingAs($this->agentA)
        ->get(route('conversations.index', ['c' => $this->mine->id]))
        ->assertInertia(fn (Assert $page) => $page
            ->where('conversation.contact.alias', 'Proveedora de cajas')
            ->where('conversation.contact.display_name', 'Proveedora de cajas')
            ->where('conversation.contact.name', 'Laura Pérez')
            ->where('details.contact.alias', 'Proveedora de cajas')
            ->where('details.contact.display_name', 'Proveedora de cajas')
            ->where('conversations.data.0.contact.display_name', 'Proveedora de cajas'));

    // The colleague attending the same contact still sees the official name.
    $this->actingAs($this->agentB)
        ->get(route('conversations.index', ['c' => $this->theirs->id]))
        ->assertInertia(fn (Assert $page) => $page
            ->where('conversation.contact.alias', null)
            ->where('conversation.contact.display_name', 'Laura Pérez')
            ->where('details.contact.alias', null)
            ->where('conversations.data.0.contact.alias', null));
});

test('an empty alias or the delete endpoint removes only the viewer\'s alias', function (string $how) {
    ContactAlias::factory()->for($this->agentA)->for($this->contact)->create(['alias' => 'Mía']);
    ContactAlias::factory()->for($this->agentB)->for($this->contact)->create(['alias' => 'De B']);

    $request = $how === 'empty'
        ? $this->actingAs($this->agentA)->putJson(route('conversations.alias.update', $this->mine), ['alias' => '   '])
        : $this->actingAs($this->agentA)->deleteJson(route('conversations.alias.destroy', $this->mine));

    $request->assertOk()->assertJsonPath('alias', null)->assertJsonPath('display_name', 'Laura Pérez');

    expect(ContactAlias::query()->pluck('alias')->all())->toBe(['De B']);
})->with(['empty', 'delete']);

test('an alias longer than 80 characters is rejected', function () {
    $this->actingAs($this->agentA)
        ->putJson(route('conversations.alias.update', $this->mine), ['alias' => str_repeat('a', 81)])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('alias');
});

test('an agent cannot set or remove an alias through a conversation they cannot see', function () {
    $hidden = Conversation::factory()->inDepartment(Department::factory()->create())->create();

    $this->actingAs($this->agentA)
        ->putJson(route('conversations.alias.update', $this->theirs), ['alias' => 'Intento'])
        ->assertNotFound();
    $this->actingAs($this->agentA)
        ->putJson(route('conversations.alias.update', $hidden), ['alias' => str_repeat('a', 200)])
        ->assertNotFound();
    $this->actingAs($this->agentA)
        ->deleteJson(route('conversations.alias.destroy', $this->theirs))
        ->assertNotFound();

    expect(ContactAlias::query()->count())->toBe(0);
});

test('an agent cannot change a colleague\'s alias by reaching the supervision endpoints', function () {
    $alias = ContactAlias::factory()->for($this->agentB)->for($this->contact)->create(['alias' => 'De B']);

    $this->actingAs($this->agentA)
        ->put(route('admin.personal-items.aliases.update', $alias), ['alias' => 'Cambiado'])
        ->assertNotFound();
    $this->actingAs($this->agentA)
        ->delete(route('admin.personal-items.aliases.destroy', $alias))
        ->assertNotFound();
    $this->actingAs($this->agentA)
        ->get(route('admin.personal-items.index'))
        ->assertForbidden();

    expect($alias->refresh()->alias)->toBe('De B');
});

test('the inbox loads the aliases of a whole page with one query', function () {
    Conversation::factory()->count(5)->inDepartment($this->sales)->assignedTo($this->agentA)->create()
        ->each(fn (Conversation $conversation) => ContactAlias::factory()->for($this->agentA)->for($conversation->contact)->create());

    $aliasQueries = 0;
    DB::listen(function (QueryExecuted $query) use (&$aliasQueries): void {
        if (str_contains($query->sql, 'contact_aliases')) {
            $aliasQueries++;
        }
    });

    $this->actingAs($this->agentA)
        ->get(route('conversations.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->has('conversations.data', 6));

    expect($aliasQueries)->toBe(1);
});

test('the inbox search finds the viewer\'s own aliases, never a colleague\'s, with LIKE wildcards taken literally', function () {
    $other = Contact::factory()->create(['name' => 'Mario Ruiz', 'phone_e164' => '+527779876543']);
    $otherConversation = Conversation::factory()->inDepartment($this->sales)->assignedTo($this->agentA)->create(['contact_id' => $other->id]);
    ContactAlias::factory()->for($this->agentA)->for($this->contact)->create(['alias' => 'Cajas 100% seguras']);
    ContactAlias::factory()->for($this->agentB)->for($other)->create(['alias' => 'Zapatero de B']);

    $found = fn (string $search): array => collect($this->actingAs($this->agentA)
        ->get(route('conversations.index', ['search' => $search]))
        ->assertOk()
        ->inertiaProps('conversations.data'))->pluck('id')->all();

    expect($found('100%'))->toBe([$this->mine->id])
        ->and($found('Zapatero'))->toBe([])
        ->and($found('%'))->toBe([$this->mine->id])
        ->and($found('_'))->toBe([])
        ->and($found('+527779876543'))->toBe([$otherConversation->id]);
});

test('an alias never changes who receives the reply', function () {
    Http::preventStrayRequests();
    config(['whatsapp.transport' => 'meta', 'services.meta.graph_version' => 'v26.0', 'whatsapp.delivery.max_attempts' => 2]);
    $this->travelTo(CarbonImmutable::parse('2026-10-22 18:00:00', 'UTC'));
    $connection = Connection::factory()->verified()->create(['phone_number_id' => '140000000000077', 'access_token' => 'EAAGaliasTestToken000000000000000000000000000000000000']);
    $messagesUrl = 'https://graph.facebook.com/v26.0/140000000000077/messages';
    Http::fake([$messagesUrl => Http::response(['messaging_product' => 'whatsapp', 'contacts' => [['input' => '527771234500', 'wa_id' => '5217771234500']], 'messages' => [['id' => 'wamid.alias.1']]])]);

    $conversation = receiveWhatsapp($connection, '5217771234500', 'Hola')->conversation;
    $conversation->forceFill(['status' => ConversationStatus::Open, 'department_id' => $this->sales->id, 'assigned_user_id' => $this->agentA->id, 'last_inbound_at' => now()->subMinutes(5)])->save();
    $before = $conversation->contact->refresh()->only(['name', 'profile_name', 'phone_e164']);

    $this->actingAs($this->agentA)
        ->putJson(route('conversations.alias.update', $conversation), ['alias' => 'Cliente de las cajas'])
        ->assertOk();

    $message = WhatsAppFixtures::outbound($conversation, ['body' => 'Con gusto', 'sender_user_id' => $this->agentA->id]);
    app()->call([new DeliverOutboundMessage($message->id), 'handle']);

    Http::assertSent(fn (Request $request): bool => $request->url() === $messagesUrl && $request['to'] === '527771234500');
    expect($conversation->contact->refresh()->only(['name', 'profile_name', 'phone_e164']))->toBe($before)
        ->and($conversation->contact->identities()->count())->toBe(1);
});

test('a double submit racing on the same alias ends as an update', function () {
    // The competing request inserts its row between the lookup and the insert.
    ContactAlias::creating(function (ContactAlias $alias): void {
        DB::table('contact_aliases')->insert(['user_id' => $alias->user_id, 'contact_id' => $alias->contact_id, 'alias' => 'Primero', 'created_at' => now(), 'updated_at' => now()]);
    });

    $record = app(SetContactAlias::class)->handle($this->agentA, $this->contact, 'Segundo');

    expect(ContactAlias::query()->where('user_id', $this->agentA->id)->where('contact_id', $this->contact->id)->pluck('alias')->all())->toBe(['Segundo'])
        ->and($record->exists)->toBeTrue();
});

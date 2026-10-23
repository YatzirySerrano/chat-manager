<?php

use App\Enums\AuditAction;
use App\Enums\DataScope;
use App\Enums\Permission;
use App\Events\Conversations\ConversationChanged;
use App\Models\AuditLog;
use App\Models\Conversation;
use App\Models\Department;
use App\Models\Tag;
use App\Models\User;
use Illuminate\Support\Facades\Event;
use Inertia\Testing\AssertableInertia as Assert;

/*
| Tags of a conversation, edited from the inbox details panel by its
| responsible agent or by whoever manages conversations, with the tag
| assignment permission. Every change is audited and signalled.
*/

beforeEach(function () {
    $this->department = Department::factory()->create();
    $this->tagger = conversationUser([
        Permission::ViewConversations,
        Permission::ReplyConversations,
        Permission::AssignTags,
    ], departments: [$this->department]);
    $this->conversation = Conversation::factory()->inDepartment($this->department)->assignedTo($this->tagger)->create();
    $this->urgent = Tag::factory()->create(['name' => 'Urgente']);
    $this->billing = Tag::factory()->create(['name' => 'Facturación']);
});

test('the responsible agent replaces the tags and the change is audited without touching the conversation', function () {
    Event::fake([ConversationChanged::class]);
    $version = $this->conversation->version;

    $this->actingAs($this->tagger)
        ->put(route('conversations.tags.update', $this->conversation), ['tag_ids' => [$this->urgent->id, $this->billing->id]])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $this->actingAs($this->tagger)
        ->put(route('conversations.tags.update', $this->conversation), ['tag_ids' => [$this->billing->id]])
        ->assertSessionHasNoErrors();

    expect($this->conversation->tags()->pluck('tags.id')->all())->toBe([$this->billing->id])
        ->and($this->conversation->refresh()->version)->toBe($version);

    $audits = AuditLog::query()->where('action', AuditAction::ConversationTagsChanged->value)->orderBy('id')->get();
    expect($audits)->toHaveCount(2)
        ->and($audits[1]->properties)->toMatchArray(['added' => [], 'removed' => ['Urgente']]);
    Event::assertDispatched(ConversationChanged::class, fn (ConversationChanged $event) => $event->reason === 'tags');
});

test('saving the same tags writes nothing and says so', function () {
    $this->conversation->tags()->attach($this->urgent->id, ['tagged_by_user_id' => $this->tagger->id, 'created_at' => now()]);

    $this->actingAs($this->tagger)
        ->put(route('conversations.tags.update', $this->conversation), ['tag_ids' => [$this->urgent->id]])
        ->assertSessionHasNoErrors()
        ->assertInertiaFlash('toast', ['type' => 'info', 'message' => 'No había cambios por guardar.']);

    expect(AuditLog::query()->where('action', AuditAction::ConversationTagsChanged->value)->count())->toBe(0);
});

test('a deleted tag cannot be newly attached but may stay where it already was', function () {
    $old = Tag::factory()->inactive()->create(['name' => 'Antigua']);
    $this->conversation->tags()->attach($old->id, ['tagged_by_user_id' => $this->tagger->id, 'created_at' => now()]);
    $deleted = Tag::factory()->inactive()->create(['name' => 'Retirada']);

    $this->actingAs($this->tagger)
        ->put(route('conversations.tags.update', $this->conversation), ['tag_ids' => [$old->id, $deleted->id]])
        ->assertSessionHasErrors(['tag_ids' => 'La etiqueta «Retirada» está eliminada y no puede asignarse.']);

    $this->actingAs($this->tagger)
        ->put(route('conversations.tags.update', $this->conversation), ['tag_ids' => [$old->id, $this->urgent->id]])
        ->assertSessionHasNoErrors();

    expect($this->conversation->tags()->pluck('tags.id')->sort()->values()->all())->toBe(collect([$old->id, $this->urgent->id])->sort()->values()->all());
});

test('tagging requires the permission and responsibility or management', function () {
    $withoutPermission = conversationUser([Permission::ViewConversations, Permission::ReplyConversations], departments: [$this->department]);
    $own = Conversation::factory()->inDepartment($this->department)->assignedTo($withoutPermission)->create();

    $this->actingAs($withoutPermission)
        ->put(route('conversations.tags.update', $own), ['tag_ids' => [$this->urgent->id]])
        ->assertForbidden();

    $observer = conversationUser([Permission::ViewConversations, Permission::AssignTags], DataScope::Global);

    $this->actingAs($observer)
        ->put(route('conversations.tags.update', $this->conversation), ['tag_ids' => [$this->urgent->id]])
        ->assertForbidden();

    $manager = conversationUser([Permission::ViewConversations, Permission::AssignConversations, Permission::AssignTags], DataScope::Global);

    $this->actingAs($manager)
        ->put(route('conversations.tags.update', $this->conversation), ['tag_ids' => [$this->urgent->id]])
        ->assertSessionHasNoErrors();

    expect($own->tags()->count())->toBe(0)
        ->and($this->conversation->tags()->count())->toBe(1);
});

test('a conversation outside the actor\'s reach cannot be tagged', function () {
    $colleague = conversationUser([Permission::ViewConversations, Permission::ReplyConversations, Permission::AssignTags], departments: [$this->department]);

    $this->actingAs($colleague)
        ->put(route('conversations.tags.update', $this->conversation), ['tag_ids' => [$this->urgent->id]])
        ->assertNotFound();

    expect($this->conversation->tags()->count())->toBe(0);
});

test('the details panel offers the active catalog only to people who can tag', function () {
    $this->actingAs($this->tagger)
        ->get(route('conversations.index', ['c' => $this->conversation->id]))
        ->assertInertia(fn (Assert $page) => $page
            ->where('conversation.can.tag', true)
            ->has('details.availableTags', 2));

    $agent = conversationUser([Permission::ViewConversations, Permission::ReplyConversations], departments: [$this->department]);
    $own = Conversation::factory()->inDepartment($this->department)->assignedTo($agent)->create();

    $this->actingAs($agent)
        ->get(route('conversations.index', ['c' => $own->id]))
        ->assertInertia(fn (Assert $page) => $page
            ->where('conversation.can.tag', false)
            ->where('details.availableTags', []));
});

test('an agent with the default role tags the conversations it attends but never manages the catalog', function () {
    $agent = User::factory()->agent()->inDepartments($this->department)->create();
    $own = Conversation::factory()->inDepartment($this->department)->assignedTo($agent)->create();

    $this->actingAs($agent)
        ->put(route('conversations.tags.update', $own), ['tag_ids' => [$this->urgent->id]])
        ->assertSessionHasNoErrors();
    $this->actingAs($agent)
        ->put(route('conversations.tags.update', $this->conversation), ['tag_ids' => [$this->urgent->id]])
        ->assertNotFound();

    expect($own->tags()->pluck('tags.id')->all())->toBe([$this->urgent->id])
        ->and($this->conversation->tags()->exists())->toBeFalse()
        ->and($agent->can('create', Tag::class))->toBeFalse()
        ->and($agent->can('update', $this->urgent))->toBeFalse();
});

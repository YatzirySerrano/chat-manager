<?php

use App\Enums\AuditAction;
use App\Enums\ChatAppearancePreset;
use App\Enums\DataScope;
use App\Enums\Permission;
use App\Models\AuditLog;
use App\Models\ChatAppearance;
use App\Models\Conversation;
use App\Models\Department;
use App\Models\TeamChat\TeamThread;
use App\Models\User;
use App\Support\Color\Contrast;
use Illuminate\Database\QueryException;
use Inertia\Testing\AssertableInertia as Assert;

/*
| Personal chat colors in the internal chat: per thread or as the user's
| default (shared with customer conversations), only for current
| participants, only ever applied to their owner's own view, and reachable
| by an authorized supervisor without revealing the thread.
*/

beforeEach(function () {
    $this->sales = Department::factory()->create();
    $this->ana = User::factory()->agent()->inDepartments($this->sales)->create(['name' => 'Ana']);
    $this->beto = User::factory()->agent()->inDepartments($this->sales)->create(['name' => 'Beto']);
    $this->ciro = User::factory()->agent()->inDepartments($this->sales)->create(['name' => 'Ciro']);
    $this->thread = TeamThread::factory()->withParticipants([$this->beto], [$this->ana])->create(['name' => 'Plan secreto']);
    $this->otherThread = TeamThread::factory()->direct($this->ana, $this->ciro)->create();
    $this->ocean = ChatAppearancePreset::Ocean->colors();
    $this->forest = ChatAppearancePreset::Forest->colors();
});

function threadAppearanceOf(object $test, User $viewer, TeamThread $thread): array
{
    $appearance = null;

    $test->actingAs($viewer)
        ->get(route('team-chat.threads.show', $thread))
        ->assertOk()
        ->assertInertia(function (Assert $page) use (&$appearance) {
            $appearance = $page->toArray()['props']['activeThread']['appearance'];

            return $page;
        });

    return $appearance;
}

test('a thread without personal colors uses the platform theme, then the user\'s default', function () {
    expect(threadAppearanceOf($this, $this->ana, $this->thread))
        ->source->toBe('system')
        ->colors->toBeNull()
        ->presets->toHaveCount(count(ChatAppearancePreset::cases()));

    ChatAppearance::factory()->for($this->ana)->create(['preset' => 'ocean', 'colors' => $this->ocean]);

    expect(threadAppearanceOf($this, $this->ana, $this->thread))
        ->source->toBe('default')
        ->preset->toBe('ocean')
        ->colors->toBe($this->ocean)
        ->conversation->toBeNull();
});

test('a thread choice overrides the default for that thread only and persists for its owner only', function () {
    ChatAppearance::factory()->for($this->ana)->create(['preset' => 'ocean', 'colors' => $this->ocean]);
    $customerChat = Conversation::factory()->inDepartment($this->sales)->assignedTo($this->ana)->create();

    $this->actingAs($this->ana)
        ->putJson(route('team-chat.threads.appearance.update', $this->thread), ['scope' => 'conversation', 'preset' => 'forest', 'colors' => $this->forest])
        ->assertOk()
        ->assertJsonPath('appearance.source', 'conversation')
        ->assertJsonPath('appearance.preset', 'forest')
        ->assertJsonPath('appearance.colors', $this->forest)
        ->assertJsonPath('appearance.default.preset', 'ocean')
        ->assertJsonPath('notice', null);

    expect(ChatAppearance::query()->where('user_id', $this->ana->id)->where('team_thread_id', $this->thread->id)->sole())
        ->conversation_id->toBeNull()
        ->isDefault()->toBeFalse();

    // A new request (reload, another session or device) reads it from the server.
    expect(threadAppearanceOf($this, $this->ana, $this->thread))->source->toBe('conversation')->preset->toBe('forest')
        ->and(threadAppearanceOf($this, $this->ana, $this->otherThread))->source->toBe('default')->preset->toBe('ocean')
        // Beto, in the same thread, keeps his own (none).
        ->and(threadAppearanceOf($this, $this->beto, $this->thread))->source->toBe('system')->colors->toBeNull()
        // A thread row is never taken for the default of a customer chat.
        ->and(ChatAppearance::query()->defaults()->where('user_id', $this->ana->id)->sole()->preset)->toBe('ocean');

    $this->actingAs($this->ana)
        ->get(route('conversations.index', ['c' => $customerChat->id]))
        ->assertInertia(fn (Assert $page) => $page
            ->where('conversation.appearance.source', 'default')
            ->where('conversation.appearance.preset', 'ocean'));
});

test('the default saved from a thread applies to every chat, and restoring goes back level by level', function () {
    $this->actingAs($this->ana)
        ->putJson(route('team-chat.threads.appearance.update', $this->thread), ['scope' => 'all', 'preset' => 'ocean', 'colors' => $this->ocean])
        ->assertOk()
        ->assertJsonPath('appearance.source', 'default');
    $this->actingAs($this->ana)
        ->putJson(route('team-chat.threads.appearance.update', $this->thread), ['scope' => 'conversation', 'preset' => 'forest', 'colors' => $this->forest])
        ->assertOk();

    expect(threadAppearanceOf($this, $this->ana, $this->otherThread))->source->toBe('default');

    $this->actingAs($this->ana)
        ->deleteJson(route('team-chat.threads.appearance.destroy', ['thread' => $this->thread, 'scope' => 'conversation']))
        ->assertOk()
        ->assertJsonPath('appearance.source', 'default')
        ->assertJsonPath('appearance.preset', 'ocean');
    $this->actingAs($this->ana)
        ->deleteJson(route('team-chat.threads.appearance.destroy', ['thread' => $this->thread, 'scope' => 'all']))
        ->assertOk()
        ->assertJsonPath('appearance.source', 'system');

    expect(ChatAppearance::query()->count())->toBe(0);
});

test('an unreadable text color is made readable and invalid colors are rejected', function () {
    $colors = $this->ocean;
    $colors['light']['sent_text'] = '#D0E4F5';
    $colors['dark']['received_text'] = '#16283A';

    $saved = $this->actingAs($this->ana)
        ->putJson(route('team-chat.threads.appearance.update', $this->thread), ['scope' => 'conversation', 'preset' => 'ocean', 'colors' => $colors])
        ->assertOk()
        ->assertJsonPath('notice', 'Ajustamos el color del texto para que sea legible sobre la burbuja (contraste mínimo 4.5:1).')
        ->assertJsonPath('appearance.preset', null)
        ->json('appearance.colors');

    expect(Contrast::isReadable($saved['light']['sent_text'], $saved['light']['sent_bg']))->toBeTrue()
        ->and(Contrast::isReadable($saved['dark']['received_text'], $saved['dark']['received_bg']))->toBeTrue();

    $this->actingAs($this->ana)
        ->putJson(route('team-chat.threads.appearance.update', $this->thread), ['scope' => 'conversation', 'colors' => array_replace_recursive($this->ocean, ['dark' => ['sent_bg' => 'red']])])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('colors.dark.sent_bg');
    $this->actingAs($this->ana)
        ->putJson(route('team-chat.threads.appearance.update', $this->thread), ['colors' => $this->ocean])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('scope');
});

test('only current participants personalize a thread, and only their own colors', function () {
    $anaRow = ChatAppearance::factory()->for($this->ana)->forTeamThread($this->thread)->create(['preset' => 'ocean', 'colors' => $this->ocean]);

    // Ciro is not in the thread: it answers as a missing one and writes nothing.
    $this->actingAs($this->ciro)
        ->putJson(route('team-chat.threads.appearance.update', $this->thread), ['scope' => 'conversation', 'colors' => $this->forest])
        ->assertNotFound();
    $this->actingAs($this->ciro)
        ->deleteJson(route('team-chat.threads.appearance.destroy', ['thread' => $this->thread, 'scope' => 'conversation']))
        ->assertNotFound();
    $this->actingAs($this->ciro)
        ->putJson(route('team-chat.threads.appearance.update', 999_999), ['scope' => 'conversation', 'colors' => $this->forest])
        ->assertNotFound();

    // Beto participates: he changes and restores his own row, never Ana's.
    $this->actingAs($this->beto)
        ->putJson(route('team-chat.threads.appearance.update', $this->thread), ['scope' => 'conversation', 'preset' => 'forest', 'colors' => $this->forest])
        ->assertOk()
        ->assertJsonPath('appearance.preset', 'forest');
    $this->actingAs($this->beto)
        ->deleteJson(route('team-chat.threads.appearance.destroy', ['thread' => $this->thread, 'scope' => 'conversation']))
        ->assertOk()
        ->assertJsonPath('appearance.source', 'system');

    expect($anaRow->refresh())->preset->toBe('ocean')->colors->toBe($this->ocean)
        ->and(ChatAppearance::query()->where('user_id', $this->ciro->id)->exists())->toBeFalse()
        ->and(ChatAppearance::query()->where('user_id', $this->beto->id)->exists())->toBeFalse();

    // Someone who left the group can no longer change it.
    $this->thread->participationOf($this->beto)->forceFill(['left_at' => now()])->save();

    $this->actingAs($this->beto)
        ->putJson(route('team-chat.threads.appearance.update', $this->thread), ['scope' => 'conversation', 'colors' => $this->forest])
        ->assertNotFound();
});

test('a supervisor with view-personal sees a thread appearance without the thread, but cannot change it', function () {
    $row = ChatAppearance::factory()->for($this->ana)->forTeamThread($this->thread)->create(['preset' => 'ocean', 'colors' => $this->ocean]);
    $viewer = conversationUser([Permission::ViewPersonalItems], DataScope::Global);

    $this->actingAs($viewer)
        ->get(route('admin.personal-items.index', ['type' => 'appearance']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('items.data', 1)
            ->where('items.data.0.owner.name', 'Ana')
            ->where('items.data.0.scope_label', 'Chat interno #'.$this->thread->id)
            ->where('items.data.0.preset_label', 'Océano')
            ->where('items.data.0.can', ['update' => false, 'delete' => false]))
        ->assertDontSee('Plan secreto');

    $this->actingAs($viewer)
        ->put(route('admin.personal-items.appearances.update', $row), ['preset' => 'forest', 'colors' => $this->forest])
        ->assertForbidden();
    $this->actingAs($viewer)
        ->delete(route('admin.personal-items.appearances.destroy', $row))
        ->assertForbidden();

    // Supervision never opens the thread itself.
    $this->actingAs($viewer)
        ->putJson(route('team-chat.threads.appearance.update', $this->thread), ['scope' => 'conversation', 'colors' => $this->forest])
        ->assertNotFound();

    expect($row->refresh()->preset)->toBe('ocean');
});

test('a supervisor with manage-personal changes and restores a thread appearance, audited', function () {
    $admin = User::factory()->administrator()->create();
    $row = ChatAppearance::factory()->for($this->ana)->forTeamThread($this->thread)->create(['preset' => 'ocean', 'colors' => $this->ocean]);

    $this->actingAs($admin)
        ->put(route('admin.personal-items.appearances.update', $row), ['preset' => 'forest', 'colors' => $this->forest])
        ->assertRedirect();

    // The same row changed: it still belongs to the thread, no default appeared.
    expect(ChatAppearance::query()->sole())
        ->id->toBe($row->id)
        ->team_thread_id->toBe($this->thread->id)
        ->preset->toBe('forest');

    $this->actingAs($admin)
        ->delete(route('admin.personal-items.appearances.destroy', $row))
        ->assertRedirect();

    expect(ChatAppearance::query()->count())->toBe(0)
        ->and(AuditLog::query()->orderBy('id')->pluck('action')->all())->toBe([AuditAction::SupervisionPersonalItemUpdated, AuditAction::SupervisionPersonalItemDeleted])
        ->and(AuditLog::query()->latest('id')->first()->properties)
        ->toMatchArray(['owner_user_id' => $this->ana->id, 'item_type' => 'chat_appearance', 'item_id' => $row->id, 'team_thread_id' => $this->thread->id]);
});

test('a row never points at a conversation and a thread at once', function () {
    $conversation = Conversation::factory()->inDepartment($this->sales)->assignedTo($this->ana)->create();

    expect(fn () => ChatAppearance::factory()->for($this->ana)->forConversation($conversation)->forTeamThread($this->thread)->create())
        ->toThrow(QueryException::class, 'chat_appearances_single_chat_check');

    ChatAppearance::factory()->for($this->ana)->forConversation($conversation)->create();
    ChatAppearance::factory()->for($this->ana)->forTeamThread($this->thread)->create();
    ChatAppearance::factory()->for($this->ana)->create();

    expect(ChatAppearance::query()->where('user_id', $this->ana->id)->count())->toBe(3);
});

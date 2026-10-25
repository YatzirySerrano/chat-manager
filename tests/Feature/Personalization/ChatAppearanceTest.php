<?php

use App\Enums\ChatAppearancePreset;
use App\Models\ChatAppearance;
use App\Models\Conversation;
use App\Models\Department;
use App\Models\User;
use App\Services\Personalization\ChatAppearances;
use App\Support\Color\Contrast;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

/*
| Personal chat colors: per conversation or as the user's default, readable
| (WCAG AA) and only ever applied to their owner's own view.
*/

beforeEach(function () {
    $this->sales = Department::factory()->create();
    $this->agentA = User::factory()->agent()->inDepartments($this->sales)->create();
    $this->agentB = User::factory()->agent()->inDepartments($this->sales)->create();
    $this->mine = Conversation::factory()->inDepartment($this->sales)->assignedTo($this->agentA)->create();
    $this->other = Conversation::factory()->inDepartment($this->sales)->assignedTo($this->agentA)->create();
    $this->theirs = Conversation::factory()->inDepartment($this->sales)->assignedTo($this->agentB)->create();
    $this->ocean = ChatAppearancePreset::Ocean->colors();
    $this->forest = ChatAppearancePreset::Forest->colors();
});

function appearanceOf(object $test, User $viewer, Conversation $conversation): array
{
    $appearance = null;

    $test->actingAs($viewer)
        ->get(route('conversations.index', ['c' => $conversation->id]))
        ->assertOk()
        ->assertInertia(function (Assert $page) use (&$appearance) {
            $appearance = $page->toArray()['props']['conversation']['appearance'];

            return $page;
        });

    return $appearance;
}

test('every preset is readable in light and dark mode', function (ChatAppearancePreset $preset) {
    foreach ($preset->colors() as $set) {
        expect(Contrast::isReadable($set['sent_text'], $set['sent_bg']))->toBeTrue()
            ->and(Contrast::isReadable($set['received_text'], $set['received_bg']))->toBeTrue();
    }
})->with(ChatAppearancePreset::cases());

test('the contrast ratio follows WCAG 2.1', function () {
    expect(Contrast::ratio('#000000', '#FFFFFF'))->toBe(21.0)
        ->and(round(Contrast::ratio('#777777', '#FFFFFF'), 2))->toBe(4.48)
        ->and(Contrast::readableTextOn('#FFFFFF'))->toBe('#111111')
        ->and(Contrast::readableTextOn('#000000'))->toBe('#FFFFFF')
        // Near-black falls short on this gray, white reaches only 4.40:1: pure black.
        ->and(Contrast::readableTextOn('#7A7A7A'))->toBe('#000000');
});

test('without personal colors the chat uses the platform theme', function () {
    expect(appearanceOf($this, $this->agentA, $this->mine))
        ->source->toBe('system')
        ->colors->toBeNull()
        ->default->toBeNull()
        ->conversation->toBeNull()
        ->presets->toHaveCount(count(ChatAppearancePreset::cases()));
});

test('a conversation choice overrides the default, which applies everywhere else', function () {
    $this->actingAs($this->agentA)
        ->putJson(route('conversations.appearance.update', $this->mine), ['scope' => 'all', 'preset' => 'ocean', 'colors' => $this->ocean])
        ->assertOk()
        ->assertJsonPath('appearance.source', 'default')
        ->assertJsonPath('appearance.preset', 'ocean')
        ->assertJsonPath('notice', null);

    $this->actingAs($this->agentA)
        ->putJson(route('conversations.appearance.update', $this->mine), ['scope' => 'conversation', 'preset' => 'forest', 'colors' => $this->forest])
        ->assertOk()
        ->assertJsonPath('appearance.source', 'conversation')
        ->assertJsonPath('appearance.colors', $this->forest)
        ->assertJsonPath('appearance.default.preset', 'ocean');

    expect(appearanceOf($this, $this->agentA, $this->mine))->source->toBe('conversation')->preset->toBe('forest')
        ->and(appearanceOf($this, $this->agentA, $this->other))->source->toBe('default')->preset->toBe('ocean')
        // Nobody else is affected.
        ->and(appearanceOf($this, $this->agentB, $this->theirs))->source->toBe('system');

    // Restoring the conversation goes back to the default; restoring all, to the theme.
    $this->actingAs($this->agentA)
        ->deleteJson(route('conversations.appearance.destroy', ['conversation' => $this->mine, 'scope' => 'conversation']))
        ->assertOk()
        ->assertJsonPath('appearance.source', 'default');
    $this->actingAs($this->agentA)
        ->deleteJson(route('conversations.appearance.destroy', ['conversation' => $this->mine, 'scope' => 'all']))
        ->assertOk()
        ->assertJsonPath('appearance.source', 'system');

    expect(ChatAppearance::query()->count())->toBe(0);
});

test('an unreadable text color is replaced by a readable one and the user is told', function () {
    $colors = $this->ocean;
    $colors['light']['sent_text'] = '#D0E4F5';
    $colors['dark']['received_text'] = '#1A2C3D';

    $response = $this->actingAs($this->agentA)
        ->putJson(route('conversations.appearance.update', $this->mine), ['scope' => 'conversation', 'preset' => 'ocean', 'colors' => $colors])
        ->assertOk()
        ->assertJsonPath('notice', 'Ajustamos el color del texto para que sea legible sobre la burbuja (contraste mínimo 4.5:1).')
        // Modified colors are no longer the preset.
        ->assertJsonPath('appearance.preset', null);

    $saved = $response->json('appearance.colors');

    expect($saved['light']['sent_text'])->toBe('#111111')
        ->and($saved['dark']['received_text'])->toBe('#FFFFFF')
        ->and($saved['light']['sent_bg'])->toBe($this->ocean['light']['sent_bg'])
        ->and(Contrast::isReadable($saved['light']['sent_text'], $saved['light']['sent_bg']))->toBeTrue();
});

test('colors must be complete #RRGGBB values', function (array $override, string $field) {
    $colors = array_replace_recursive($this->ocean, $override);

    $this->actingAs($this->agentA)
        ->putJson(route('conversations.appearance.update', $this->mine), ['scope' => 'conversation', 'colors' => $colors])
        ->assertUnprocessable()
        ->assertJsonValidationErrors($field);
})->with([
    'not hex' => [['light' => ['sent_bg' => 'red']], 'colors.light.sent_bg'],
    'short hex' => [['dark' => ['chat_bg' => '#FFF']], 'colors.dark.chat_bg'],
    'css injection' => [['light' => ['received_bg' => '#FFFFFF;background:url(x)']], 'colors.light.received_bg'],
]);

test('an agent cannot change appearance through a conversation they cannot see, nor someone else\'s row', function () {
    $foreign = ChatAppearance::factory()->for($this->agentB)->create();

    $this->actingAs($this->agentA)
        ->putJson(route('conversations.appearance.update', $this->theirs), ['scope' => 'conversation', 'colors' => $this->ocean])
        ->assertNotFound();
    $this->actingAs($this->agentA)
        ->deleteJson(route('conversations.appearance.destroy', ['conversation' => $this->theirs, 'scope' => 'all']))
        ->assertNotFound();
    $this->actingAs($this->agentA)
        ->put(route('admin.personal-items.appearances.update', $foreign), ['colors' => $this->forest])
        ->assertNotFound();
    $this->actingAs($this->agentA)
        ->delete(route('admin.personal-items.appearances.destroy', $foreign))
        ->assertNotFound();

    expect(ChatAppearance::query()->where('user_id', $this->agentA->id)->exists())->toBeFalse()
        ->and($foreign->refresh()->colors)->toBe(ChatAppearance::factory()->make()->colors);
});

test('a double submit racing on the same appearance ends as an update', function () {
    // The competing request inserts its row between the lookup and the insert.
    ChatAppearance::creating(function (ChatAppearance $appearance): void {
        DB::table('chat_appearances')->insert(['user_id' => $appearance->user_id, 'conversation_id' => $appearance->conversation_id, 'preset' => 'ocean', 'colors' => json_encode($this->ocean), 'created_at' => now(), 'updated_at' => now()]);
    });

    ['appearance' => $appearance] = app(ChatAppearances::class)->save($this->agentA, $this->mine, ChatAppearancePreset::Forest, $this->forest);

    expect(ChatAppearance::query()->where('user_id', $this->agentA->id)->where('conversation_id', $this->mine->id)->pluck('preset')->all())->toBe(['forest'])
        ->and($appearance->exists)->toBeTrue();
});

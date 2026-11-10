<?php

use App\Enums\ChatAppearancePreset;
use App\Events\Broadcasts\RealtimeSignal;
use App\Events\TeamChat\TeamThreadRead;
use App\Models\ChatAppearance;
use App\Models\Department;
use App\Models\TeamChat\TeamMessage;
use App\Models\TeamChat\TeamThread;
use App\Models\User;
use Illuminate\Support\Facades\Event;
use Inertia\Testing\AssertableInertia as Assert;

/*
| The internal chat preview (long press / right click on a thread) shows the
| latest messages without opening the chat: the viewer's read position,
| unread counters and typing indicators stay as they were, and only current
| participants can preview.
*/

beforeEach(function () {
    $department = Department::factory()->create();
    $this->ana = User::factory()->agent()->inDepartments($department)->create(['name' => 'Ana']);
    $this->beto = User::factory()->agent()->inDepartments($department)->create(['name' => 'Beto']);
    $this->thread = TeamThread::factory()->direct($this->ana, $this->beto)->create();
});

test('the preview lists the latest messages with their author and offers no actions', function () {
    TeamMessage::factory()->in($this->thread, $this->beto)->create(['body' => '¿Revisas el caso 12?']);
    TeamMessage::factory()->in($this->thread, $this->ana)->create(['body' => 'Ya voy.']);

    $this->actingAs($this->ana)->getJson(route('team-chat.threads.preview', $this->thread))
        ->assertOk()
        ->assertJsonPath('has_more', false)
        ->assertJsonPath('data.0.body', '¿Revisas el caso 12?')
        ->assertJsonPath('data.0.is_mine', false)
        ->assertJsonPath('data.1.body', 'Ya voy.')
        ->assertJsonPath('data.1.is_mine', true)
        ->assertJsonPath('data.1.can.edit', false);
});

test('previewing keeps the read position, the counters and signals nothing', function () {
    TeamMessage::factory()->count(3)->in($this->thread, $this->beto)->create();
    $readBefore = $this->thread->participationOf($this->ana)->last_read_message_id;
    Event::fake([TeamThreadRead::class, RealtimeSignal::class]);

    $writes = databaseWritesDuring(fn () => $this->actingAs($this->ana)
        ->getJson(route('team-chat.threads.preview', $this->thread))
        ->assertOk());

    expect($writes)->toBe([])
        ->and($this->thread->participationOf($this->ana)->last_read_message_id)->toBe($readBefore);
    Event::assertNotDispatched(TeamThreadRead::class);
    Event::assertNotDispatched(RealtimeSignal::class);

    $this->actingAs($this->ana)->get(route('team-chat.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('unreadTotal', 3)
            ->where('threads.threads.0.unread', 3));
});

test('reading the chat afterwards keeps working as before', function () {
    $last = TeamMessage::factory()->in($this->thread, $this->beto)->create();
    $this->actingAs($this->ana)->getJson(route('team-chat.threads.preview', $this->thread))->assertOk();

    $this->actingAs($this->ana)
        ->postJson(route('team-chat.threads.read', $this->thread), ['message_id' => $last->id])
        ->assertOk()
        ->assertJsonPath('unread_total', 0);
});

test('someone who is not a participant cannot preview the chat', function () {
    TeamMessage::factory()->in($this->thread, $this->beto)->create(['body' => 'Solo para Ana']);
    $outsider = User::factory()->administrator()->create();

    $this->actingAs($outsider)->getJson(route('team-chat.threads.preview', $this->thread))
        ->assertNotFound()
        ->assertDontSee('Solo para Ana');
});

test('the preview shows the viewer\'s own colors for the thread, else their default, else the theme', function () {
    $this->actingAs($this->ana)->getJson(route('team-chat.threads.preview', $this->thread))
        ->assertJsonPath('appearance', ['source' => 'system', 'colors' => null]);

    ChatAppearance::factory()->for($this->ana)->create(['colors' => ChatAppearancePreset::Ocean->colors()]);
    $this->actingAs($this->ana)->getJson(route('team-chat.threads.preview', $this->thread))
        ->assertJsonPath('appearance', ['source' => 'default', 'colors' => ChatAppearancePreset::Ocean->colors()]);

    ChatAppearance::factory()->for($this->ana)->forTeamThread()->create(['colors' => ChatAppearancePreset::Forest->colors()]);
    ChatAppearance::factory()->for($this->ana)->forTeamThread($this->thread)->create(['colors' => ChatAppearancePreset::Sand->colors()]);
    $this->actingAs($this->ana)->getJson(route('team-chat.threads.preview', $this->thread))
        ->assertJsonPath('appearance', ['source' => 'conversation', 'colors' => ChatAppearancePreset::Sand->colors()]);
});

test('another participant\'s colors for the thread never reach the viewer\'s preview', function () {
    ChatAppearance::factory()->for($this->beto)->create(['colors' => ChatAppearancePreset::Ocean->colors()]);
    ChatAppearance::factory()->for($this->beto)->forTeamThread($this->thread)->create(['colors' => ChatAppearancePreset::Forest->colors()]);

    $this->actingAs($this->ana)->getJson(route('team-chat.threads.preview', $this->thread))
        ->assertOk()
        ->assertJsonPath('appearance', ['source' => 'system', 'colors' => null]);
});

test('previewing with personal colors creates no appearance and keeps the read position', function () {
    TeamMessage::factory()->count(2)->in($this->thread, $this->beto)->create();
    $readBefore = $this->thread->participationOf($this->ana)->last_read_message_id;
    $default = ChatAppearance::factory()->for($this->ana)->create(['colors' => ChatAppearancePreset::Ocean->colors()]);

    $writes = databaseWritesDuring(fn () => $this->actingAs($this->ana)
        ->getJson(route('team-chat.threads.preview', $this->thread))
        ->assertJsonPath('appearance.source', 'default'));

    expect($writes)->toBe([])
        ->and(ChatAppearance::query()->sole()->is($default))->toBeTrue()
        ->and($this->thread->participationOf($this->ana)->last_read_message_id)->toBe($readBefore);
});

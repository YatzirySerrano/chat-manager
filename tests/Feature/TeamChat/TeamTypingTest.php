<?php

use App\Events\Broadcasts\RealtimeSignal;
use App\Models\Department;
use App\Models\TeamChat\TeamMessage;
use App\Models\TeamChat\TeamThread;
use App\Models\User;
use Illuminate\Broadcasting\Broadcasters\Broadcaster;
use Illuminate\Broadcasting\BroadcastException;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Event;

/*
| "Typing…" is an ephemeral signal on the thread channel: the author is set
| by the server, it is authorized on every call and nothing is stored.
*/

beforeEach(function () {
    $department = Department::factory()->create();
    $this->ana = User::factory()->agent()->inDepartments($department)->create();
    $this->beto = User::factory()->agent()->inDepartments($department)->create();
    $this->group = TeamThread::factory()->withParticipants([$this->beto], [$this->ana])->create(['name' => 'Soporte']);
});

test('a participant announces they are typing without storing anything', function () {
    Event::fake([RealtimeSignal::class]);

    $this->actingAs($this->ana)->postJson(route('team-chat.threads.typing', $this->group), ['user_id' => $this->beto->id])->assertNoContent();

    Event::assertDispatched(RealtimeSignal::class, fn (RealtimeSignal $signal) => $signal->channels === ["team-chat.threads.{$this->group->id}"]
        && $signal->event === 'typing'
        && $signal->payload === ['user_id' => $this->ana->id]);

    expect(TeamMessage::query()->count())->toBe(0);
});

test('someone removed or a read-only group cannot announce typing', function () {
    Event::fake([RealtimeSignal::class]);

    $this->group->participations()->where('user_id', $this->beto->id)->update(['left_at' => now()]);
    $this->actingAs($this->beto)->postJson(route('team-chat.threads.typing', $this->group))->assertNotFound();

    $this->group->forceFill(['archived_at' => now()])->save();
    $this->actingAs($this->ana)->postJson(route('team-chat.threads.typing', $this->group))->assertForbidden();

    Event::assertNotDispatched(RealtimeSignal::class);
});

test('an unreachable broadcaster does not turn the typing request into an error', function () {
    Broadcast::extend('failing', fn () => new class extends Broadcaster
    {
        public function auth($request): mixed
        {
            return null;
        }

        public function validAuthenticationResponse($request, $result): mixed
        {
            return null;
        }

        public function broadcast(array $channels, $event, array $payload = []): void
        {
            throw new BroadcastException('Reverb no responde.');
        }
    });
    config(['broadcasting.connections.failing' => ['driver' => 'failing'], 'broadcasting.default' => 'failing']);

    $this->actingAs($this->ana)->postJson(route('team-chat.threads.typing', $this->group))->assertNoContent();
});

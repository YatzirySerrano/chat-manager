<?php

namespace App\Actions\TeamChat;

use App\Enums\TeamChat\TeamParticipantRole;
use App\Enums\TeamChat\TeamThreadType;
use App\Events\TeamChat\TeamThreadChanged;
use App\Models\TeamChat\TeamThread;
use App\Models\User;
use App\Services\TeamChat\TeamChatDirectory;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Open the one-to-one chat between two people, creating it the first time.
 * There is a single direct chat per pair (unique `direct_key`), also when
 * both start it at the same moment.
 */
class StartDirectThread
{
    public function __construct(private TeamChatDirectory $directory) {}

    public function handle(User $actor, User $target): TeamThread
    {
        if ($actor->id === $target->id) {
            throw ValidationException::withMessages(['user_id' => 'No puedes iniciar un chat contigo.']);
        }

        $key = TeamThread::directKeyFor($actor->id, $target->id);
        $existing = TeamThread::query()->where('direct_key', $key)->withActiveParticipant($actor)->first();

        if ($existing !== null) {
            return $existing;
        }

        if (! $this->directory->canContact($actor, $target)) {
            throw ValidationException::withMessages(['user_id' => 'No puedes iniciar un chat con esta persona.']);
        }

        try {
            return DB::transaction(function () use ($actor, $target, $key): TeamThread {
                $thread = new TeamThread(['type' => TeamThreadType::Direct]);
                $thread->forceFill(['direct_key' => $key, 'created_by_user_id' => $actor->id])->save();

                foreach ([$actor, $target] as $participant) {
                    $thread->participations()->create([
                        'user_id' => $participant->id,
                        'role' => TeamParticipantRole::Member,
                        'joined_at' => now(),
                        'added_by_user_id' => $actor->id,
                    ]);
                }

                TeamThreadChanged::dispatch($thread->id, TeamThreadChanged::CREATED, $actor->id, [$actor->id, $target->id]);

                return $thread;
            });
        } catch (UniqueConstraintViolationException) {
            return TeamThread::query()->where('direct_key', $key)->firstOrFail();
        }
    }
}

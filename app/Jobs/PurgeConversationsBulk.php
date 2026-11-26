<?php

namespace App\Jobs;

use App\Actions\Conversations\PurgeConversationsInBulk;
use App\Enums\Permission;
use App\Models\User;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * «Vaciar conversaciones» (PurgeConversationsInBulk) a slice at a time: each
 * run erases conversations for at most SECONDS and queues itself again with
 * what it has erased so far, so a scope with a large history never holds a
 * worker for long. A run that dies is continued by asking again: whatever was
 * erased is gone and the rest is still there. The person who asked must still
 * hold permanent-deletion.conversations.
 */
class PurgeConversationsBulk implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Queueable;

    private const SECONDS = 200;

    public int $tries = 1;

    public int $timeout = 400;

    public bool $failOnTimeout = true;

    public int $uniqueFor = 900;

    /**
     * @param  array{conversations: int, messages: int, attachments: int, files_pending: int}  $erased
     */
    public function __construct(
        public string $scope,
        public int $actorId,
        public array $erased = ['conversations' => 0, 'messages' => 0, 'attachments' => 0, 'files_pending' => 0],
    ) {}

    public function uniqueId(): string
    {
        return 'purge-conversations-'.$this->scope;
    }

    public function handle(PurgeConversationsInBulk $purge): void
    {
        $actor = User::query()->find($this->actorId);

        if (! in_array($this->scope, PurgeConversationsInBulk::SCOPES, true)) {
            return;
        }

        if ($actor === null || ! $actor->holdsPermission(Permission::PurgeConversations)) {
            Log::warning('The bulk deletion of conversations stopped: the person who asked can no longer do it.', ['scope' => $this->scope]);

            return;
        }

        $step = $purge->erase($this->scope, $actor, self::SECONDS);

        $erased = [
            'conversations' => $this->erased['conversations'] + $step['conversations'],
            'messages' => $this->erased['messages'] + $step['messages'],
            'attachments' => $this->erased['attachments'] + $step['attachments'],
            'files_pending' => $this->erased['files_pending'] + $step['files_pending'],
        ];

        if ($step['done']) {
            $purge->finish($this->scope, $actor, $erased);

            return;
        }

        self::dispatch($this->scope, $this->actorId, $erased)->onQueue($this->queue);
    }
}

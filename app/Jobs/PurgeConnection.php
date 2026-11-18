<?php

namespace App\Jobs;

use App\Actions\Connections\DeleteConnectionPermanently;
use App\Enums\Permission;
use App\Models\Connection;
use App\Models\User;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Erases a connection for good (DeleteConnectionPermanently) a slice at a
 * time: each run erases conversations for at most SECONDS and queues itself
 * again with what it has erased so far, so a connection with a large
 * history never holds a worker for long. A run that dies is continued by
 * asking again: whatever was erased is gone and the rest is still there.
 * The person who asked must still hold permanent-deletion.connections.
 */
class PurgeConnection implements ShouldBeUniqueUntilProcessing, ShouldQueue
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
        public int $connectionId,
        public int $actorId,
        public array $erased = ['conversations' => 0, 'messages' => 0, 'attachments' => 0, 'files_pending' => 0],
    ) {}

    public function uniqueId(): string
    {
        return 'purge-connection-'.$this->connectionId;
    }

    public function handle(DeleteConnectionPermanently $purge): void
    {
        $connection = Connection::query()->find($this->connectionId);
        $actor = User::query()->find($this->actorId);

        if ($connection === null) {
            return;
        }

        if ($actor === null || ! $actor->holdsPermission(Permission::PurgeConnections)) {
            Log::warning('The permanent deletion of a connection stopped: the person who asked can no longer do it.', ['connection_id' => $this->connectionId]);

            return;
        }

        $step = $purge->eraseConversations($connection, $actor, self::SECONDS);

        $erased = [
            'conversations' => $this->erased['conversations'] + $step['conversations'],
            'messages' => $this->erased['messages'] + $step['messages'],
            'attachments' => $this->erased['attachments'] + $step['attachments'],
            'files_pending' => $this->erased['files_pending'] + $step['files_pending'],
        ];

        if ($step['done'] && $purge->finish($connection, $actor, $erased)) {
            return;
        }

        self::dispatch($this->connectionId, $this->actorId, $erased)->onQueue($this->queue);
    }
}

<?php

namespace Tests\Support\Concurrency;

use App\Enums\Permission;
use App\Models\User;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Queued job that records whether a user holds a permission, as seen by the
 * queue worker running it (the same check the inbound distribution and the
 * notification listeners make in background workers).
 */
final class ProbePermissionJob implements ShouldQueue
{
    use Queueable;

    public function __construct(public int $userId, public string $permission, public string $log) {}

    public function handle(): void
    {
        $holds = User::query()->findOrFail($this->userId)->holdsPermission(Permission::from($this->permission));

        file_put_contents($this->log, ($holds ? 'holds' : 'lacks')."\n", FILE_APPEND | LOCK_EX);
    }
}

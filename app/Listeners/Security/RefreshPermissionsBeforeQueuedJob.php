<?php

namespace App\Listeners\Security;

use Illuminate\Queue\Events\JobProcessing;
use Spatie\Permission\PermissionRegistrar;

/**
 * A queue worker is a long-running process: the role and permission map
 * it loaded for its first job would stay in memory for every later job, so
 * a permission revoked by an administrator (in another process) would keep
 * being honored by background work, such as the automatic distribution of
 * inbound conversations or the selection of who is notified. Before each
 * job the in-memory map is dropped; it is read again from the shared cache
 * store, which the revocation already invalidated.
 */
class RefreshPermissionsBeforeQueuedJob
{
    public function __construct(private PermissionRegistrar $permissions) {}

    public function handle(JobProcessing $event): void
    {
        $this->permissions->clearPermissionsCollection();
    }
}

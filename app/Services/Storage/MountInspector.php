<?php

namespace App\Services\Storage;

use Illuminate\Container\Attributes\Bind;

/**
 * Operating-system view of a mounted media disk (a NAS share mounted on
 * this server). Kept behind an interface so tests can simulate a mount, a
 * missing mount or a different filesystem without touching the real
 * mount table.
 */
#[Bind(SystemMountInspector::class)]
interface MountInspector
{
    /**
     * Whether the mount table can be read without touching the mount itself
     * (Linux: /proc/self/mountinfo).
     */
    public function canReadMountTable(): bool;

    /**
     * Filesystem type mounted exactly at the path (the topmost mount, e.g.
     * "cifs"), or null when nothing is mounted there. Only meaningful when
     * canReadMountTable() is true.
     */
    public function mountedType(string $path): ?string;

    /**
     * Device ids of the path and of its parent directory (stat). Different
     * ids mean the path is a mountpoint. Null when either cannot be read.
     * This touches the mount: only call it from the bounded health probe.
     *
     * @return array{path: int, parent: int}|null
     */
    public function deviceIds(string $path): ?array;
}

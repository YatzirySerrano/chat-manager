<?php

namespace Tests\Feature\Storage;

use App\Services\Storage\MountInspector;

/**
 * A simulated mount table and stat() for a temporary directory standing in
 * for the NAS share: by default the directory looks like a CIFS mountpoint
 * (its own device id); tests change the properties to unmount it, mount
 * another filesystem or make it share its parent's device.
 */
final class FakeMountInspector implements MountInspector
{
    public bool $tableReadable = true;

    public ?string $type = 'cifs';

    /** @var array{path: int, parent: int}|null */
    public ?array $devices = ['path' => 2050, 'parent' => 2049];

    public function canReadMountTable(): bool
    {
        return $this->tableReadable;
    }

    public function mountedType(string $path): ?string
    {
        return $this->type;
    }

    public function deviceIds(string $path): ?array
    {
        return $this->devices;
    }
}

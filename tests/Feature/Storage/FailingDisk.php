<?php

namespace Tests\Feature\Storage;

use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use League\Flysystem\UnableToCheckExistence;

/**
 * A faked disk (Storage::fake) that misbehaves like an unreachable or
 * flaky external bucket configured with throw=false:
 * - "write": writeStream()/put() report failure and store nothing;
 * - "verify": the write "succeeds" but the stored size does not match;
 * - "read": checking or reading a file throws (network error).
 */
final class FailingDisk extends FilesystemAdapter
{
    /** @var list<string> */
    public array $failing = [];

    /** @var list<int> Database transaction level at each writeStream() call. */
    public array $writeTransactionLevels = [];

    public static function install(string $disk, string ...$failing): self
    {
        $fake = Storage::fake($disk);
        $instance = new self($fake->getDriver(), $fake->getAdapter(), $fake->getConfig());
        $instance->failing = array_values($failing);
        Storage::set($disk, $instance);

        return $instance;
    }

    public function writeStream($path, $resource, array $options = [])
    {
        $this->writeTransactionLevels[] = DB::transactionLevel();

        return in_array('write', $this->failing, true) ? false : parent::writeStream($path, $resource, $options);
    }

    public function put($path, $contents, $options = [])
    {
        return in_array('write', $this->failing, true) ? false : parent::put($path, $contents, $options);
    }

    public function size($path)
    {
        return in_array('verify', $this->failing, true) ? 0 : parent::size($path);
    }

    public function exists($path)
    {
        if (in_array('read', $this->failing, true)) {
            throw UnableToCheckExistence::forLocation($path);
        }

        return parent::exists($path);
    }
}

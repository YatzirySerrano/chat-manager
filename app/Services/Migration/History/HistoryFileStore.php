<?php

namespace App\Services\Migration\History;

use App\Enums\HistoryImportFileStatus;
use App\Models\HistoryImportFile;
use Closure;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * The stored copies of the files of a history import batch (private
 * imports disk). Readers need a local path: on a local disk it is the file
 * itself, on a remote one (S3) a temporary copy that is always deleted.
 */
class HistoryFileStore
{
    /**
     * @template TResult
     *
     * @param  Closure(string): TResult  $callback
     * @return TResult
     */
    public function withLocalCopy(HistoryImportFile $file, Closure $callback): mixed
    {
        if ($file->path === null) {
            throw new RuntimeException('The history import file was already deleted.');
        }

        $disk = Storage::disk($file->disk);

        if (config("filesystems.disks.{$file->disk}.driver") === 'local') {
            return $callback($disk->path($file->path));
        }

        $temporary = tempnam(sys_get_temp_dir(), 'hist');

        if ($temporary === false) {
            throw new RuntimeException('No temporary file for the history import.');
        }

        try {
            $source = $disk->readStream($file->path);
            $target = fopen($temporary, 'wb');

            if (! is_resource($source) || $target === false) {
                throw new RuntimeException('The history import file could not be copied.');
            }

            stream_copy_to_stream($source, $target);
            fclose($source);
            fclose($target);

            return $callback($temporary);
        } finally {
            @unlink($temporary);
        }
    }

    /**
     * Delete the stored copy (the original stays wherever the admin keeps
     * it; nothing is ever deleted in Whaticket).
     */
    public function purge(HistoryImportFile $file): void
    {
        if ($file->path !== null) {
            Storage::disk($file->disk)->delete($file->path);
        }

        $file->forceFill(['path' => null, 'status' => HistoryImportFileStatus::Purged])->save();
    }
}

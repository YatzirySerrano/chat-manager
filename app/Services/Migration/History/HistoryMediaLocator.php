<?php

namespace App\Services\Migration\History;

use App\Enums\HistoryImportFileKind;
use App\Enums\HistoryImportFileStatus;
use App\Models\DataTransfer;
use App\Models\HistoryImportFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use ZipArchive;

/**
 * Finds the file a message references inside the batch's ZIPs while a
 * batch is executed.
 *
 * Scope (never across conversations by accident): a Chat.txt message is
 * looked up only in the ZIP that contained that Chat.txt; a message of the
 * messages CSV (or of a Chat.txt uploaded alone) is looked up in every ZIP
 * of the batch, and only when exactly one ZIP has a file with that name —
 * phone-generated names (IMG-20240101-WA0001.jpg) repeat across exports, so
 * a name found in several ZIPs is "ambiguous" and never guessed.
 *
 * Every ZIP is checked again (SafeZip) when opened; each one is opened once
 * per run. On a remote imports disk a local working copy of each ZIP is kept
 * between runs (storage/app/private/history-import-work/{batch}) and removed
 * with the batch's staging (purgeWorkingCopies).
 */
class HistoryMediaLocator
{
    public const AMBIGUOUS = 'ambiguous';

    /**
     * @var array<int, array<string, array{index: int, size: int}>> ZIP file id => base name => entry.
     */
    private array $entries = [];

    /**
     * @var array<string, list<int>> Base name => ids of the ZIPs that have it.
     */
    private array $owners = [];

    /**
     * @var array<int, string> ZIP file id => local path.
     */
    private array $paths = [];

    /**
     * @var array<int, ZipArchive>
     */
    private array $archives = [];

    public function __construct(private SafeZip $zip) {}

    public function open(DataTransfer $batch): void
    {
        $this->close();

        HistoryImportFile::query()
            ->where('data_transfer_id', $batch->id)
            ->where('kind', HistoryImportFileKind::Media)
            ->where('extension', 'zip')
            ->where('status', HistoryImportFileStatus::Parsed)
            ->whereNotNull('path')
            ->orderBy('id')
            ->each(function (HistoryImportFile $file) use ($batch): void {
                $path = $this->localPath($batch, $file);
                $this->paths[$file->id] = $path;

                foreach ($this->zip->entries($path) as $entry) {
                    if (ChatTextParser::isChatText($entry['base']) || isset($this->entries[$file->id][$entry['base']])) {
                        continue;
                    }

                    $this->entries[$file->id][$entry['base']] = ['index' => $entry['index'], 'size' => $entry['size']];
                    $this->owners[$entry['base']][] = $file->id;
                }
            });
    }

    /**
     * The entry a message references: [zip file id, index, size], the
     * AMBIGUOUS marker, or null when no ZIP has it.
     *
     * @param  int|null  $zipFileId  The ZIP the message came from (its Chat.txt), or null.
     * @return array{file: int, index: int, size: int}|string|null
     */
    public function find(?int $zipFileId, ?string $name): array|string|null
    {
        if ($name === null || $name === '') {
            return null;
        }

        if ($zipFileId !== null && isset($this->paths[$zipFileId])) {
            $entry = $this->entries[$zipFileId][$name] ?? null;

            return $entry === null ? null : ['file' => $zipFileId, ...$entry];
        }

        $owners = $this->owners[$name] ?? [];

        if (count($owners) > 1) {
            return self::AMBIGUOUS;
        }

        return $owners === [] ? null : ['file' => $owners[0], ...$this->entries[$owners[0]][$name]];
    }

    /**
     * A temporary copy of an entry found by find(); the caller deletes it.
     *
     * @param  array{file: int, index: int, size: int}  $entry
     */
    public function extract(array $entry): string
    {
        $this->archives[$entry['file']] ??= $this->zip->openArchive($this->paths[$entry['file']]);

        return $this->zip->extractFrom($this->archives[$entry['file']], $entry['index'], $entry['size']);
    }

    public function close(): void
    {
        foreach ($this->archives as $archive) {
            $archive->close();
        }

        $this->entries = [];
        $this->owners = [];
        $this->paths = [];
        $this->archives = [];
    }

    /**
     * Remove the local working copies of a batch's ZIPs.
     */
    public static function purgeWorkingCopies(DataTransfer $batch): void
    {
        File::deleteDirectory(self::workingDirectory($batch));
    }

    private static function workingDirectory(DataTransfer $batch): string
    {
        return storage_path('app/private/history-import-work/'.$batch->id);
    }

    private function localPath(DataTransfer $batch, HistoryImportFile $file): string
    {
        $disk = Storage::disk($file->disk);

        if (config("filesystems.disks.{$file->disk}.driver") === 'local') {
            return $disk->path((string) $file->path);
        }

        $directory = self::workingDirectory($batch);
        $target = $directory.'/'.$file->sha256.'.zip';

        if (is_file($target) && hash_file('sha256', $target) === $file->sha256) {
            return $target;
        }

        File::ensureDirectoryExists($directory, 0700);
        $source = $disk->readStream((string) $file->path);
        $out = fopen($target, 'wb');

        if (! is_resource($source) || $out === false) {
            throw new RuntimeException('The media ZIP of the history import could not be copied.');
        }

        stream_copy_to_stream($source, $out);
        fclose($source);
        fclose($out);

        return $target;
    }
}

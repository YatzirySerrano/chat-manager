<?php

namespace App\Jobs;

use App\Enums\VideoExportStatus;
use App\Exceptions\StorageUnavailable;
use App\Models\VideoExport;
use App\Services\Storage\MediaDisk;
use App\Services\Storage\MediaPaths;
use App\Services\VideoExports\VideoExports;
use App\Services\VideoExports\VideoFolders;
use App\Support\Queues;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Copies (never moves) the file of a VideoExport (a video, image, PDF,
 * document or audio of a chat; the class keeps its original name) into the
 * folder of the NAS the person chose, under the name they typed and the
 * extension of the original, through MediaDisk: the
 * target disk must be ready (a mounted NAS is verified with MountHealth
 * before writing), the file is written next to its final name and renamed
 * when complete, and a name already taken gets " (2)".
 *
 * While the NAS is down the copy waits and tries again (backoff), with the
 * reason shown in the bubble; after the last attempt it is marked failed
 * with a clear explanation and can be retried by hand. The original stays
 * where it is, so the chat keeps playing it.
 */
class SaveVideoToNas implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    /**
     * Name used when the typed one is left empty once cleaned.
     *
     * @var array<string, string>
     */
    private const DEFAULT_NAMES = ['video' => 'Video', 'image' => 'Imagen', 'pdf' => 'Documento', 'document' => 'Documento', 'audio' => 'Audio'];

    public int $tries = 4;

    /** Large videos cross the network to the NAS. */
    public int $timeout = 900;

    /**
     * Seconds before each new attempt while the NAS is not available.
     *
     * @var list<int>
     */
    public array $retryAfter = [60, 300, 900];

    public function __construct(public int $exportId)
    {
        $this->onQueue(Queues::name(Queues::MEDIA));
    }

    public function handle(MediaDisk $mediaDisk, VideoFolders $folders): void
    {
        $export = VideoExport::query()->find($this->exportId);

        if ($export === null || ! $export->status->isPending()) {
            return;
        }

        $export->forceFill(['status' => VideoExportStatus::Saving, 'attempts' => $export->attempts + 1])->save();

        $source = $export->source();
        $sourcePath = $source?->path;

        if ($source === null || $sourcePath === null || $sourcePath === '') {
            $this->markFailed($export, 'El archivo original ya no está disponible.');

            return;
        }

        // The copy keeps the extension of the original (never one typed by the person).
        $extension = app(VideoExports::class)->extensionOf($source);
        $stem = MediaPaths::clean($export->file_name, 120);
        $stem = $stem !== '' ? $stem : self::DEFAULT_NAMES[VideoExports::kindOf($source)];
        $temporary = null;

        try {
            // Before touching the source: with the NAS down nothing is read or written.
            $mediaDisk->assertReady($export->disk);

            // The folder is checked again: it may have been removed or renamed on the NAS meanwhile.
            try {
                $segments = VideoFolders::segments($export->folder);
                $exists = $folders->disk() === $export->disk && $folders->exists($segments);
            } catch (ValidationException) {
                $exists = false;
            }

            if (! $exists) {
                $this->markFailed($export, 'La carpeta elegida ya no existe en Synology. Guarda el archivo otra vez en otra carpeta.');

                return;
            }

            $copy = $this->localCopy((string) $source->disk, $sourcePath);

            if ($copy === null) {
                $this->markFailed($export, 'El archivo original ya no está disponible.');

                return;
            }

            [$localPath, $temporary] = $copy;
            $stored = $mediaDisk->putNamedFile($localPath, $folders->pathOnDisk($segments), $stem, $extension, $export->disk);

            $export->forceFill([
                'status' => VideoExportStatus::Saved,
                'target_path' => $stored['path'],
                'error' => null,
                'saved_at' => now(),
            ])->save();
        } catch (StorageUnavailable $exception) {
            $reason = MediaDisk::explain($exception->reason);

            if ($this->attempts() < $this->tries) {
                $export->forceFill([
                    'status' => VideoExportStatus::Queued,
                    'error' => 'Synology no está disponible: '.$reason.'. Se reintentará automáticamente.',
                ])->save();

                $this->release($this->retryAfter[min($this->attempts() - 1, count($this->retryAfter) - 1)] ?? 60);

                return;
            }

            $this->markFailed($export, 'No se pudo guardar en Synology: '.$reason.'.');
        } finally {
            if ($temporary !== null && is_file($temporary)) {
                @unlink($temporary);
            }
        }
    }

    /**
     * A timeout or an unexpected error: the copy is given up with a clear
     * status (nothing half written keeps its final name, see MediaDisk).
     */
    public function failed(?Throwable $exception): void
    {
        $export = VideoExport::query()->find($this->exportId);

        if ($export !== null && $export->status !== VideoExportStatus::Saved) {
            $this->markFailed($export, 'No se pudo guardar en Synology. Inténtalo de nuevo; si continúa, avisa a un administrador.');
        }
    }

    private function markFailed(VideoExport $export, string $message): void
    {
        $export->forceFill(['status' => VideoExportStatus::Failed, 'error' => $message])->save();

        Log::warning('A chat file could not be saved to the NAS.', ['video_export_id' => $export->id]);
    }

    /**
     * A path on this server with the source content: the file itself on a
     * local (or mounted) disk, else a temporary download of it.
     *
     * @return array{string, string|null}|null The path and the temporary file to remove, if any; null when the original is gone.
     *
     * @throws StorageUnavailable
     */
    private function localCopy(string $disk, string $path): ?array
    {
        $filesystem = Storage::disk($disk);

        try {
            if (config("filesystems.disks.{$disk}.driver") === 'local') {
                $local = $filesystem->path($path);

                return is_file($local) ? [$local, null] : null;
            }

            $temporary = (string) tempnam(sys_get_temp_dir(), 'video-export-');
            $input = $filesystem->readStream($path);
            $output = fopen($temporary, 'wb');

            if (! is_resource($input) || $output === false || stream_copy_to_stream($input, $output) === false) {
                throw new StorageUnavailable('read_failed', $disk);
            }

            fclose($output);
            fclose($input);

            return [$temporary, $temporary];
        } catch (StorageUnavailable $exception) {
            throw $exception;
        } catch (Throwable) {
            throw new StorageUnavailable('read_failed', $disk);
        }
    }
}

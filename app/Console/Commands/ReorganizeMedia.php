<?php

namespace App\Console\Commands;

use App\Exceptions\MediaReorganizationStopped;
use App\Models\Contact;
use App\Models\HistoryImportMessage;
use App\Models\MessageAttachment;
use App\Models\TeamChat\TeamAttachment;
use App\Services\Storage\MediaDisk;
use App\Services\Storage\MediaPaths;
use App\Services\Storage\MediaThumbnails;
use App\Services\Storage\MountHealth;
use App\Support\PhoneNumber;
use Illuminate\Console\Command;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Moves the files stored with the old layout ("media/2026/11/{uuid}",
 * "team-chat/2026/11/{uuid}") to the readable one of MediaPaths
 * ("WhatsApp/Juan Perez (+52 55 1234 5678)/2026-11 Noviembre/2026-11-12
 * 14.30.15 Foto recibida.jpg").
 *
 * Without --apply it only SIMULATES: it lists origin → destination and
 * writes nothing (no file, no row, no folder assignment).
 *
 * With --apply every file is moved on its own, safely: copied to the
 * destination, verified (size, and SHA-256 when the file is not huge),
 * its path updated in the database for every row that references it,
 * recorded in a journal, and only then removed from the origin together
 * with the previews an earlier version left next to it (".thumb.webp").
 * A file that fails stays where it was and keeps working. The run can be
 * stopped and repeated: files already moved are no longer selected.
 *
 * --undo=<journal> moves the files of a previous run back.
 */
class ReorganizeMedia extends Command
{
    protected $signature = 'storage:reorganize-media
        {--apply : Move the files (without it the command only simulates and writes nothing)}
        {--limit=0 : Most files to process in this run (0 = all)}
        {--contact= : Only the files of one contact (its phone number or its numeric id)}
        {--only= : Only one area: conversations or team-chat}
        {--undo= : Journal of a previous run whose files are moved back}';

    protected $description = 'Move stored files to readable folders per contact (simulation unless --apply)';

    /** Files above this size are verified by size only. */
    private const HASH_LIMIT_BYTES = 64 * 1024 * 1024;

    /**
     * Names planned in this run, per directory (simulation and same-second files).
     *
     * @var array<string, array<string, true>>
     */
    private array $planned = [];

    /** @var array<string, int> */
    private array $totals = ['moved' => 0, 'would_move' => 0, 'missing' => 0, 'skipped' => 0, 'failed' => 0];

    /** @var resource|null */
    private $journal = null;

    private ?string $journalPath = null;

    private int $listed = 0;

    public function handle(MediaDisk $mediaDisk, MediaPaths $paths, MountHealth $mounts): int
    {
        // One instance may run more than once in a process (tests, a scheduler).
        $this->planned = [];
        $this->totals = ['moved' => 0, 'would_move' => 0, 'missing' => 0, 'skipped' => 0, 'failed' => 0];
        $this->journal = null;
        $this->journalPath = null;
        $this->listed = 0;

        $undo = $this->option('undo');

        if (is_string($undo) && $undo !== '') {
            return $this->undo($undo, $mediaDisk, $mounts);
        }

        $apply = (bool) $this->option('apply');
        $limit = max(0, (int) $this->option('limit'));
        $only = $this->option('only');

        if (! in_array($only, [null, '', 'conversations', 'team-chat'], true)) {
            $this->error('--only admite "conversations" o "team-chat".');

            return self::INVALID;
        }

        $contactId = $this->contactId();

        if ($contactId === false) {
            $this->error('No hay ningún contacto con ese teléfono o identificador.');

            return self::FAILURE;
        }

        $this->line($apply
            ? 'Moviendo archivos al esquema de carpetas legibles...'
            : 'SIMULACIÓN: se muestra qué se movería. No se escribe nada. Usa --apply para mover.');

        try {
            if ($only !== 'team-chat') {
                $this->conversations($apply, $limit, $contactId, $mediaDisk, $paths, $mounts);
            }

            if ($only !== 'conversations' && $contactId === null && ($limit === 0 || $this->processed() < $limit)) {
                $this->teamChat($apply, $limit, $mediaDisk, $paths, $mounts);
            }
        } catch (MediaReorganizationStopped $stopped) {
            $this->closeJournal();
            $this->newLine();
            $this->error($stopped->getMessage());
            $this->summary($apply);

            return self::FAILURE;
        }

        $this->closeJournal();
        $this->summary($apply);

        return $this->totals['failed'] > 0 ? self::FAILURE : self::SUCCESS;
    }

    private function conversations(bool $apply, int $limit, ?int $contactId, MediaDisk $mediaDisk, MediaPaths $paths, MountHealth $mounts): void
    {
        $query = MessageAttachment::query()
            ->whereNotNull('path')
            ->where(fn ($q) => $q->where('path', 'like', 'media/%'))
            ->when($contactId !== null, fn ($q) => $q->whereIn('message_id', fn ($messages) => $messages
                ->select('messages.id')->from('messages')
                ->join('conversations', 'conversations.id', '=', 'messages.conversation_id')
                ->where('conversations.contact_id', $contactId)));

        $seen = [];

        foreach ($query->orderBy('id')->lazyById(200) as $attachment) {
            if ($limit > 0 && $this->processed() >= $limit) {
                return;
            }

            $key = $attachment->disk.'|'.$attachment->path;

            // Several rows may reference one stored file: it is moved once.
            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $message = $attachment->message()->first();
            $placement = $message !== null ? $paths->forMessage($message, (string) $attachment->mime_type, $attachment->file_name, null, $apply) : null;

            if ($placement === null) {
                $this->totals['skipped']++;

                continue;
            }

            $this->move('message', (string) $attachment->disk, (string) $attachment->path, $placement, $apply, $mediaDisk, $mounts);
        }
    }

    private function teamChat(bool $apply, int $limit, MediaDisk $mediaDisk, MediaPaths $paths, MountHealth $mounts): void
    {
        $query = TeamAttachment::query()->whereNotNull('path')->whereNull('purged_at')->where('path', 'like', 'team-chat/%');
        $seen = [];

        foreach ($query->orderBy('id')->lazyById(200) as $attachment) {
            if ($limit > 0 && $this->processed() >= $limit) {
                return;
            }

            $key = $attachment->disk.'|'.$attachment->path;

            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $message = $attachment->message()->first();
            $thread = $message?->thread()->first();

            if ($message === null || $thread === null) {
                $this->totals['skipped']++;

                continue;
            }

            $kind = $attachment->kind;
            $placement = $paths->teamFile(
                $thread,
                $message->author()->first(),
                $kind->value,
                (string) $attachment->mime_type,
                $message->created_at ?? now(),
                $attachment->original_name,
                $apply,
            );

            $this->move('team', (string) $attachment->disk, (string) $attachment->path, $placement, $apply, $mediaDisk, $mounts);
        }
    }

    /**
     * @param  array{directory: string, stem: string, extension: string}  $placement
     */
    private function move(string $type, string $disk, string $from, array $placement, bool $apply, MediaDisk $mediaDisk, MountHealth $mounts): void
    {
        $this->assertAvailable($disk, $mediaDisk, $mounts);

        $filesystem = Storage::disk($disk);

        try {
            if (! $filesystem->exists($from)) {
                $this->totals['missing']++;
                $this->report($from, null, 'el archivo no está en el almacenamiento; no se toca');

                return;
            }

            $to = $this->freeName($filesystem, $placement);
        } catch (Throwable $exception) {
            throw new MediaReorganizationStopped('El almacenamiento dejó de responder ('.$exception::class.'). Se detuvo sin perder nada; vuelve a ejecutar el comando para continuar.');
        }

        if (! $apply) {
            $this->totals['would_move']++;
            $this->report($from, $to);

            return;
        }

        try {
            $size = $filesystem->size($from);

            if (! $filesystem->copy($from, $to) || $filesystem->size($to) !== $size) {
                throw new \RuntimeException('size mismatch after copy');
            }

            // Compared with the origin itself (the digest of an old row may be stale).
            if ($size <= self::HASH_LIMIT_BYTES && $this->hash($filesystem, $to) !== $this->hash($filesystem, $from)) {
                throw new \RuntimeException('content mismatch after copy');
            }
        } catch (Throwable $exception) {
            try {
                $filesystem->delete($to);
            } catch (Throwable) {
                // The origin is intact; a partial copy is reported below.
            }

            $this->totals['failed']++;
            $this->report($from, $to, 'no se pudo copiar o verificar ('.$exception->getMessage().'); el original sigue en su sitio');

            return;
        }

        DB::transaction(function () use ($type, $disk, $from, $to): void {
            if ($type === 'team') {
                TeamAttachment::query()->where('disk', $disk)->where('path', $from)->toBase()->update(['path' => $to]);

                return;
            }

            MessageAttachment::query()->where('disk', $disk)->where('path', $from)->toBase()->update(['path' => $to]);
            // The staged row of an imported history points at the same file.
            HistoryImportMessage::query()->where('media_disk', $disk)->where('media_path', $from)->toBase()->update(['media_path' => $to]);
        });

        $this->record(['type' => $type, 'disk' => $disk, 'from' => $from, 'to' => $to]);

        try {
            // The origin, the previews an earlier version left next to it
            // and the preview filed under the old path (it is made again on demand).
            $filesystem->delete([$from, ...MediaThumbnails::pathsFor($from)]);
        } catch (Throwable) {
            $this->report($from, $to, 'movido; no se pudo borrar el original (queda duplicado, sin referencia)');
        }

        $this->totals['moved']++;
        $this->report($from, $to);
    }

    /**
     * First free name of the placement: neither on the disk nor planned in this run.
     *
     * @param  array{directory: string, stem: string, extension: string}  $placement
     */
    private function freeName(Filesystem $filesystem, array $placement): string
    {
        $suffix = $placement['extension'] !== '' ? '.'.$placement['extension'] : '';
        $directory = $placement['directory'];
        $bucket = mb_strtolower($directory);

        for ($order = 1; $order <= 9999; $order++) {
            $name = $placement['stem'].($order === 1 ? '' : " ({$order})").$suffix;
            $taken = isset($this->planned[$bucket][mb_strtolower($name)]) || $filesystem->exists($directory.'/'.$name);

            if (! $taken) {
                $this->planned[$bucket][mb_strtolower($name)] = true;

                return $directory.'/'.$name;
            }
        }

        throw new \RuntimeException('No free name in '.$directory);
    }

    private function assertAvailable(string $disk, MediaDisk $mediaDisk, MountHealth $mounts): void
    {
        // A share that is not mounted leaves an empty local directory: the
        // files would look missing and copies would land on the server.
        if ($mediaDisk->isMount($disk) && ($problem = $mounts->cheapProblem($disk)) !== null) {
            throw new MediaReorganizationStopped('El almacenamiento «'.$disk.'» no está disponible: '.MediaDisk::explain($problem).'. No se movió nada más; vuelve a ejecutar el comando cuando responda.');
        }
    }

    private function hash(Filesystem $filesystem, string $path): string
    {
        $stream = $filesystem->readStream($path);

        if (! is_resource($stream)) {
            throw new \RuntimeException('unreadable copy');
        }

        $context = hash_init('sha256');
        hash_update_stream($context, $stream);
        fclose($stream);

        return hash_final($context);
    }

    /**
     * Null without the option; false when it matches no contact.
     */
    private function contactId(): int|false|null
    {
        $value = $this->option('contact');

        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        $value = trim($value);
        $phone = PhoneNumber::normalize($value);
        $contact = ($phone !== null ? Contact::query()->where('phone_e164', $phone)->first() : null)
            ?? (ctype_digit($value) ? Contact::query()->whereKey((int) $value)->first() : null);

        return $contact === null ? false : $contact->id;
    }

    /**
     * @param  array{type: string, disk: string, from: string, to: string}  $entry
     */
    private function record(array $entry): void
    {
        if ($this->journal === null) {
            $directory = storage_path('app/private/reorganizacion-de-archivos');

            if (! is_dir($directory)) {
                mkdir($directory, 0700, true);
            }

            $this->journalPath = $directory.DIRECTORY_SEPARATOR.now()->format('Y-m-d H.i.s').'.jsonl';
            $journal = fopen($this->journalPath, 'ab');
            $this->journal = $journal === false ? null : $journal;
        }

        if ($this->journal !== null) {
            fwrite($this->journal, json_encode($entry, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n");
            fflush($this->journal);
        }
    }

    private function closeJournal(): void
    {
        if ($this->journal !== null) {
            fclose($this->journal);
            $this->journal = null;
        }
    }

    private function report(string $from, ?string $to, ?string $note = null): void
    {
        // The whole list would flood the terminal on a large storage.
        if ($this->listed >= 60 && $note === null && ! $this->output->isVerbose()) {
            if ($this->listed === 60) {
                $this->line('  … (usa -v para ver todos)');
                $this->listed++;
            }

            return;
        }

        $this->listed++;
        $this->line('  '.$from.($to !== null ? "\n    → ".$to : '').($note !== null ? "\n    ! ".$note : ''));
    }

    private function processed(): int
    {
        return $this->totals['moved'] + $this->totals['would_move'] + $this->totals['failed'];
    }

    private function summary(bool $apply): void
    {
        $this->newLine();
        $this->line($apply ? 'Movidos: '.$this->totals['moved'] : 'Se moverían: '.$this->totals['would_move']);
        $this->line('Sin archivo en el almacenamiento: '.$this->totals['missing']);
        $this->line('Sin contacto o chat al que asignarlos: '.$this->totals['skipped']);
        $this->line('Con error (siguen en su sitio): '.$this->totals['failed']);

        if ($this->journalPath !== null) {
            $this->line('Registro para revertir: '.$this->journalPath);
            $this->line('  php artisan storage:reorganize-media --undo="'.$this->journalPath.'"');
        }

        if (! $apply) {
            $this->line('Nada se escribió. El nombre de una carpeta puede variar si dos contactos comparten nombre.');
        }
    }

    /**
     * Moves the files of a journal back to where they were.
     */
    private function undo(string $journal, MediaDisk $mediaDisk, MountHealth $mounts): int
    {
        if (! is_file($journal)) {
            $this->error('No existe ese registro.');

            return self::FAILURE;
        }

        $entries = array_reverse(array_values(array_filter(array_map(
            fn (string $line): mixed => json_decode($line, true),
            file($journal, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [],
        ), 'is_array')));

        $restored = 0;
        $failed = 0;

        foreach ($entries as $entry) {
            [$type, $disk, $from, $to] = [(string) ($entry['type'] ?? ''), (string) ($entry['disk'] ?? ''), (string) ($entry['from'] ?? ''), (string) ($entry['to'] ?? '')];

            if ($disk === '' || $from === '' || $to === '') {
                continue;
            }

            try {
                $this->assertAvailable($disk, $mediaDisk, $mounts);
                $filesystem = Storage::disk($disk);

                if (! $filesystem->exists($to) || $filesystem->exists($from)) {
                    continue;
                }

                $size = $filesystem->size($to);

                if (! $filesystem->copy($to, $from) || $filesystem->size($from) !== $size) {
                    throw new \RuntimeException('copy not verified');
                }

                DB::transaction(function () use ($type, $disk, $from, $to): void {
                    if ($type === 'team') {
                        TeamAttachment::query()->where('disk', $disk)->where('path', $to)->toBase()->update(['path' => $from]);

                        return;
                    }

                    MessageAttachment::query()->where('disk', $disk)->where('path', $to)->toBase()->update(['path' => $from]);
                    HistoryImportMessage::query()->where('media_disk', $disk)->where('media_path', $to)->toBase()->update(['media_path' => $from]);
                });

                $filesystem->delete([$to, ...MediaThumbnails::pathsFor($to)]);
                $restored++;
            } catch (MediaReorganizationStopped $stopped) {
                $this->error($stopped->getMessage());

                return self::FAILURE;
            } catch (Throwable $exception) {
                $failed++;
                $this->line('  ! '.$to.' ('.$exception->getMessage().')');
            }
        }

        $this->line("Devueltos a su ruta anterior: {$restored}. Con error: {$failed}.");

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}

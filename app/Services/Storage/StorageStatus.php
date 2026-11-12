<?php

namespace App\Services\Storage;

use App\Enums\AttachmentStatus;
use App\Exceptions\StorageUnavailable;
use App\Models\MessageAttachment;
use App\Models\TeamChat\TeamAttachment;
use App\Services\PlatformSettings;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Administración > Almacenamiento: where the files of the conversations are
 * kept (the NAS share, an S3-compatible bucket or the server's disk),
 * whether that storage answers right now and what it has been saving.
 *
 * Everything returned is safe to show to an administrator: no credentials,
 * endpoints, bucket names, full internal paths, file contents or complete
 * file names (only a masked name and the type).
 *
 * Cost: readiness reads configuration and, for a mounted disk, the cached
 * bounded probe (MountHealth). Disks without a mount are probed here at
 * most once per PROBE_SECONDS. The activity figures are aggregated queries
 * cached for ACTIVITY_SECONDS, so the live polling of the page stays cheap.
 *
 * @phpstan-type Check array{key: string, label: string, status: string, detail: string}
 */
class StorageStatus
{
    private const PROBE_PREFIX = 'storage:status-probe:';

    private const PROBE_SECONDS = 60;

    private const ACTIVITY_KEY = 'storage:status-activity:';

    private const ACTIVITY_SECONDS = 8;

    private const RECENT_FILES = 10;

    public function __construct(
        private MediaDisk $media,
        private MountHealth $mounts,
        private Repository $cache,
        private PlatformSettings $settings,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function snapshot(): array
    {
        $disk = $this->media->name();
        $kind = $this->kind($disk);
        $problem = $this->media->problem();
        $check = $this->lastCheck($disk, $kind, $problem);
        $usage = $this->usage();
        $activity = $this->activity($disk);

        // A file that arrived after the last probe proves the storage answered then.
        $staleAfter = $kind === 'nas' ? $this->mounts->ttlSeconds() * 2 : self::PROBE_SECONDS * 3;
        $age = $check['checked_at'] !== null ? max(0, now()->getTimestamp() - $check['checked_at']) : null;
        $problem ??= $check['problem'];

        $warnings = [];

        if ($problem === null) {
            if (in_array($usage['level'], ['warning', 'exceeded'], true)) {
                $warnings[] = $usage['level'] === 'exceeded'
                    ? 'El espacio contratado para adjuntos ya se ocupó por completo.'
                    : "Los adjuntos ya ocupan {$usage['percent']} % del espacio contratado.";
            }

            if ($age === null || $age > $staleAfter) {
                $warnings[] = 'No hay una comprobación reciente del almacenamiento: revisa que el programador de tareas esté activo.';
            }

            if ($activity['failed_24h'] > 0) {
                $warnings[] = $activity['failed_24h'] === 1
                    ? 'Un archivo no se pudo guardar en las últimas 24 horas.'
                    : "{$activity['failed_24h']} archivos no se pudieron guardar en las últimas 24 horas.";
            }
        }

        $state = match (true) {
            $problem !== null => 'down',
            $warnings !== [] => 'warning',
            default => 'ok',
        };

        return [
            'state' => $state,
            'headline' => $this->headline($state, $kind),
            'message' => match (true) {
                $problem !== null => ucfirst(MediaDisk::explain($problem)).'.',
                $warnings !== [] => implode(' ', $warnings),
                default => $this->healthyMessage($kind),
            },
            'problem' => $problem,
            'disk' => [
                'name' => $disk,
                'kind' => $kind,
                'label' => $this->kindLabel($kind),
                'location' => $this->location($disk, $kind),
                'required' => $this->media->isRequired(),
                'external' => $this->media->isExternal(),
            ],
            'checked_at' => $check['checked_at'] !== null ? Carbon::createFromTimestamp($check['checked_at'])->toIso8601String() : null,
            'check_age_seconds' => $age,
            'checks' => $this->checks($kind, $problem, $check['checked_at'] !== null),
            'usage' => [...$usage, 'warning_percent' => $this->media->warningPercent()],
            'activity' => $activity,
            'now' => now()->toIso8601String(),
        ];
    }

    /**
     * Probes the storage now (a bounded process for a mounted disk, a tiny
     * write/read/delete elsewhere) and forgets the cached figures.
     */
    public function probeNow(): void
    {
        $disk = $this->media->name();

        if ($this->media->isMount($disk)) {
            if (MountHealth::configurationProblem($disk) === null) {
                $this->mounts->refresh($disk);
            }
        } else {
            $this->cache->forget(self::PROBE_PREFIX.$disk);
            $this->probeUnmounted($disk);
        }

        $this->cache->forget(self::ACTIVITY_KEY.$disk);
    }

    private function kind(string $disk): string
    {
        return match (true) {
            $this->media->isMount($disk) => 'nas',
            $this->media->driver($disk) === 's3' => 's3',
            $this->media->driver($disk) === 'local' => 'local',
            default => 'other',
        };
    }

    private function kindLabel(string $kind): string
    {
        return match ($kind) {
            'nas' => 'NAS montado (Synology)',
            's3' => 'Almacenamiento compatible con S3',
            'local' => 'Disco local del servidor',
            default => 'Almacenamiento externo',
        };
    }

    private function headline(string $state, string $kind): string
    {
        if ($state === 'down') {
            return match ($kind) {
                'nas' => 'Sin conexión con el Synology',
                'local' => 'El disco del servidor no acepta archivos',
                default => 'El almacenamiento no responde',
            };
        }

        $connected = match ($kind) {
            'nas' => 'Conectado al Synology',
            's3' => 'Conectado al almacenamiento externo',
            'local' => 'Guardando en el disco del servidor',
            default => 'Almacenamiento conectado',
        };

        return $state === 'warning' ? $connected.', con avisos' : $connected;
    }

    private function healthyMessage(string $kind): string
    {
        return match ($kind) {
            'nas' => 'Todo se está guardando en la carpeta compartida del NAS y cada archivo se verifica al escribirlo.',
            's3' => 'Todo se está guardando en el almacenamiento externo y cada archivo se verifica al escribirlo.',
            'local' => 'Los adjuntos se guardan en el disco del servidor, no en el Synology.',
            default => 'Todo se está guardando y cada archivo se verifica al escribirlo.',
        };
    }

    /**
     * Only the last folder of a mount; nothing for other disks (bucket
     * names and endpoints stay out of the page).
     */
    private function location(string $disk, string $kind): ?string
    {
        if ($kind !== 'nas') {
            return null;
        }

        $root = (string) (MountHealth::settings($disk)['root'] ?? '');
        $folder = basename($root);

        return $folder !== '' ? '…/'.$folder : null;
    }

    /**
     * The last time the storage was actually probed and what it answered.
     *
     * @return array{problem: string|null, checked_at: int|null}
     */
    private function lastCheck(string $disk, string $kind, ?string $configured): array
    {
        if ($kind === 'nas') {
            $last = $this->mounts->lastCheck($disk);

            return ['problem' => $last['problem'] ?? null, 'checked_at' => $last['checked_at'] ?? null];
        }

        // A disk that is not ready by configuration is never written to.
        if ($configured !== null) {
            return ['problem' => null, 'checked_at' => null];
        }

        return $this->probeUnmounted($disk);
    }

    /**
     * @return array{problem: string|null, checked_at: int|null}
     */
    private function probeUnmounted(string $disk): array
    {
        try {
            /** @var array{problem: string|null, checked_at: int} $result */
            $result = $this->cache->remember(self::PROBE_PREFIX.$disk, self::PROBE_SECONDS, fn (): array => [
                'problem' => $this->media->probe(),
                'checked_at' => now()->getTimestamp(),
            ]);

            return $result;
        } catch (Throwable) {
            return ['problem' => 'probe_failed', 'checked_at' => now()->getTimestamp()];
        }
    }

    /**
     * The checks behind the state, in the order they run: a failure leaves
     * the following ones unevaluated.
     *
     * @return list<Check>
     */
    private function checks(string $kind, ?string $problem, bool $probed): array
    {
        $stages = $kind === 'nas'
            ? [
                'config' => ['Configuración del disco', 'MEDIA_DISK, MEDIA_MOUNT_PATH y MEDIA_MOUNT_ID están completos.', ['not_configured', 'public_disk', 'local_not_allowed', 'adapter_missing']],
                'mount' => ['Carpeta compartida montada', 'La tabla de montajes del servidor muestra el NAS en la ruta esperada.', ['not_mounted', 'wrong_filesystem']],
                'marker' => ['Identidad del almacenamiento', 'El archivo de identidad de la carpeta coincide con MEDIA_MOUNT_ID.', ['marker_missing', 'marker_mismatch']],
                'write' => ['Escritura, lectura y borrado', 'Se escribió, leyó y borró un archivo de prueba.', ['write_failed', 'delete_failed', 'probe_failed', 'probe_timeout']],
            ]
            : [
                'config' => ['Configuración del disco', 'El disco de adjuntos está completo y es privado.', ['not_configured', 'public_disk', 'local_not_allowed', 'adapter_missing']],
                'write' => ['Escritura, lectura y borrado', 'Se escribió, leyó y borró un archivo de prueba.', ['write_failed', 'delete_failed', 'probe_failed', 'probe_timeout']],
            ];

        $checks = [];
        $failed = false;

        foreach ($stages as $key => [$label, $passed, $codes]) {
            if ($failed) {
                $checks[] = ['key' => $key, 'label' => $label, 'status' => 'skipped', 'detail' => 'No se evaluó porque una comprobación anterior falló.'];

                continue;
            }

            if ($problem !== null && (in_array($problem, $codes, true) || $key === 'write')) {
                $failed = true;
                $checks[] = ['key' => $key, 'label' => $label, 'status' => 'fail', 'detail' => ucfirst(MediaDisk::explain($problem)).'.'];

                continue;
            }

            if ($key === 'write' && ! $probed) {
                $checks[] = ['key' => $key, 'label' => $label, 'status' => 'skipped', 'detail' => 'Aún no hay una prueba registrada; usa «Comprobar ahora».'];

                continue;
            }

            $checks[] = ['key' => $key, 'label' => $label, 'status' => 'ok', 'detail' => $passed];
        }

        return $checks;
    }

    /**
     * @return array{disk: string, used_bytes: int, quota_bytes: int, percent: int|null, level: string}
     */
    private function usage(): array
    {
        try {
            return $this->media->usage();
        } catch (Throwable) {
            return ['disk' => $this->media->name(), 'used_bytes' => 0, 'quota_bytes' => $this->media->quotaBytes(), 'percent' => null, 'level' => 'unlimited'];
        }
    }

    /**
     * What the active disk has been saving (conversation files and the
     * team chat's), from the metadata tables only.
     *
     * @return array<string, mixed>
     */
    private function activity(string $disk): array
    {
        try {
            /** @var array<string, mixed> $activity */
            $activity = $this->cache->remember(self::ACTIVITY_KEY.$disk, self::ACTIVITY_SECONDS, fn (): array => $this->computeActivity($disk));

            return $activity;
        } catch (Throwable) {
            return $this->computeActivity($disk);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function computeActivity(string $disk): array
    {
        $now = CarbonImmutable::now();
        $today = $now->setTimezone((string) $this->settings->get('general.timezone', config('app.timezone')))->startOfDay()->utc();
        $week = $now->subDays(7);
        $day = $now->subHours(24);

        $messages = fn () => MessageAttachment::query()->where('disk', $disk)->where('status', AttachmentStatus::Available)->whereNotNull('path');
        $team = fn () => TeamAttachment::query()->where('disk', $disk)->whereNull('purged_at')->whereNotNull('path');

        $total = $messages()->count() + $team()->count();
        $weekCount = $messages()->where('created_at', '>=', $week)->count() + $team()->where('created_at', '>=', $week)->count();

        $todayRows = [
            $messages()->where('created_at', '>=', $today)->selectRaw('COUNT(*) AS files, COALESCE(SUM(size_bytes), 0) AS bytes')->toBase()->first(),
            $team()->where('created_at', '>=', $today)->selectRaw('COUNT(*) AS files, COALESCE(SUM(size_bytes), 0) AS bytes')->toBase()->first(),
        ];

        $failed = MessageAttachment::query()
            ->where('status', AttachmentStatus::Unavailable)
            ->where('created_at', '>=', $day)
            ->where('error_code', StorageUnavailable::CODE)
            ->count();

        $pending = MessageAttachment::query()->where('status', AttachmentStatus::Pending)->count();

        $recent = $this->recent($disk);

        return [
            'total_files' => $total,
            'files_today' => (int) ($todayRows[0]->files ?? 0) + (int) ($todayRows[1]->files ?? 0),
            'bytes_today' => (int) ($todayRows[0]->bytes ?? 0) + (int) ($todayRows[1]->bytes ?? 0),
            'files_7d' => $weekCount,
            'pending' => $pending,
            'failed_24h' => $failed,
            'last_saved_at' => $recent[0]['saved_at'] ?? null,
            'hourly' => $this->hourly($disk, $now),
            'recent' => $recent,
            'other_disks' => $this->otherDisks($disk),
        ];
    }

    /**
     * Files and bytes saved in each of the last 24 hours (oldest first).
     *
     * @return list<array{hour: string, files: int, bytes: int}>
     */
    private function hourly(string $disk, CarbonImmutable $now): array
    {
        $start = $now->utc()->startOfHour()->subHours(23);
        $buckets = [];

        for ($index = 0; $index < 24; $index++) {
            $buckets[$start->addHours($index)->format('Y-m-d H')] = ['files' => 0, 'bytes' => 0];
        }

        $sources = [
            MessageAttachment::query()->where('disk', $disk)->where('status', AttachmentStatus::Available)->whereNotNull('path'),
            TeamAttachment::query()->where('disk', $disk)->whereNull('purged_at')->whereNotNull('path'),
        ];

        foreach ($sources as $query) {
            $rows = $query
                ->where('created_at', '>=', $start)
                ->selectRaw("DATE_FORMAT(created_at, '%Y-%m-%d %H') AS bucket, COUNT(*) AS files, COALESCE(SUM(size_bytes), 0) AS bytes")
                ->groupBy('bucket')
                ->toBase()
                ->get();

            foreach ($rows as $row) {
                $bucket = (string) $row->bucket;

                if (isset($buckets[$bucket])) {
                    $buckets[$bucket]['files'] += (int) $row->files;
                    $buckets[$bucket]['bytes'] += (int) $row->bytes;
                }
            }
        }

        $series = [];

        foreach ($buckets as $bucket => $values) {
            $series[] = [
                'hour' => CarbonImmutable::createFromFormat('Y-m-d H', $bucket, 'UTC')->startOfHour()->toIso8601String(),
                'files' => $values['files'],
                'bytes' => $values['bytes'],
            ];
        }

        return $series;
    }

    /**
     * The latest files saved on the disk, newest first, with a masked name.
     *
     * @return list<array{key: string, kind: string, name: string, size_bytes: int|null, saved_at: string|null, source: string}>
     */
    private function recent(string $disk): array
    {
        $files = [];

        $messages = MessageAttachment::query()
            ->where('message_attachments.disk', $disk)
            ->where('message_attachments.status', AttachmentStatus::Available)
            ->whereNotNull('message_attachments.path')
            ->leftJoin('messages', 'messages.id', '=', 'message_attachments.message_id')
            ->orderByDesc('message_attachments.id')
            ->limit(self::RECENT_FILES)
            ->toBase()
            ->get([
                'message_attachments.id', 'message_attachments.mime_type', 'message_attachments.file_name',
                'message_attachments.size_bytes', 'message_attachments.created_at', 'messages.direction',
            ]);

        foreach ($messages as $row) {
            $files[] = [
                'key' => 'm-'.$row->id,
                'kind' => $this->fileKind((string) $row->mime_type),
                'name' => $this->maskedName($row->file_name, (string) $row->mime_type),
                'size_bytes' => $row->size_bytes !== null ? (int) $row->size_bytes : null,
                'saved_at' => $this->iso($row->created_at),
                'source' => match ((string) $row->direction) {
                    'inbound' => 'Recibido de un cliente',
                    'outbound' => 'Enviado por el equipo',
                    default => 'Conversaciones',
                },
            ];
        }

        $team = TeamAttachment::query()
            ->where('disk', $disk)
            ->whereNull('purged_at')
            ->whereNotNull('path')
            ->orderByDesc('id')
            ->limit(self::RECENT_FILES)
            ->toBase()
            ->get(['id', 'mime_type', 'original_name', 'size_bytes', 'created_at']);

        foreach ($team as $row) {
            $files[] = [
                'key' => 't-'.$row->id,
                'kind' => $this->fileKind((string) $row->mime_type),
                'name' => $this->maskedName($row->original_name, (string) $row->mime_type),
                'size_bytes' => (int) $row->size_bytes,
                'saved_at' => $this->iso($row->created_at),
                'source' => 'Chat interno',
            ];
        }

        usort($files, fn (array $a, array $b): int => strcmp((string) $b['saved_at'], (string) $a['saved_at']));

        return array_slice($files, 0, self::RECENT_FILES);
    }

    /**
     * Files still kept on other disks (e.g. the server's disk before the
     * NAS was connected): they keep resolving from where they were saved.
     *
     * @return list<array{disk: string, label: string, files: int, bytes: int}>
     */
    private function otherDisks(string $disk): array
    {
        $totals = [];

        $sources = [
            DB::table('message_attachments')->where('status', AttachmentStatus::Available->value)->whereNotNull('path'),
            DB::table('team_attachments')->whereNull('purged_at')->whereNotNull('path'),
        ];

        foreach ($sources as $query) {
            $rows = $query
                ->where('disk', '!=', $disk)
                ->selectRaw('disk, COUNT(*) AS files, COALESCE(SUM(size_bytes), 0) AS bytes')
                ->groupBy('disk')
                ->get();

            foreach ($rows as $row) {
                $name = (string) $row->disk;
                $totals[$name] ??= ['files' => 0, 'bytes' => 0];
                $totals[$name]['files'] += (int) $row->files;
                $totals[$name]['bytes'] += (int) $row->bytes;
            }
        }

        $disks = [];

        foreach ($totals as $name => $values) {
            $disks[] = ['disk' => $name, 'label' => $this->kindLabel($this->kind($name)), 'files' => $values['files'], 'bytes' => $values['bytes']];
        }

        return $disks;
    }

    private function fileKind(string $mime): string
    {
        return match (true) {
            $mime === 'image/webp' => 'sticker',
            str_starts_with($mime, 'image/') => 'image',
            str_starts_with($mime, 'video/') => 'video',
            str_starts_with($mime, 'audio/') => 'audio',
            default => 'document',
        };
    }

    /**
     * "fac…​.pdf": the first characters and the extension, never the whole
     * name (it may carry a customer's name or a folio).
     */
    private function maskedName(mixed $name, string $mime): string
    {
        $name = is_string($name) ? trim(basename(str_replace('\\', '/', $name))) : '';
        $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));

        if ($extension === '' || strlen($extension) > 5 || ! ctype_alnum($extension)) {
            $extension = strtolower((string) preg_replace('/[^a-z0-9]/i', '', substr(strrchr($mime, '/') ?: '', 1)));
            $extension = substr($extension, 0, 5);
        }

        $stem = mb_substr((string) pathinfo($name, PATHINFO_FILENAME), 0, 3);
        $stem = (string) preg_replace('/[^\p{L}\p{N}_-]/u', '', $stem);

        return ($stem !== '' ? $stem : 'archivo').'…'.($extension !== '' ? '.'.$extension : '');
    }

    private function iso(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? Carbon::parse($value, 'UTC')->toIso8601String() : null;
    }
}

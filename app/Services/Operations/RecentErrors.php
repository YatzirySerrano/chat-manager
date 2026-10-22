<?php

namespace App\Services\Operations;

use Carbon\CarbonImmutable;
use Throwable;

/**
 * Last error-level entries of the application log, for the system page.
 * Reads only the tail of the newest log file (never the whole file), keeps
 * the first line of each entry (no stack traces, no context arrays) and
 * masks secrets and personal data with SensitiveText.
 *
 * @phpstan-type RecentError array{at: string|null, level: string, message: string}
 */
class RecentErrors
{
    private const LEVELS = ['ERROR', 'CRITICAL', 'ALERT', 'EMERGENCY'];

    /**
     * @return array{file: string|null, entries: list<RecentError>}
     */
    public function latest(?int $limit = null): array
    {
        $limit ??= (int) config('operations.recent_errors.limit', 20);
        $file = $this->newestLogFile();

        if ($file === null) {
            return ['file' => null, 'entries' => []];
        }

        $entries = [];

        foreach (array_reverse($this->tail($file)) as $line) {
            if (preg_match('/^\[([^\]]+)\]\s+[\w.-]+\.('.implode('|', self::LEVELS).'):\s?(.*)$/', $line, $matches) !== 1) {
                continue;
            }

            $entries[] = [
                'at' => $this->parseDate($matches[1]),
                'level' => strtolower($matches[2]),
                // The JSON context Monolog appends may hold ids and request data.
                'message' => SensitiveText::scrub((string) preg_replace('/\s+(\{.*\}|\[.*\])\s*(\[\])?\s*$/', '', $matches[3]), 300),
            ];

            if (count($entries) >= $limit) {
                break;
            }
        }

        return ['file' => basename($file), 'entries' => $entries];
    }

    private function newestLogFile(): ?string
    {
        $files = glob(storage_path('logs/*.log')) ?: [];
        $newest = null;
        $newestTime = 0;

        foreach ($files as $file) {
            $time = @filemtime($file);

            if ($time !== false && $time > $newestTime && ! str_starts_with(basename($file), 'ops')) {
                $newest = $file;
                $newestTime = $time;
            }
        }

        return $newest;
    }

    /**
     * @return list<string>
     */
    private function tail(string $file): array
    {
        $bytes = max(4096, (int) config('operations.recent_errors.tail_bytes', 262144));
        $handle = @fopen($file, 'rb');

        if ($handle === false) {
            return [];
        }

        try {
            $size = (int) (fstat($handle)['size'] ?? 0);
            $offset = max(0, $size - $bytes);
            fseek($handle, $offset);
            $chunk = (string) stream_get_contents($handle);
        } finally {
            fclose($handle);
        }

        $lines = explode("\n", $chunk);

        if ($offset > 0) {
            array_shift($lines);
        }

        return array_values(array_filter($lines, fn (string $line): bool => str_starts_with($line, '[')));
    }

    private function parseDate(string $value): ?string
    {
        try {
            return CarbonImmutable::parse($value)->toIso8601String();
        } catch (Throwable) {
            return null;
        }
    }
}

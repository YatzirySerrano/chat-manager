<?php

namespace App\Services\Storage;

/**
 * Reads the real mount table (/proc/self/mountinfo) and stat() device ids.
 */
class SystemMountInspector implements MountInspector
{
    private const MOUNT_TABLE = '/proc/self/mountinfo';

    public function canReadMountTable(): bool
    {
        return is_readable(self::MOUNT_TABLE);
    }

    public function mountedType(string $path): ?string
    {
        $table = @file_get_contents(self::MOUNT_TABLE);

        if (! is_string($table)) {
            return null;
        }

        return self::typeFromMountTable($table, $path);
    }

    /**
     * Type of the topmost mount at the path in mountinfo content: fields are
     * "id parent major:minor root mountpoint options [optional...] - type
     * source super-options"; the mountpoint escapes spaces as \040.
     */
    public static function typeFromMountTable(string $table, string $path): ?string
    {
        $wanted = self::normalize($path);
        $type = null;

        foreach (preg_split('/\R/', $table) ?: [] as $line) {
            $separator = strpos($line, ' - ');

            if ($separator === false) {
                continue;
            }

            $fields = explode(' ', substr($line, 0, $separator));
            $after = explode(' ', substr($line, $separator + 3));

            if (! isset($fields[4]) || $after[0] === '') {
                continue;
            }

            $mountpoint = preg_replace_callback('/\\\\([0-7]{3})/', fn (array $match): string => chr((int) octdec($match[1])), $fields[4]);

            if (self::normalize((string) $mountpoint) === $wanted) {
                // Later lines are mounted on top of earlier ones (autofs, then cifs).
                $type = $after[0];
            }
        }

        return $type;
    }

    public function deviceIds(string $path): ?array
    {
        $path = self::normalize($path);
        $own = @stat($path);
        $parent = @stat(dirname($path));

        if ($own === false || $parent === false) {
            return null;
        }

        return ['path' => (int) $own['dev'], 'parent' => (int) $parent['dev']];
    }

    private static function normalize(string $path): string
    {
        $trimmed = rtrim($path, '/');

        return $trimmed === '' ? '/' : $trimmed;
    }
}

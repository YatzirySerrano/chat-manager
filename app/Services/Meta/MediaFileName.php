<?php

namespace App\Services\Meta;

use Illuminate\Support\Str;

/**
 * Safe display names for customer files: the name is only shown and offered
 * as a download name; files are always stored under a random path.
 */
final class MediaFileName
{
    public static function sanitize(string $name): ?string
    {
        $name = basename(str_replace('\\', '/', $name));
        $name = (string) preg_replace('/[\x00-\x1F\x7F"<>:|?*\/\\\\]+/u', '', $name);
        $name = trim($name, " .\t");

        return $name === '' ? null : Str::limit($name, 180, '');
    }

    /**
     * Download name of a stored file that always ends in the extension of its
     * real type ("contrato" with application/pdf becomes "contrato.pdf").
     */
    public static function withExtension(?string $name, string $mime, string $fallback = 'archivo'): string
    {
        $name = self::sanitize((string) $name) ?? $fallback;
        $extension = self::extensionFor(strtolower($mime));

        $current = strtolower(pathinfo($name, PATHINFO_EXTENSION));

        if ($extension === null || $current === $extension || in_array($current.'>'.$extension, ['jpeg>jpg', 'mp4>m4a', 'opus>ogg', 'oga>ogg', '3gpp>3gp'], true)) {
            return $name;
        }

        return $name.'.'.$extension;
    }

    /**
     * Extension for a detected MIME type, used for download names.
     */
    public static function extensionFor(string $mime): ?string
    {
        return match ($mime) {
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
            'image/gif' => 'gif',
            'audio/aac' => 'aac',
            'audio/wav' => 'wav',
            'audio/webm' => 'weba',
            'video/quicktime' => 'mov',
            'video/webm' => 'webm',
            'video/x-msvideo' => 'avi',
            'text/csv' => 'csv',
            'audio/mp4' => 'm4a',
            'audio/mpeg' => 'mp3',
            'audio/amr' => 'amr',
            'audio/ogg', 'audio/opus', 'video/ogg' => 'ogg',
            'video/mp4' => 'mp4',
            'video/3gpp' => '3gp',
            'application/pdf' => 'pdf',
            'text/plain' => 'txt',
            'application/msword' => 'doc',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
            'application/vnd.ms-excel' => 'xls',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'xlsx',
            'application/vnd.ms-powerpoint' => 'ppt',
            'application/vnd.openxmlformats-officedocument.presentationml.presentation' => 'pptx',
            'application/zip' => 'zip',
            default => null,
        };
    }
}

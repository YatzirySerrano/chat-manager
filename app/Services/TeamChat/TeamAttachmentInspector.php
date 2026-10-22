<?php

namespace App\Services\TeamChat;

use App\Enums\TeamChat\TeamAttachmentKind;
use finfo;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use ZipArchive;

/**
 * Decides whether an uploaded file may be attached to an internal message,
 * from its real content (finfo), never from the name or the type the
 * browser declares. The extension must also match the detected type, so a
 * renamed executable or HTML page is rejected.
 */
class TeamAttachmentInspector
{
    /**
     * Office Open XML / OpenDocument files are ZIP containers; some libmagic
     * builds only report the container.
     *
     * @var array<string, string>
     */
    private const ZIP_DOCUMENTS = [
        'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
        'odt' => 'application/vnd.oasis.opendocument.text',
        'ods' => 'application/vnd.oasis.opendocument.spreadsheet',
    ];

    /**
     * The accepted MIME type of the file, or null when it is not allowed.
     */
    public function acceptedMimeType(UploadedFile $file): ?string
    {
        $path = $file->getRealPath();

        if ($path === false || ! is_file($path)) {
            return null;
        }

        $detected = (new finfo(FILEINFO_MIME_TYPE))->file($path);

        if (! is_string($detected)) {
            return null;
        }

        $extension = Str::lower($file->getClientOriginalExtension());
        $mime = $this->normalize($detected, $extension, $path);

        /** @var array<string, list<string>> $types */
        $types = (array) config('team-chat.attachments.types', []);

        if (! array_key_exists($mime, $types) || ! in_array($extension, $types[$mime], true)) {
            return null;
        }

        return $mime;
    }

    public function kindOf(string $mime): TeamAttachmentKind
    {
        return match (true) {
            str_starts_with($mime, 'image/') => TeamAttachmentKind::Image,
            str_starts_with($mime, 'audio/') => TeamAttachmentKind::Audio,
            str_starts_with($mime, 'video/') => TeamAttachmentKind::Video,
            default => TeamAttachmentKind::Document,
        };
    }

    /**
     * A display name safe for headers and the interface: no path, no
     * control characters, bounded length, original extension kept.
     */
    public function safeName(UploadedFile $file): string
    {
        $name = basename(str_replace('\\', '/', $file->getClientOriginalName()));
        $name = (string) preg_replace('/[\x00-\x1F\x7F"\/\\\\<>:|?*]+/u', '', $name);
        $name = trim(Str::squish($name), ' .');

        if ($name === '') {
            $name = 'archivo';
        }

        $extension = Str::lower($file->getClientOriginalExtension());
        $base = $extension === '' ? $name : Str::beforeLast($name, '.');

        return Str::limit($base, 150, '').($extension === '' ? '' : '.'.$extension);
    }

    private function normalize(string $detected, string $extension, string $path): string
    {
        if (in_array($detected, ['application/zip', 'application/octet-stream'], true)
            && array_key_exists($extension, self::ZIP_DOCUMENTS)
            && $this->isZipDocument($path, $extension)) {
            return self::ZIP_DOCUMENTS[$extension];
        }

        if ($extension === 'csv' && in_array($detected, ['text/plain', 'application/csv', 'text/x-csv'], true)) {
            return 'text/csv';
        }

        return match ($detected) {
            'audio/x-m4a' => 'audio/mp4',
            'audio/mp3' => 'audio/mpeg',
            default => $detected,
        };
    }

    /**
     * The ZIP really is an Office/OpenDocument file (it has the manifest of
     * its format), not an arbitrary archive with a document extension.
     */
    private function isZipDocument(string $path, string $extension): bool
    {
        if (! class_exists(ZipArchive::class)) {
            return false;
        }

        $zip = new ZipArchive;

        if ($zip->open($path, ZipArchive::RDONLY) !== true) {
            return false;
        }

        $manifest = in_array($extension, ['odt', 'ods'], true) ? 'mimetype' : '[Content_Types].xml';
        $isDocument = $zip->locateName($manifest) !== false;
        $zip->close();

        return $isDocument;
    }
}

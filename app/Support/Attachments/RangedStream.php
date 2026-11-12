<?php

namespace App\Support\Attachments;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Streams a file of an external disk honoring a single byte range
 * (`Range: bytes=a-b`, `a-`, `-n`), like a local file response does.
 *
 * Players seek with ranges and Safari refuses a video answered without
 * them; without this a remote video was always sent whole. The file is
 * copied in small chunks, never read into memory. The stream is opened only
 * when the body is sent, so a 304 or a HEAD request opens nothing.
 */
final class RangedStream
{
    private const CHUNK_BYTES = 64 * 1024;

    /**
     * @param  Closure(): (resource|null)  $open  Opens the file for reading.
     * @param  array<string, string>  $headers
     */
    public static function response(Request $request, Closure $open, int $size, array $headers): Response
    {
        $range = self::range($request, $size);
        $headers['Accept-Ranges'] = 'bytes';

        if ($range === false) {
            return new Response('', Response::HTTP_REQUESTED_RANGE_NOT_SATISFIABLE, [...$headers, 'Content-Range' => "bytes */{$size}"]);
        }

        [$start, $end] = $range ?? [0, max(0, $size - 1)];
        $length = $size === 0 ? 0 : $end - $start + 1;

        $headers['Content-Length'] = (string) $length;

        if ($range !== null) {
            $headers['Content-Range'] = "bytes {$start}-{$end}/{$size}";
        }

        return new StreamedResponse(function () use ($open, $start, $length): void {
            $stream = $length > 0 ? $open() : null;

            if (! is_resource($stream)) {
                return;
            }

            try {
                self::skip($stream, $start);
                $remaining = $length;

                while ($remaining > 0 && ! feof($stream)) {
                    $chunk = fread($stream, min(self::CHUNK_BYTES, $remaining));

                    if ($chunk === false || $chunk === '') {
                        break;
                    }

                    $remaining -= strlen($chunk);
                    echo $chunk;
                    flush();

                    if (connection_aborted() === 1) {
                        break;
                    }
                }
            } finally {
                fclose($stream);
            }
        }, $range === null ? Response::HTTP_OK : Response::HTTP_PARTIAL_CONTENT, $headers);
    }

    /**
     * The requested range as [first byte, last byte]; null when the whole
     * file is asked for (no header, a unit other than bytes, several ranges,
     * or `If-Range`, which this endpoint does not evaluate); false when the
     * range cannot be satisfied.
     *
     * @return array{int, int}|false|null
     */
    public static function range(Request $request, int $size): array|false|null
    {
        $header = trim((string) $request->headers->get('Range', ''));

        if ($header === '' || $request->headers->has('If-Range') || ! $request->isMethod('GET')) {
            return null;
        }

        if (preg_match('/^bytes=(\d*)-(\d*)$/', $header, $matches) !== 1 || ($matches[1] === '' && $matches[2] === '')) {
            return null;
        }

        if ($matches[1] === '') {
            // The last n bytes.
            $suffix = (int) $matches[2];

            return $suffix === 0 || $size === 0 ? false : [max(0, $size - $suffix), $size - 1];
        }

        $start = (int) $matches[1];
        $end = $matches[2] === '' ? $size - 1 : min((int) $matches[2], $size - 1);

        return $start >= $size || $start > $end ? false : [$start, $end];
    }

    /**
     * Moves to the first byte: by seeking when the stream allows it, by
     * reading and dropping otherwise (a remote object stream).
     *
     * @param  resource  $stream
     */
    private static function skip($stream, int $bytes): void
    {
        if ($bytes === 0) {
            return;
        }

        if (stream_get_meta_data($stream)['seekable'] && fseek($stream, $bytes) === 0) {
            return;
        }

        while ($bytes > 0 && ! feof($stream)) {
            $dropped = fread($stream, min(self::CHUNK_BYTES, $bytes));

            if ($dropped === false || $dropped === '') {
                break;
            }

            $bytes -= strlen($dropped);
        }
    }
}

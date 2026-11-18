<?php

namespace App\Services\LinkPreviews;

use GuzzleHttp\Psr7\Uri;
use GuzzleHttp\Psr7\UriResolver;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Psr\Http\Message\StreamInterface;
use Throwable;

/**
 * Downloads a public web page or image for a preview, never anything else:
 * every address (the first one and each redirect, followed by hand, at most
 * link_previews.max_redirects) passes SafeUrl, and the connection is pinned
 * to the address SafeUrl checked. Proxies from the environment are ignored.
 * Each request has its own timeout and the whole preview a deadline; only
 * the allowed content types are accepted and at most $maxBytes are read
 * (a page is cut there, a file above it is refused).
 */
class SafeFetcher
{
    public function __construct(private SafeUrl $guard) {}

    /**
     * @param  list<string>  $types  Content types accepted (without parameters).
     * @param  float  $deadline  microtime(true) after which nothing more is requested.
     * @param  bool  $truncate  Keep the first $maxBytes of a larger body (pages) instead of refusing it (files).
     * @return array{url: string, mime: string, charset: string|null, body: string}
     *
     * @throws BlockedAddress
     */
    public function fetch(string $url, array $types, int $maxBytes, float $deadline, bool $truncate = false): array
    {
        $maxRedirects = (int) config('link_previews.max_redirects', 3);

        for ($redirects = 0; ; $redirects++) {
            $target = $this->guard->check($url);
            $remaining = $deadline - microtime(true);

            if ($remaining < 0.5) {
                throw new BlockedAddress('timeout');
            }

            $response = $this->request($target, min((float) config('link_previews.timeout_seconds', 4), $remaining));
            $status = $response->status();

            if (in_array($status, [301, 302, 303, 307, 308], true)) {
                $location = $response->header('Location');
                $response->close();

                if ($location === '' || $redirects >= $maxRedirects) {
                    throw new BlockedAddress('too_many_redirects');
                }

                try {
                    $url = (string) UriResolver::resolve(new Uri($target['url']), new Uri($location));
                } catch (Throwable) {
                    throw new BlockedAddress('invalid_url');
                }

                continue;
            }

            if ($status < 200 || $status >= 300) {
                $response->close();

                throw new BlockedAddress('http_'.$status);
            }

            [$mime, $charset] = self::contentType($response->header('Content-Type'));

            if (! in_array($mime, $types, true)) {
                $response->close();

                throw new BlockedAddress('type_not_allowed');
            }

            $declared = $response->header('Content-Length');

            if (! $truncate && is_numeric($declared) && (int) $declared > $maxBytes) {
                $response->close();

                throw new BlockedAddress('too_large');
            }

            $body = $this->read($response->toPsrResponse()->getBody(), $maxBytes);

            if (strlen($body) > $maxBytes) {
                if (! $truncate) {
                    throw new BlockedAddress('too_large');
                }

                $body = substr($body, 0, $maxBytes);
            }

            return ['url' => $target['url'], 'mime' => $mime, 'charset' => $charset, 'body' => $body];
        }
    }

    /**
     * @param  array{url: string, host: string, port: int, ip: string}  $target
     *
     * @throws BlockedAddress
     */
    private function request(array $target, float $timeout): Response
    {
        $ip = str_contains($target['ip'], ':') ? '['.$target['ip'].']' : $target['ip'];

        try {
            return Http::withOptions([
                'stream' => true,
                'allow_redirects' => false,
                'proxy' => '',
                'curl' => [
                    CURLOPT_RESOLVE => [sprintf('%s:%d:%s', $target['host'], $target['port'], $ip)],
                    CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
                ],
            ])
                ->withHeaders([
                    'User-Agent' => (string) config('link_previews.user_agent'),
                    'Accept' => 'text/html,application/xhtml+xml,image/avif,image/webp,image/png,image/jpeg,*/*;q=0.5',
                    'Accept-Language' => 'es-MX,es;q=0.9,en;q=0.6',
                ])
                ->connectTimeout(max(1, (int) floor(min(3.0, $timeout))))
                ->timeout(max(1, (int) floor($timeout)))
                ->get($target['url']);
        } catch (ConnectionException) {
            throw new BlockedAddress('unreachable');
        } catch (Throwable) {
            throw new BlockedAddress('request_failed');
        }
    }

    /**
     * Reads at most one byte more than the limit, so a larger body is noticed.
     */
    private function read(StreamInterface $stream, int $maxBytes): string
    {
        $body = '';

        try {
            while (! $stream->eof() && strlen($body) <= $maxBytes) {
                $chunk = $stream->read(min(16384, $maxBytes + 1 - strlen($body)));

                if ($chunk === '') {
                    break;
                }

                $body .= $chunk;
            }
        } catch (Throwable) {
            throw new BlockedAddress('read_failed');
        } finally {
            $stream->close();
        }

        return $body;
    }

    /**
     * @return array{0: string, 1: string|null}
     */
    private static function contentType(string $header): array
    {
        $parts = explode(';', $header);
        $mime = strtolower(trim($parts[0]));
        $charset = null;

        foreach (array_slice($parts, 1) as $parameter) {
            if (preg_match('/^\s*charset\s*=\s*"?([A-Za-z0-9._-]+)"?\s*$/i', $parameter, $match) === 1) {
                $charset = strtoupper($match[1]);
            }
        }

        return [$mime, $charset];
    }
}

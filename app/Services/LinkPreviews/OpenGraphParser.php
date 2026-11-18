<?php

namespace App\Services\LinkPreviews;

use DOMDocument;
use GuzzleHttp\Psr7\Uri;
use GuzzleHttp\Psr7\UriResolver;
use Illuminate\Support\Str;
use Throwable;

/**
 * Reads what a page says about itself: Open Graph (og:title,
 * og:description, og:image, og:site_name), with the Twitter card, the
 * <title>, the meta description and the page icon as fallbacks. Only text
 * is taken (tags stripped, whitespace collapsed, lengths bounded); image
 * and icon addresses are made absolute and kept only when they are
 * http/https. Nothing of the page is ever executed or rendered.
 */
class OpenGraphParser
{
    /**
     * @return array{title: string|null, description: string|null, site_name: string|null, image: string|null, icon: string|null}
     */
    public function parse(string $html, string $baseUrl, ?string $charset = null): array
    {
        $html = $this->toUtf8($html, $charset);
        $document = new DOMDocument;
        $previous = libxml_use_internal_errors(true);

        try {
            $document->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_COMPACT);
        } catch (Throwable) {
            return ['title' => null, 'description' => null, 'site_name' => null, 'image' => null, 'icon' => null];
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        $meta = [];

        foreach ($document->getElementsByTagName('meta') as $element) {
            $key = strtolower(trim($element->getAttribute('property') ?: $element->getAttribute('name')));
            $value = $element->getAttribute('content');

            if ($key !== '' && $value !== '' && ! isset($meta[$key])) {
                $meta[$key] = $value;
            }
        }

        $title = $meta['og:title'] ?? $meta['twitter:title'] ?? $this->titleTag($document);
        $image = $meta['og:image:secure_url'] ?? $meta['og:image'] ?? $meta['og:image:url'] ?? $meta['twitter:image'] ?? $meta['twitter:image:src'] ?? null;

        return [
            'title' => self::clean($title, 300),
            'description' => self::clean($meta['og:description'] ?? $meta['twitter:description'] ?? $meta['description'] ?? null, 600),
            'site_name' => self::clean($meta['og:site_name'] ?? $meta['application-name'] ?? null, 150),
            'image' => self::absolute($image, $baseUrl),
            'icon' => self::absolute($this->icon($document), $baseUrl),
        ];
    }

    private function titleTag(DOMDocument $document): ?string
    {
        $title = $document->getElementsByTagName('title')->item(0);

        return $title?->textContent;
    }

    private function icon(DOMDocument $document): ?string
    {
        $best = null;

        foreach ($document->getElementsByTagName('link') as $element) {
            $rel = ' '.strtolower($element->getAttribute('rel')).' ';
            $href = trim($element->getAttribute('href'));

            if ($href === '' || ! str_contains($rel, ' icon ') && ! str_contains($rel, 'apple-touch-icon')) {
                continue;
            }

            // An SVG icon is never kept (see LinkPreviews::ICON_TYPES).
            if (str_contains(strtolower($element->getAttribute('type')), 'svg') || str_ends_with(strtolower(parse_url($href, PHP_URL_PATH) ?: ''), '.svg')) {
                continue;
            }

            $best ??= $href;

            if (str_contains($rel, 'apple-touch-icon')) {
                return $href;
            }
        }

        return $best;
    }

    private function toUtf8(string $html, ?string $charset): string
    {
        if ($charset === null && preg_match('/<meta[^>]+charset\s*=\s*["\']?([A-Za-z0-9._-]+)/i', substr($html, 0, 4096), $match) === 1) {
            $charset = strtoupper($match[1]);
        }

        if ($charset !== null && ! in_array($charset, ['UTF-8', 'UTF8'], true)) {
            try {
                $converted = mb_convert_encoding($html, 'UTF-8', $charset);

                if (is_string($converted)) {
                    return $converted;
                }
            } catch (Throwable) {
                // An unknown charset: read it as UTF-8 below.
            }
        }

        return mb_scrub($html, 'UTF-8');
    }

    public static function clean(?string $text, int $limit): ?string
    {
        if ($text === null) {
            return null;
        }

        $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = trim((string) preg_replace('/[\s\x{00A0}\x{200B}]+/u', ' ', $text));
        $text = (string) preg_replace('/[\x{0000}-\x{001F}\x{007F}]/u', '', $text);

        return $text === '' ? null : Str::limit($text, $limit - 3);
    }

    public static function absolute(?string $url, string $baseUrl): ?string
    {
        $url = $url === null ? '' : trim(html_entity_decode($url, ENT_QUOTES | ENT_HTML5, 'UTF-8'));

        if ($url === '' || strlen($url) > 2048) {
            return null;
        }

        try {
            $resolved = (string) UriResolver::resolve(new Uri($baseUrl), new Uri($url));
        } catch (Throwable) {
            return null;
        }

        return preg_match('#^https?://#i', $resolved) === 1 ? $resolved : null;
    }
}

<?php

namespace App\Services\LinkPreviews;

/**
 * Google Maps (and Apple Maps) addresses: whether a link is one, and the
 * point and place name it names when they are written in it
 * ("@19.43,-99.13,17z", "!3d19.43!4d-99.13", "?q=19.43,-99.13",
 * "/maps/place/Nombre/"). A short link (maps.app.goo.gl) names nothing
 * until its redirects are followed.
 */
class MapsLink
{
    public static function is(string $url): bool
    {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        $path = strtolower((string) parse_url($url, PHP_URL_PATH));

        return $host === 'maps.app.goo.gl'
            || $host === 'maps.apple.com'
            || ($host === 'goo.gl' && str_starts_with($path, '/maps'))
            || preg_match('/^maps\.google\.[a-z.]+$/', $host) === 1
            || (preg_match('/^(www\.)?google\.[a-z.]+$/', $host) === 1 && str_starts_with($path, '/maps'));
    }

    /**
     * @return array{latitude: float, longitude: float}|null
     */
    public static function coordinates(string $url): ?array
    {
        $url = rawurldecode($url);
        $number = '(-?\d{1,3}(?:\.\d+)?)';
        $patterns = [
            "/!3d{$number}!4d{$number}/",
            "/@{$number},{$number}(?:,|$|\/|\?)/",
            "/[?&](?:q|query|ll|center|destination|daddr|sll)={$number},\s*{$number}(?:&|$|,)/",
            "/\/maps\/(?:place|search|dir)\/{$number},\s*{$number}/",
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $url, $match) === 1) {
                $latitude = (float) $match[1];
                $longitude = (float) $match[2];

                if (abs($latitude) <= 90 && abs($longitude) <= 180 && ($latitude !== 0.0 || $longitude !== 0.0)) {
                    return ['latitude' => $latitude, 'longitude' => $longitude];
                }
            }
        }

        return null;
    }

    /** The place name of a "/maps/place/{name}/…" address. */
    public static function placeName(string $url): ?string
    {
        if (preg_match('#/maps/place/([^/@?]+)#', (string) parse_url($url, PHP_URL_PATH), $match) !== 1) {
            return null;
        }

        $name = trim(str_replace('+', ' ', rawurldecode($match[1])));

        return $name === '' || preg_match('/^-?\d+(\.\d+)?,\s*-?\d+(\.\d+)?$/', $name) === 1
            ? null
            : OpenGraphParser::clean($name, 300);
    }
}

<?php

namespace App\Support\Color;

use InvalidArgumentException;

/**
 * WCAG 2.1 contrast between two sRGB colors written as #RRGGBB. The same
 * formulas live in resources/js/lib/contrast.ts, so the editor and the
 * server always agree on what is readable.
 */
final class Contrast
{
    /** Minimum ratio of WCAG 2.1 AA for normal text. */
    public const MINIMUM_RATIO = 4.5;

    /** Pattern of an accepted color: # followed by six hexadecimal digits. */
    public const HEX_PATTERN = '/^#[0-9a-fA-F]{6}$/';

    /** Near-black preferred over pure black when it is readable enough. */
    public const DARK_TEXT = '#111111';

    public const LIGHT_TEXT = '#FFFFFF';

    public static function isHex(mixed $value): bool
    {
        return is_string($value) && preg_match(self::HEX_PATTERN, $value) === 1;
    }

    /**
     * Relative luminance (0 = black, 1 = white).
     */
    public static function luminance(string $hex): float
    {
        if (! self::isHex($hex)) {
            throw new InvalidArgumentException("Invalid color [{$hex}].");
        }

        $channels = array_map(
            static function (string $pair): float {
                $value = hexdec($pair) / 255;

                return $value <= 0.04045 ? $value / 12.92 : (($value + 0.055) / 1.055) ** 2.4;
            },
            str_split(substr($hex, 1), 2),
        );

        return 0.2126 * $channels[0] + 0.7152 * $channels[1] + 0.0722 * $channels[2];
    }

    /**
     * Contrast ratio between two colors, from 1 (none) to 21.
     */
    public static function ratio(string $first, string $second): float
    {
        $a = self::luminance($first);
        $b = self::luminance($second);

        return (max($a, $b) + 0.05) / (min($a, $b) + 0.05);
    }

    public static function isReadable(string $text, string $background): bool
    {
        return self::ratio($text, $background) >= self::MINIMUM_RATIO;
    }

    /**
     * The most readable text color for the background: near-black or white,
     * whichever contrasts more, and pure black when near-black falls short.
     */
    public static function readableTextOn(string $background): string
    {
        $dark = self::ratio(self::DARK_TEXT, $background);
        $light = self::ratio(self::LIGHT_TEXT, $background);

        return match (true) {
            $light >= self::MINIMUM_RATIO && $light >= $dark => self::LIGHT_TEXT,
            $dark >= self::MINIMUM_RATIO => self::DARK_TEXT,
            $light >= self::MINIMUM_RATIO => self::LIGHT_TEXT,
            // White and black always reach 4.58:1 between them.
            default => '#000000',
        };
    }
}

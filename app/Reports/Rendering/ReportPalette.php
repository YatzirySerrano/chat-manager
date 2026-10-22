<?php

namespace App\Reports\Rendering;

/**
 * Fixed institutional colors shared by the XLSX and PDF renderers. Reports
 * never follow the app theme (light/dark or palette): printed documents
 * keep the same look everywhere. Values are the light-mode tokens of
 * resources/css/app.css (emerald accent, slate neutrals).
 */
final class ReportPalette
{
    public const ACCENT = '#008251';

    public const ACCENT_DARK = '#006435';

    public const ACCENT_SOFT = '#E6F5EE';

    public const INK = '#121921';

    public const MUTED = '#616A75';

    public const SUBTLE = '#8A929C';

    public const BORDER = '#E3E7EA';

    public const ZEBRA = '#F6F8F9';

    public const SURFACE = '#FFFFFF';

    public const NEUTRAL_MARK = '#C3CAD1';

    /**
     * Categorical order for charts with several categories or series
     * (validated for color-vision deficiencies; always paired with labels).
     *
     * @var list<string>
     */
    public const SERIES = ['#179765', '#2B88C0', '#D58D25', '#8968D4', '#D8516A'];

    /**
     * Color for the n-th category; beyond the palette categories turn gray.
     */
    public static function series(int $index): string
    {
        return self::SERIES[$index] ?? self::NEUTRAL_MARK;
    }

    /**
     * Hex without "#", as PhpSpreadsheet expects (RGB).
     */
    public static function rgb(string $hex): string
    {
        return strtoupper(ltrim($hex, '#'));
    }
}

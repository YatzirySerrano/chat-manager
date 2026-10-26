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
     * Categorical order for charts with several categories or series: the
     * fixed light-mode categorical chart tokens of the app (--chart-cat-1…8,
     * validated for color-vision deficiencies; always paired with labels).
     *
     * @var list<string>
     */
    public const SERIES = ['#2A78D6', '#EB6834', '#15936A', '#B87A00', '#D55181', '#008300', '#6250D6', '#E34948'];

    /**
     * Status colors of the screen (useChartTheme statusColor): pending is
     * the warning ink, in attention the first categorical hue, closed the
     * muted ink.
     *
     * @var array<string, string>
     */
    public const STATUS = [
        'pending' => '#8D4F00',
        'open' => '#2A78D6',
        'on_hold' => '#2A78D6',
        'closed' => self::MUTED,
    ];

    /**
     * Channel colors of the screen (useChartTheme channelColor): a channel is
     * an entity, so it keeps its color whatever its rank.
     *
     * @var array<string, string>
     */
    public const CHANNEL = [
        'web_chat' => '#2A78D6',
        'whatsapp' => '#15936A',
        'messenger' => '#6250D6',
        'instagram' => '#D55181',
    ];

    /**
     * Color for the n-th category; beyond the palette categories turn gray.
     */
    public static function series(int $index): string
    {
        return self::SERIES[$index] ?? self::NEUTRAL_MARK;
    }

    public static function status(string $status): string
    {
        return self::STATUS[$status] ?? self::MUTED;
    }

    public static function channel(?string $channel): string
    {
        return $channel === null ? self::MUTED : (self::CHANNEL[$channel] ?? self::MUTED);
    }

    /**
     * Hex without "#", as PhpSpreadsheet expects (RGB).
     */
    public static function rgb(string $hex): string
    {
        return strtoupper(ltrim($hex, '#'));
    }
}

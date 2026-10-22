import type { StyleValue } from 'vue';
import { swatchClasses } from '@/lib/swatches';
import type { AppColor, ColorSwatch, HexColor } from '@/types';

/**
 * Class and inline style to bind on an element (`v-bind="presentation"`).
 * Palette swatches only use Tailwind classes; custom hex colors use inline
 * styles plus CSS variables for the light/dark foreground.
 */
export type ColorPresentation = {
    class: string;
    style?: StyleValue;
};

export type ColorPresentations = {
    /** Small solid indicator (dot, color circle). */
    dot: ColorPresentation;
    /** Tinted background with a readable foreground (chips, icon tiles). */
    soft: ColorPresentation;
    /** Solid background with white or black text, whichever reads better. */
    solid: ColorPresentation;
    /** Human readable name of the color. */
    label: string;
};

const HEX_PATTERN = /^#[0-9a-f]{6}$/i;

/** Opacity of the tinted background, matching the palette's `/10`-ish soft tone. */
const SOFT_ALPHA = 0.14;

/** WCAG AA for normal text. */
const MIN_CONTRAST = 4.5;

/**
 * Approximate surfaces the chips sit on (see resources/css/app.css). For each
 * theme the one that gives the lowest contrast is used, so the result reads on
 * cards and on the page background alike.
 */
const LIGHT_SURFACE: Rgb = [244, 246, 249];
const DARK_SURFACE: Rgb = [30, 33, 39];

type Rgb = [number, number, number];

export function isHexColor(value: unknown): value is HexColor {
    return typeof value === 'string' && HEX_PATTERN.test(value);
}

export function isPaletteColor(value: unknown): value is ColorSwatch {
    return typeof value === 'string' && value in swatchClasses;
}

/** Lowercase "#rrggbb", the format the server stores. */
export function normalizeHex(value: string): HexColor {
    return value.toLowerCase() as HexColor;
}

function toRgb(hex: HexColor): Rgb {
    return [1, 3, 5].map((start) =>
        Number.parseInt(hex.slice(start, start + 2), 16),
    ) as Rgb;
}

function toHex([red, green, blue]: Rgb): string {
    return `#${[red, green, blue]
        .map((channel) => Math.round(channel).toString(16).padStart(2, '0'))
        .join('')}`;
}

function relativeLuminance(rgb: Rgb): number {
    const [red, green, blue] = rgb.map((channel) => {
        const value = channel / 255;

        return value <= 0.03928
            ? value / 12.92
            : ((value + 0.055) / 1.055) ** 2.4;
    });

    return 0.2126 * red + 0.7152 * green + 0.0722 * blue;
}

/** WCAG 2 contrast ratio between two colors. */
export function contrastRatio(first: Rgb, second: Rgb): number {
    const lighter = Math.max(
        relativeLuminance(first),
        relativeLuminance(second),
    );
    const darker = Math.min(
        relativeLuminance(first),
        relativeLuminance(second),
    );

    return (lighter + 0.05) / (darker + 0.05);
}

function mix(color: Rgb, target: Rgb, amount: number): Rgb {
    return color.map(
        (channel, index) => channel + (target[index] - channel) * amount,
    ) as Rgb;
}

/**
 * Shade (towards black) or tint (towards white) the color step by step until
 * it reaches the minimum contrast against the background, keeping its hue.
 */
function readableOn(color: Rgb, background: Rgb, towards: Rgb): string {
    for (let step = 0; step <= 20; step++) {
        const candidate = mix(color, towards, step / 20);

        if (contrastRatio(candidate, background) >= MIN_CONTRAST) {
            return toHex(candidate);
        }
    }

    return toHex(towards);
}

function customPresentations(hex: HexColor): ColorPresentations {
    const rgb = toRgb(hex);
    const lightBackground = mix(LIGHT_SURFACE, rgb, SOFT_ALPHA);
    const darkBackground = mix(DARK_SURFACE, rgb, SOFT_ALPHA);
    const alpha = Math.round(SOFT_ALPHA * 255)
        .toString(16)
        .padStart(2, '0');

    return {
        dot: { class: '', style: { backgroundColor: hex } },
        soft: {
            class: 'text-(--app-color-fg) dark:text-(--app-color-fg-dark)',
            style: {
                backgroundColor: `${hex}${alpha}`,
                '--app-color-fg': readableOn(rgb, lightBackground, [0, 0, 0]),
                '--app-color-fg-dark': readableOn(
                    rgb,
                    darkBackground,
                    [255, 255, 255],
                ),
            },
        },
        solid: {
            class: '',
            style: {
                backgroundColor: hex,
                color: solidForeground(hex) === 'white' ? '#ffffff' : '#000000',
            },
        },
        label: `Color personalizado ${hex}`,
    };
}

/**
 * Presentation of a department or tag color, whether it is a palette key or
 * a custom "#rrggbb" color. Unknown values fall back to the neutral swatch.
 */
export function colorPresentations(
    color: AppColor | null | undefined,
): ColorPresentations {
    if (isHexColor(color)) {
        return customPresentations(normalizeHex(color));
    }

    const swatch = swatchClasses[isPaletteColor(color) ? color : 'slate'];

    return {
        dot: { class: swatch.dot },
        soft: { class: swatch.soft },
        solid: { class: swatch.solid },
        label: swatch.label,
    };
}

/** Text color (white or black) that reads best on top of a solid color. */
export function solidForeground(color: AppColor): 'white' | 'black' {
    if (!isHexColor(color)) {
        return 'white';
    }

    const rgb = toRgb(normalizeHex(color));

    return contrastRatio(rgb, [255, 255, 255]) >= contrastRatio(rgb, [0, 0, 0])
        ? 'white'
        : 'black';
}

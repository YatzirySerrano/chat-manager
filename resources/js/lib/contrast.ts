/**
 * WCAG 2.1 contrast between two sRGB colors written as #RRGGBB. Same
 * formulas as app/Support/Color/Contrast.php, so the editor warns exactly
 * when the server would adjust a color.
 */

/** Minimum ratio of WCAG 2.1 AA for normal text. */
export const MINIMUM_RATIO = 4.5;

/** Near-black preferred over pure black when it is readable enough. */
export const DARK_TEXT = '#111111';
export const LIGHT_TEXT = '#FFFFFF';

const HEX_PATTERN = /^#[0-9a-fA-F]{6}$/;

export function isHex(value: unknown): value is string {
    return typeof value === 'string' && HEX_PATTERN.test(value);
}

/** Relative luminance (0 = black, 1 = white). */
export function luminance(hex: string): number {
    if (!HEX_PATTERN.test(hex)) {
        throw new Error(`Invalid color [${hex}].`);
    }

    const [r, g, b] = [1, 3, 5].map((start) => {
        const value = parseInt(hex.slice(start, start + 2), 16) / 255;

        return value <= 0.04045
            ? value / 12.92
            : ((value + 0.055) / 1.055) ** 2.4;
    });

    return 0.2126 * r + 0.7152 * g + 0.0722 * b;
}

/** Contrast ratio between two colors, from 1 (none) to 21. */
export function contrastRatio(first: string, second: string): number {
    const a = luminance(first);
    const b = luminance(second);

    return (Math.max(a, b) + 0.05) / (Math.min(a, b) + 0.05);
}

export function isReadable(text: string, background: string): boolean {
    return contrastRatio(text, background) >= MINIMUM_RATIO;
}

/**
 * The most readable text color for the background: near-black or white,
 * whichever contrasts more, and pure black when near-black falls short.
 */
export function readableTextOn(background: string): string {
    const dark = contrastRatio(DARK_TEXT, background);
    const light = contrastRatio(LIGHT_TEXT, background);

    if (light >= MINIMUM_RATIO && light >= dark) {
        return LIGHT_TEXT;
    }

    if (dark >= MINIMUM_RATIO) {
        return DARK_TEXT;
    }

    if (light >= MINIMUM_RATIO) {
        return LIGHT_TEXT;
    }

    // White and black always reach 4.58:1 between them.
    return '#000000';
}

/** Ratio rounded down to one decimal, for display ("4.4:1"). */
export function formatRatio(ratio: number): string {
    return `${(Math.floor(ratio * 10) / 10).toFixed(1)}:1`;
}

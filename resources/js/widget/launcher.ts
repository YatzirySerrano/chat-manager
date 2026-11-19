/**
 * Look of the floating button the loader draws on the host page, shared
 * with the admin preview (WebChatPreview) so both draw exactly the same
 * button. Dependency free and small: it ships inside the loader. The icons
 * are inline SVG (no external assets, CSP friendly).
 */
export type LauncherShape = 'circle' | 'rounded' | 'pill';
export type LauncherIcon = 'chat' | 'message' | 'headset' | 'help';
export type LauncherSize = 'small' | 'medium' | 'large';

export const LAUNCHER_SHAPES: readonly LauncherShape[] = [
    'circle',
    'rounded',
    'pill',
];
export const LAUNCHER_ICONS: readonly LauncherIcon[] = [
    'chat',
    'message',
    'headset',
    'help',
];
export const LAUNCHER_SIZES: readonly LauncherSize[] = [
    'small',
    'medium',
    'large',
];

/** Bounds mirrored from App\Models\WebChatWidget. */
export const MAX_LAUNCHER_OFFSET = 120;
export const LAUNCHER_WIDTH = { min: 48, max: 260 } as const;
export const PANEL_WIDTH = { min: 300, max: 480, default: 380 } as const;
export const PANEL_HEIGHT = { min: 420, max: 720, default: 600 } as const;

export type LauncherStyle = {
    launcher_color: string | null;
    launcher_icon_color: string | null;
    launcher_shape: LauncherShape;
    launcher_icon: LauncherIcon;
    launcher_size: LauncherSize;
    launcher_offset: { x: number; y: number };
    launcher_pulse: boolean;
    launcher_width: number | null;
    launcher_font_size: LauncherSize;
    panel: { width: number; height: number };
};

export const DEFAULT_LAUNCHER_STYLE: LauncherStyle = {
    launcher_color: null,
    launcher_icon_color: null,
    launcher_shape: 'circle',
    launcher_icon: 'chat',
    launcher_size: 'medium',
    launcher_offset: { x: 0, y: 0 },
    launcher_pulse: false,
    launcher_width: null,
    launcher_font_size: 'medium',
    panel: { width: PANEL_WIDTH.default, height: PANEL_HEIGHT.default },
};

const SVG_OPEN =
    '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">';

/** Inner markup of each icon (24×24, stroked with currentColor). */
const ICON_PATHS: Record<LauncherIcon, string> = {
    chat: '<path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/>',
    message:
        '<path d="M7.9 20A9 9 0 1 0 4 16.1L2 22z"/><path transform="translate(7.2 7.2) scale(.4)" stroke-width="4.5" d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72 12.84 12.84 0 0 0 .7 2.81 2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45 12.84 12.84 0 0 0 2.81.7A2 2 0 0 1 22 16.92z"/>',
    headset:
        '<path d="M3 14h3a2 2 0 0 1 2 2v3a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-7a9 9 0 0 1 18 0v7a2 2 0 0 1-2 2h-1a2 2 0 0 1-2-2v-3a2 2 0 0 1 2-2h3"/>',
    help: '<circle cx="12" cy="12" r="10"/><path d="M9.1 9a3 3 0 0 1 5.8 1c0 2-3 3-3 3"/><path d="M12 17h.01"/>',
};

export function launcherIconSvg(icon: LauncherIcon): string {
    return SVG_OPEN + (ICON_PATHS[icon] ?? ICON_PATHS.chat) + '</svg>';
}

const HEIGHTS: Record<LauncherSize, number> = {
    small: 48,
    medium: 56,
    large: 64,
};
const ICON_SIZES: Record<LauncherSize, number> = {
    small: 20,
    medium: 24,
    large: 28,
};
const FONT_SIZES: Record<LauncherSize, number> = {
    small: 13,
    medium: 15,
    large: 17,
};

/** Text color (#fff or #111827) readable on a #rrggbb background. */
export function readableOn(hex: string): string {
    const value = parseInt(hex.slice(1), 16);
    const channels = [value >> 16, (value >> 8) & 255, value & 255].map(
        (channel) => {
            const c = channel / 255;

            return c <= 0.03928 ? c / 12.92 : ((c + 0.055) / 1.055) ** 2.4;
        },
    );
    const luminance =
        0.2126 * channels[0] + 0.7152 * channels[1] + 0.0722 * channels[2];

    return luminance > 0.4 ? '#111827' : '#ffffff';
}

export type LauncherMetrics = {
    background: string;
    color: string;
    height: number;
    iconSize: number;
    fontSize: number;
    radius: number;
    /** Fixed width in px, or null: square (circle, rounded) or fits its text (pill). */
    width: number | null;
    /** Whether the text label is drawn (only pills carry text). */
    showLabel: boolean;
};

/** Everything needed to draw the button, from the accent and its style. */
export function launcherMetrics(
    accent: string,
    style: LauncherStyle,
    label: string | null,
): LauncherMetrics {
    const background = style.launcher_color ?? accent;
    const height = HEIGHTS[style.launcher_size] ?? HEIGHTS.medium;
    const pill = style.launcher_shape === 'pill';
    const showLabel = pill && !!label && label.trim() !== '';

    return {
        background,
        color: style.launcher_icon_color ?? readableOn(background),
        height,
        iconSize: ICON_SIZES[style.launcher_size] ?? ICON_SIZES.medium,
        fontSize: FONT_SIZES[style.launcher_font_size] ?? FONT_SIZES.medium,
        radius:
            style.launcher_shape === 'rounded'
                ? Math.round(height * 0.3)
                : height / 2,
        width: pill ? style.launcher_width : height,
        showLabel,
    };
}

function isColor(value: unknown): value is string {
    return typeof value === 'string' && /^#[0-9a-fA-F]{6}$/.test(value);
}

function bounded(value: unknown, min: number, max: number): number | null {
    const number = Number(value);

    return Number.isFinite(number)
        ? Math.min(max, Math.max(min, Math.round(number)))
        : null;
}

function oneOf<T extends string>(
    value: unknown,
    options: readonly T[],
    fallback: T,
): T {
    return options.includes(value as T) ? (value as T) : fallback;
}

/**
 * Validates the button's part of an appearance, falling back to the
 * defaults (the original look) for anything missing or invalid.
 */
export function readLauncherStyle(value: unknown): LauncherStyle {
    const a = (
        typeof value === 'object' && value !== null ? value : {}
    ) as Record<string, unknown>;
    const offset = (
        typeof a.launcher_offset === 'object' && a.launcher_offset !== null
            ? a.launcher_offset
            : {}
    ) as Record<string, unknown>;
    const panel = (
        typeof a.panel === 'object' && a.panel !== null ? a.panel : {}
    ) as Record<string, unknown>;

    return {
        launcher_color: isColor(a.launcher_color) ? a.launcher_color : null,
        launcher_icon_color: isColor(a.launcher_icon_color)
            ? a.launcher_icon_color
            : null,
        launcher_shape: oneOf(a.launcher_shape, LAUNCHER_SHAPES, 'circle'),
        launcher_icon: oneOf(a.launcher_icon, LAUNCHER_ICONS, 'chat'),
        launcher_size: oneOf(a.launcher_size, LAUNCHER_SIZES, 'medium'),
        launcher_offset: {
            x: bounded(offset.x, 0, MAX_LAUNCHER_OFFSET) ?? 0,
            y: bounded(offset.y, 0, MAX_LAUNCHER_OFFSET) ?? 0,
        },
        launcher_pulse: a.launcher_pulse === true,
        launcher_width:
            a.launcher_width === null || a.launcher_width === undefined
                ? null
                : bounded(
                      a.launcher_width,
                      LAUNCHER_WIDTH.min,
                      LAUNCHER_WIDTH.max,
                  ),
        launcher_font_size: oneOf(
            a.launcher_font_size,
            LAUNCHER_SIZES,
            'medium',
        ),
        panel: {
            width:
                bounded(panel.width, PANEL_WIDTH.min, PANEL_WIDTH.max) ??
                PANEL_WIDTH.default,
            height:
                bounded(panel.height, PANEL_HEIGHT.min, PANEL_HEIGHT.max) ??
                PANEL_HEIGHT.default,
        },
    };
}

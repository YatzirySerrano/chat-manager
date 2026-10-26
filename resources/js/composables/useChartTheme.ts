import { usePreferredReducedMotion } from '@vueuse/core';
import { computed, ref } from 'vue';
import type { ComputedRef, Ref } from 'vue';
import {
    formatDuration as formatLongDuration,
    formatTimeZone,
} from '@/lib/format';
import type { ActivityGranularity } from '@/types/charts';
import type { ConnectionChannel } from '@/types/connections';

/**
 * Shared look and formats of every chart (components/charts). Marks are
 * painted with CSS variables (`var(--chart-primary)`…) so light/dark and the
 * platform palette apply without JavaScript; `colors` resolves the same
 * tokens to concrete values for whatever needs them outside of CSS, and is
 * recomputed when <html> switches theme or palette.
 */
export type ChartColors = {
    primary: string;
    secondary: string;
    comparison: string;
    grid: string;
    axis: string;
    text: string;
    muted: string;
    surface: string;
    categorical: string[];
    status: Record<'pending' | 'open' | 'closed' | 'failed', string>;
};

export type BucketLabel = { short: string; long: string };

/** The CSS variable of each token (what the SVG marks use). */
export const chartTokens = {
    primary: 'var(--chart-primary)',
    secondary: 'var(--chart-secondary)',
    comparison: 'var(--chart-comparison)',
    grid: 'var(--chart-grid)',
    axis: 'var(--chart-axis)',
    surface: 'var(--surface-raised)',
    categorical: Array.from(
        { length: 8 },
        (_, index) => `var(--chart-cat-${index + 1})`,
    ),
} as const;

/**
 * Color of a channel: an entity, so it never depends on the rank nor on
 * which channels have data (WhatsApp is always the same color).
 */
const channelTokens: Record<ConnectionChannel, string> = {
    web_chat: 'var(--chart-cat-1)',
    whatsapp: 'var(--chart-cat-3)',
    messenger: 'var(--chart-cat-7)',
    instagram: 'var(--chart-cat-5)',
};

export function channelColor(channel: ConnectionChannel | null): string {
    return channel === null
        ? 'var(--muted-foreground)'
        : (channelTokens[channel] ?? 'var(--muted-foreground)');
}

/** Status colors (always shown with their label, never alone). */
const statusTokens: Record<string, string> = {
    pending: 'var(--warning)',
    open: 'var(--chart-cat-1)',
    on_hold: 'var(--chart-cat-1)',
    closed: 'var(--muted-foreground)',
    failed: 'var(--destructive)',
};

export function statusColor(status: string): string {
    return statusTokens[status] ?? 'var(--muted-foreground)';
}

/* One observer for the whole app: charts only read the version. */
const themeVersion = ref(0);
let observing = false;

function observeTheme(): void {
    if (observing || typeof document === 'undefined') {
        return;
    }

    observing = true;
    new MutationObserver(() => {
        themeVersion.value++;
    }).observe(document.documentElement, {
        attributes: true,
        attributeFilter: ['class', 'data-palette', 'style'],
    });
}

function readVariable(name: string): string {
    if (typeof document === 'undefined') {
        return '';
    }

    return getComputedStyle(document.documentElement)
        .getPropertyValue(name)
        .trim();
}

const numberFormatter = new Intl.NumberFormat('es-MX');
const compactFormatter = new Intl.NumberFormat('es-MX', {
    notation: 'compact',
    maximumFractionDigits: 1,
});
const percentFormatter = new Intl.NumberFormat('es-MX', {
    style: 'percent',
    maximumFractionDigits: 1,
});

export function formatNumber(value: number): string {
    return numberFormatter.format(value);
}

/** «12.5%» (es-MX); a real 0 reads «0%». */
export function formatPercent(ratio: number): string {
    return percentFormatter.format(ratio);
}

/** Axis ticks: «950», «1,2 mil». */
export function formatCompact(value: number): string {
    return Math.abs(value) < 1000
        ? numberFormatter.format(value)
        : compactFormatter.format(value);
}

/** Exact duration for tooltips and tables («3 min 20 s»). */
export function formatDuration(seconds: number): string {
    return formatLongDuration(Math.round(seconds));
}

/** Short duration for axis ticks («30 s», «5 min», «1,5 h»). */
export function formatDurationTick(seconds: number): string {
    if (seconds < 60) {
        return `${formatNumber(seconds)} s`;
    }

    if (seconds < 3600) {
        return `${formatNumber(Math.round((seconds / 60) * 10) / 10)} min`;
    }

    return `${formatNumber(Math.round((seconds / 3600) * 10) / 10)} h`;
}

/**
 * Readable labels of a bucket key built on the server in the platform time
 * zone («2026-10-21T09:00», «2026-10-21», «2026-10»). The key already is a
 * local date, so it is formatted as is (in UTC, no conversion).
 */
export function formatBucket(
    key: string,
    granularity: ActivityGranularity,
): BucketLabel {
    const [datePart, timePart] = key.split('T');
    const [year, month = 1, day = 1] = (datePart ?? '').split('-').map(Number);

    if (year === undefined || Number.isNaN(year)) {
        return { short: key, long: key };
    }

    const date = new Date(Date.UTC(year, month - 1, day));
    const format = (options: Intl.DateTimeFormatOptions): string =>
        new Intl.DateTimeFormat('es-MX', { ...options, timeZone: 'UTC' })
            .format(date)
            .replace(/\./g, '');

    if (granularity === 'month') {
        return {
            short: format({ month: 'short', year: 'numeric' }),
            long: format({ month: 'long', year: 'numeric' }),
        };
    }

    const longDay = format({
        weekday: 'long',
        day: 'numeric',
        month: 'long',
        year: 'numeric',
    });

    if (granularity === 'hour') {
        const hour = (timePart ?? '00:00').slice(0, 2);

        return {
            short: `${hour}:00`,
            long: `${longDay}, de ${hour}:00 a ${hour}:59`,
        };
    }

    return { short: format({ day: 'numeric', month: 'short' }), long: longDay };
}

/**
 * The platform time zone in plain words, shown once next to the period
 * («hora de Ciudad de México (UTC−6)»), never the raw IANA name.
 */
export function zoneLabel(zone: string): string {
    return zone === '' ? '' : `hora de ${formatTimeZone(zone)}`;
}

export function granularityNoun(granularity: ActivityGranularity): string {
    return { hour: 'hora', day: 'día', month: 'mes' }[granularity];
}

export function useChartTheme(): {
    colors: ComputedRef<ChartColors>;
    reducedMotion: Ref<boolean>;
    duration: ComputedRef<number>;
    formatNumber: typeof formatNumber;
    formatPercent: typeof formatPercent;
    formatCompact: typeof formatCompact;
    formatDuration: typeof formatDuration;
    formatBucket: typeof formatBucket;
} {
    observeTheme();

    const preference = usePreferredReducedMotion();
    const reducedMotion = computed(() => preference.value === 'reduce');

    const colors = computed<ChartColors>(() => {
        void themeVersion.value;

        return {
            primary: readVariable('--chart-primary'),
            secondary: readVariable('--chart-secondary'),
            comparison: readVariable('--chart-comparison'),
            grid: readVariable('--chart-grid'),
            axis: readVariable('--chart-axis'),
            text: readVariable('--foreground'),
            muted: readVariable('--muted-foreground'),
            surface: readVariable('--surface-raised'),
            categorical: Array.from({ length: 8 }, (_, index) =>
                readVariable(`--chart-cat-${index + 1}`),
            ),
            status: {
                pending: readVariable('--warning'),
                open: readVariable('--chart-cat-1'),
                closed: readVariable('--muted-foreground'),
                failed: readVariable('--destructive'),
            },
        };
    });

    return {
        colors,
        reducedMotion,
        duration: computed(() => (reducedMotion.value ? 0 : 300)),
        formatNumber,
        formatPercent,
        formatCompact,
        formatDuration,
        formatBucket,
    };
}

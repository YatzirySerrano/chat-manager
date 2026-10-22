import { usePage } from '@inertiajs/vue3';

function platformTimezone(): string | undefined {
    try {
        return usePage().props.platform?.timezone;
    } catch {
        return undefined;
    }
}

/**
 * Format an ISO date using the platform time zone (falls back to the browser's).
 */
export function formatDateTime(
    iso: string | null | undefined,
    options: Intl.DateTimeFormatOptions = {
        dateStyle: 'medium',
        timeStyle: 'short',
    },
): string {
    if (!iso) {
        return '—';
    }

    const timeZone = platformTimezone();

    try {
        return new Intl.DateTimeFormat('es-MX', {
            ...options,
            timeZone,
        }).format(new Date(iso));
    } catch {
        return new Intl.DateTimeFormat('es-MX', options).format(new Date(iso));
    }
}

export function formatDate(iso: string | null | undefined): string {
    return formatDateTime(iso, { dateStyle: 'medium' });
}

const relativeFormatter = new Intl.RelativeTimeFormat('es', {
    numeric: 'auto',
});

const relativeUnits: [Intl.RelativeTimeFormatUnit, number][] = [
    ['year', 31_536_000],
    ['month', 2_592_000],
    ['week', 604_800],
    ['day', 86_400],
    ['hour', 3_600],
    ['minute', 60],
];

/**
 * Human relative time such as "hace 5 minutos".
 */
export function formatRelative(iso: string | null | undefined): string {
    if (!iso) {
        return '—';
    }

    const seconds = Math.round((new Date(iso).getTime() - Date.now()) / 1000);

    for (const [unit, unitSeconds] of relativeUnits) {
        if (Math.abs(seconds) >= unitSeconds) {
            return relativeFormatter.format(
                Math.round(seconds / unitSeconds),
                unit,
            );
        }
    }

    return 'justo ahora';
}

/**
 * Format a duration in seconds as "1 h 05 min" / "3 min 20 s".
 */
export function formatDuration(totalSeconds: number): string {
    const hours = Math.floor(totalSeconds / 3600);
    const minutes = Math.floor((totalSeconds % 3600) / 60);
    const seconds = totalSeconds % 60;

    if (hours > 0) {
        return `${hours} h ${String(minutes).padStart(2, '0')} min`;
    }

    if (minutes > 0) {
        return `${minutes} min ${String(seconds).padStart(2, '0')} s`;
    }

    return `${seconds} s`;
}

/**
 * End a sentence with a period unless the text already ends with one, as
 * es-MX times do ("9:00 a.m."), so the copy never shows "a.m..".
 */
export function endSentence(text: string): string {
    return text.endsWith('.') ? text : `${text}.`;
}

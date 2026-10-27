import { usePage } from '@inertiajs/vue3';

/**
 * The platform time zone every date is shown in (undefined falls back to the
 * browser's). Shared by formatDateTime() and the chat's day separators.
 */
export function platformTimezone(): string | undefined {
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
 * Human file size ("850 KB", "1.5 MB", "2 MB") in decimal units (1 MB =
 * 10^6 bytes): the unit of the channel attachment limits (Meta's "16 MB"
 * read conservatively, ChannelAttachmentRules::humanSize) and of the sizes
 * macOS shows. Shared by the inbox and the team chat so the same file
 * always reads the same, in the composer card and in the sent bubble.
 */
export function formatFileSize(bytes: number | null | undefined): string {
    if (bytes === null || bytes === undefined) {
        return '';
    }

    if (bytes >= 1_000_000) {
        return `${(bytes / 1_000_000).toFixed(1).replace(/\.0$/, '')} MB`;
    }

    return `${Math.max(1, Math.round(bytes / 1000))} KB`;
}

/**
 * End a sentence with a period unless the text already ends with one, as
 * es-MX times do ("9:00 a.m."), so the copy never shows "a.m..".
 */
export function endSentence(text: string): string {
    return text.endsWith('.') ? text : `${text}.`;
}

/** Spanish names of the time zones a Mexican organization usually picks. */
const timeZoneNames: Record<string, string> = {
    'America/Mexico_City': 'Ciudad de México',
    'America/Cancun': 'Cancún',
    'America/Tijuana': 'Tijuana',
    'America/Hermosillo': 'Hermosillo',
    'America/Mazatlan': 'Mazatlán',
    'America/Chihuahua': 'Chihuahua',
    'America/Ciudad_Juarez': 'Ciudad Juárez',
    'America/Monterrey': 'Monterrey',
    'America/Merida': 'Mérida',
    'America/Matamoros': 'Matamoros',
    'America/Bogota': 'Bogotá',
    'America/Lima': 'Lima',
    'America/Santiago': 'Santiago de Chile',
    'America/Argentina/Buenos_Aires': 'Buenos Aires',
    'America/New_York': 'Nueva York',
    'America/Los_Angeles': 'Los Ángeles',
    'Europe/Madrid': 'Madrid',
    UTC: 'Tiempo universal',
};

/**
 * Readable name of an IANA time zone with its current offset, e.g.
 * "Ciudad de México (UTC−6)". Other zones keep their IANA name.
 */
export function formatTimeZone(zone: string): string {
    const name = timeZoneNames[zone] ?? zone.replaceAll('_', ' ');

    try {
        const offset =
            new Intl.DateTimeFormat('en-US', {
                timeZone: zone,
                timeZoneName: 'shortOffset',
            })
                .formatToParts(new Date())
                .find((part) => part.type === 'timeZoneName')
                ?.value.replace('GMT', 'UTC')
                .replace('-', '−') ?? '';

        return offset === ''
            ? name
            : `${name} (${offset === 'UTC' ? 'UTC±0' : offset})`;
    } catch {
        return name;
    }
}

/**
 * Short readable name of a browser user agent, e.g. "Chrome en Windows".
 * A minimal parser: unknown agents fall back to "Navegador".
 */
export function formatUserAgent(userAgent: string | null | undefined): string {
    if (!userAgent) {
        return 'Navegador';
    }

    const browsers: [RegExp, string][] = [
        [/Edg(e|A|iOS)?\//, 'Edge'],
        [/OPR\/|Opera/, 'Opera'],
        [/SamsungBrowser\//, 'Samsung Internet'],
        [/Firefox\/|FxiOS\//, 'Firefox'],
        [/Chrome\/|CriOS\//, 'Chrome'],
        [/Safari\//, 'Safari'],
    ];
    const systems: [RegExp, string][] = [
        [/Windows/, 'Windows'],
        [/Android/, 'Android'],
        [/iPhone|iPad|iPod/, 'iPhone o iPad'],
        [/Mac OS X|Macintosh/, 'Mac'],
        [/CrOS/, 'Chromebook'],
        [/Linux/, 'Linux'],
    ];
    const browser = browsers.find(([pattern]) => pattern.test(userAgent))?.[1];
    const system = systems.find(([pattern]) => pattern.test(userAgent))?.[1];

    if (browser && system) {
        return `${browser} en ${system}`;
    }

    return browser ?? system ?? 'Navegador';
}

/**
 * Readable name of a Meta template language code, e.g. "es_MX" →
 * "Español (México)". Unknown codes are shown as they come.
 */
export function formatLocale(code: string | null | undefined): string {
    if (!code) {
        return '—';
    }

    try {
        const name = new Intl.DisplayNames(['es'], { type: 'language' }).of(
            code.replace('_', '-'),
        );

        return name
            ? name.charAt(0).toLocaleUpperCase('es') + name.slice(1)
            : code;
    } catch {
        return code;
    }
}

/** Meta's quality rating of a template (GREEN/YELLOW/RED) in plain words. */
export function formatTemplateQuality(
    score: string | null | undefined,
): string {
    switch (score?.toUpperCase()) {
        case 'GREEN':
        case 'HIGH':
            return 'Alta';
        case 'YELLOW':
        case 'MEDIUM':
            return 'Media';
        case 'RED':
        case 'LOW':
            return 'Baja';
        default:
            return 'Sin calificación';
    }
}

/**
 * Calendar-day grouping of the chat history. Pure (no Vue, no Inertia) so
 * the same rules apply wherever they run: every day is computed in one
 * time zone (the platform's), "today" is passed in (so a chat left open
 * past midnight relabels its days when the caller updates it), and each
 * calendar day yields exactly one group.
 */

/** A calendar day as "YYYY-MM-DD". */
export type CalendarDay = string;

export type DayGroup<T> = {
    day: CalendarDay;
    label: string;
    items: T[];
};

const dayFormatters = new Map<string, Intl.DateTimeFormat>();

function dayFormatter(timeZone: string | undefined): Intl.DateTimeFormat {
    const cacheKey = timeZone ?? '';
    let formatter = dayFormatters.get(cacheKey);

    if (!formatter) {
        try {
            formatter = new Intl.DateTimeFormat('en-CA', {
                timeZone,
                year: 'numeric',
                month: '2-digit',
                day: '2-digit',
            });
        } catch {
            formatter = new Intl.DateTimeFormat('en-CA', {
                year: 'numeric',
                month: '2-digit',
                day: '2-digit',
            });
        }

        dayFormatters.set(cacheKey, formatter);
    }

    return formatter;
}

/**
 * The calendar day of an instant in the given time zone (the browser's
 * when omitted), or null when the instant is missing or invalid.
 */
export function calendarDay(
    at: string | number | Date | null | undefined,
    timeZone?: string,
): CalendarDay | null {
    if (at === null || at === undefined || at === '') {
        return null;
    }

    const date = at instanceof Date ? at : new Date(at);

    if (Number.isNaN(date.getTime())) {
        return null;
    }

    const parts = dayFormatter(timeZone).formatToParts(date);
    const part = (type: Intl.DateTimeFormatPartTypes): string =>
        parts.find((item) => item.type === type)?.value ?? '';

    return `${part('year')}-${part('month')}-${part('day')}`;
}

/** Noon UTC of a calendar day: a stable instant to do date arithmetic on. */
function dayToUtcNoon(day: CalendarDay): Date {
    const [year, month, date] = day.split('-').map(Number);

    return new Date(Date.UTC(year, month - 1, date, 12));
}

/**
 * The day before, by calendar arithmetic (never "now minus 24 h", which is
 * wrong on days with a daylight saving change).
 */
export function previousCalendarDay(day: CalendarDay): CalendarDay {
    const date = dayToUtcNoon(day);
    date.setUTCDate(date.getUTCDate() - 1);

    return date.toISOString().slice(0, 10);
}

const fullDateFormatter = new Intl.DateTimeFormat('es-MX', {
    timeZone: 'UTC',
    weekday: 'long',
    day: 'numeric',
    month: 'long',
    year: 'numeric',
});

/** First letter in upper case, as every heading of the interface. */
function capitalize(text: string): string {
    return text.charAt(0).toLocaleUpperCase('es-MX') + text.slice(1);
}

/**
 * "Hoy", "Ayer" or the full date ("Jueves, 22 de octubre de 2026") of a
 * calendar day, relative to the given today.
 */
export function calendarDayLabel(day: CalendarDay, today: CalendarDay): string {
    if (day === today) {
        return 'Hoy';
    }

    if (day === previousCalendarDay(today)) {
        return 'Ayer';
    }

    return capitalize(fullDateFormatter.format(dayToUtcNoon(day)));
}

/**
 * Splits a list already ordered by time into one group per calendar day.
 * `at` must return the same instant the list is ordered by, so days come
 * out contiguous; an item without a valid instant joins the group before
 * it (or today's, when it is the first). A day that would appear again
 * further down (only possible with an unordered list) joins its first
 * group, so a day never gets two separators.
 */
export function groupByCalendarDay<T>(
    items: readonly T[],
    at: (item: T) => string | null | undefined,
    today: CalendarDay,
    timeZone?: string,
): DayGroup<T>[] {
    const groups: DayGroup<T>[] = [];
    const byDay = new Map<CalendarDay, DayGroup<T>>();
    let current: DayGroup<T> | null = null;

    for (const item of items) {
        const day: CalendarDay =
            calendarDay(at(item), timeZone) ?? current?.day ?? today;

        if (current === null || current.day !== day) {
            current = byDay.get(day) ?? null;

            if (current === null) {
                current = {
                    day,
                    label: calendarDayLabel(day, today),
                    items: [],
                };
                byDay.set(day, current);
                groups.push(current);
            }
        }

        current.items.push(item);
    }

    return groups;
}

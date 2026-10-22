/**
 * Calendar label of a non-working day ("25 de diciembre de 2026", or
 * "25 de diciembre (cada año)" when it repeats). Dates are plain calendar
 * days, so they are formatted in UTC to never shift by time zone.
 */
export function formatHoliday(holiday: {
    date: string;
    recurring: boolean;
}): string {
    const date = new Date(`${holiday.date}T00:00:00Z`);

    if (Number.isNaN(date.getTime())) {
        return holiday.date;
    }

    const formatter = new Intl.DateTimeFormat('es-MX', {
        day: 'numeric',
        month: 'long',
        ...(holiday.recurring ? {} : { year: 'numeric' }),
        timeZone: 'UTC',
    });

    return holiday.recurring
        ? `${formatter.format(date)} (cada año)`
        : formatter.format(date);
}

import type { DateValue } from "@internationalized/date"
import { usePage } from "@inertiajs/vue3"
import {
  CalendarDate,
  endOfMonth,
  getLocalTimeZone,
  parseDate,
  startOfMonth,
  today,
} from "@internationalized/date"

/** Locale of every calendar: Spanish (Mexico), weeks start on Monday. */
export const CALENDAR_LOCALE = "es-MX"
export const WEEK_STARTS_ON = 1

/** "2026-11-12" → CalendarDate; anything else (empty, malformed) → undefined. */
export function toCalendarDate(value: string | null | undefined): CalendarDate | undefined {
  if (!value) {
    return undefined
  }

  try {
    return parseDate(value.slice(0, 10))
  }
  catch {
    return undefined
  }
}

export function toIsoDate(date: DateValue | undefined | null): string {
  return date
    ? `${String(date.year).padStart(4, "0")}-${String(date.month).padStart(2, "0")}-${String(date.day).padStart(2, "0")}`
    : ""
}

const displayFormat = new Intl.DateTimeFormat(CALENDAR_LOCALE, {
  day: "numeric",
  month: "short",
  year: "numeric",
})

const monthFormat = new Intl.DateTimeFormat(CALENDAR_LOCALE, { month: "long" })
const shortMonthFormat = new Intl.DateTimeFormat(CALENDAR_LOCALE, { month: "short" })

function asLocalDate(date: DateValue): Date {
  return new Date(date.year, date.month - 1, date.day, 12)
}

/** "12 nov 2026" */
export function formatDate(value: string | DateValue | null | undefined): string {
  const date = typeof value === "string" ? toCalendarDate(value) : value

  return date ? displayFormat.format(asLocalDate(date)).replace(/\./g, "") : ""
}

/** "noviembre" / "nov" (month is 1–12). */
export function monthName(month: number, style: "long" | "short" = "long"): string {
  const date = new Date(2024, month - 1, 1, 12)

  return (style === "long" ? monthFormat : shortMonthFormat).format(date).replace(/\./g, "")
}

/** "Noviembre de 2026" */
export function monthHeading(date: DateValue): string {
  const name = monthName(date.month)

  return `${name.charAt(0).toUpperCase()}${name.slice(1)} de ${date.year}`
}

/**
 * Today in the platform's time zone (shared Inertia prop), so "Hoy" matches
 * the reports of the server; the browser's zone is the fallback.
 */
export function usePlatformToday(): () => CalendarDate {
  let timeZone = getLocalTimeZone()

  try {
    const platform = (usePage().props as { platform?: { timezone?: string } }).platform

    if (platform?.timezone) {
      timeZone = platform.timezone
    }
  }
  catch {
    // Outside an Inertia page (isolated rendering): keep the browser zone.
  }

  return () => {
    try {
      return today(timeZone)
    }
    catch {
      return today(getLocalTimeZone())
    }
  }
}

export interface DateRangePreset {
  label: string
  /** Inclusive range as ISO dates, computed from "today". */
  range: (today: CalendarDate) => { from: CalendarDate, to: CalendarDate }
}

/** Shortcuts of the range picker. */
export const DEFAULT_RANGE_PRESETS: DateRangePreset[] = [
  { label: "Hoy", range: (day) => ({ from: day, to: day }) },
  { label: "Ayer", range: (day) => ({ from: day.subtract({ days: 1 }), to: day.subtract({ days: 1 }) }) },
  { label: "Últimos 7 días", range: (day) => ({ from: day.subtract({ days: 6 }), to: day }) },
  { label: "Últimos 30 días", range: (day) => ({ from: day.subtract({ days: 29 }), to: day }) },
  { label: "Este mes", range: (day) => ({ from: startOfMonth(day), to: day }) },
  {
    label: "Mes pasado",
    range: (day) => {
      const previous = day.subtract({ months: 1 })

      return { from: startOfMonth(previous), to: endOfMonth(previous) }
    },
  },
]

export { CalendarDate }

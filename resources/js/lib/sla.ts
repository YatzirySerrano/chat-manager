import type { ConversationSla } from '@/types';

/** Traffic light of a request against its response objective. */
export type SlaState = 'on_time' | 'warning' | 'breached';

export const SLA_STATE_LABEL: Record<SlaState, string> = {
    on_time: 'En tiempo',
    warning: 'Por vencer',
    breached: 'Vencida',
};

/** Classes of each state (soft surface + readable text, light and dark). */
export const SLA_STATE_CLASS: Record<SlaState, string> = {
    on_time: 'border-success/30 bg-success-soft text-success-soft-foreground',
    warning: 'border-warning/40 bg-warning-soft text-warning-soft-foreground',
    breached:
        'border-destructive/40 bg-destructive-soft text-destructive-soft-foreground',
};

function instant(iso: string | null): number | null {
    if (!iso) {
        return null;
    }

    const parsed = Date.parse(iso);

    return Number.isNaN(parsed) ? null : parsed;
}

/**
 * State of the request at `nowMs` (server clock): the deadline passed,
 * the warning instant was reached, or still on time. Exactly at the
 * deadline the objective is still met, as on the server.
 */
export function slaState(sla: ConversationSla, nowMs: number): SlaState {
    const due = instant(sla.due_at);
    const warn = instant(sla.warn_at);

    if (sla.breached || (due !== null && nowMs > due)) {
        return 'breached';
    }

    return warn !== null && nowMs >= warn ? 'warning' : 'on_time';
}

/** Whole seconds until the deadline (negative once it passed). */
export function slaSecondsLeft(
    sla: ConversationSla,
    nowMs: number,
): number | null {
    const due = instant(sla.due_at);

    return due === null ? null : Math.ceil((due - nowMs) / 1000);
}

/** Whole seconds the customer has been waiting. */
export function slaSecondsElapsed(
    sla: Pick<ConversationSla, 'started_at'>,
    nowMs: number,
): number {
    const started = instant(sla.started_at);

    return started === null
        ? 0
        : Math.max(0, Math.floor((nowMs - started) / 1000));
}

/**
 * A span of time in words, short enough for a badge: "45 s", "12 min",
 * "1 h 05 min", "2 d 3 h". Seconds are only shown under one minute, so the
 * text changes once per minute for most of its life.
 */
export function formatSlaSpan(totalSeconds: number): string {
    const seconds = Math.max(0, Math.floor(totalSeconds));

    if (seconds < 60) {
        return `${seconds} s`;
    }

    const minutes = Math.floor(seconds / 60);

    if (minutes < 60) {
        return `${minutes} min`;
    }

    const hours = Math.floor(minutes / 60);

    if (hours < 24) {
        return `${hours} h ${String(minutes % 60).padStart(2, '0')} min`;
    }

    return `${Math.floor(hours / 24)} d ${hours % 24} h`;
}

/** The objective in words: "10 min", "2 h", "1 h 30 min", "2 d". */
export function formatSlaTarget(targetSeconds: number): string {
    const minutes = Math.round(targetSeconds / 60);

    if (minutes < 60) {
        return `${minutes} min`;
    }

    const hours = Math.floor(minutes / 60);
    const rest = minutes % 60;

    if (hours < 24 || rest !== 0 || hours % 24 !== 0) {
        return rest === 0 ? `${hours} h` : `${hours} h ${rest} min`;
    }

    return `${hours / 24} d`;
}

/** "Vence en 12 min" / "Venció hace 3 min". */
export function slaTimeText(sla: ConversationSla, nowMs: number): string {
    const left = slaSecondsLeft(sla, nowMs);

    if (left === null) {
        return '';
    }

    return left >= 0
        ? `Vence en ${formatSlaSpan(left)}`
        : `Venció hace ${formatSlaSpan(-left)}`;
}

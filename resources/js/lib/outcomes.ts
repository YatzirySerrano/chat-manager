import type { ConversationOutcome } from '@/types';

/**
 * Credit result of a completed request, in words, the same everywhere
 * (details panel, list badge, reports): «Crédito aprobado · $3,000»,
 * «Rechazada». Amounts are pesos; cents only when there are.
 */
export function formatMoney(amount: number | string | null): string {
    const value = typeof amount === 'string' ? Number(amount) : amount;

    if (value === null || !Number.isFinite(value)) {
        return '';
    }

    return value.toLocaleString('es-MX', {
        style: 'currency',
        currency: 'MXN',
        currencyDisplay: 'narrowSymbol',
        minimumFractionDigits: Number.isInteger(value) ? 0 : 2,
        maximumFractionDigits: 2,
    });
}

export function outcomeText(
    outcome: ConversationOutcome | null | undefined,
): string | null {
    if (!outcome) {
        return null;
    }

    return outcome.value === 'approved' && outcome.amount !== null
        ? `${outcome.label} · ${formatMoney(outcome.amount)}`
        : outcome.label;
}

/**
 * An amount typed by a person («$3,000», «3 000.50») as the server expects
 * it («3000», «3000.50»), '' when empty, or null when it is not a valid
 * amount (up to ten digits and two decimals).
 */
export function normalizeAmount(input: string): string | null {
    const raw = input.replace(/[$\s,]/g, '').replace(/^mxn|mxn$/i, '');

    if (raw === '') {
        return '';
    }

    return /^\d{1,10}(\.\d{1,2})?$/.test(raw) ? raw : null;
}

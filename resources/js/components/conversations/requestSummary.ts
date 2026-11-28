import { formatMoney, normalizeAmount } from '@/lib/outcomes';
import type { RequestSummary, RequestSummaryResult } from '@/types';

/**
 * How a conversation is told as the credit request it was, the same in
 * «Solicitudes del contacto» and in the separators of «Conversación completa»:
 * «Solicitud de BEATRIZ GUERRERO OLVERA» (the «Cliente» field; the WhatsApp
 * contact is usually the field manager, not the client), its facts and a
 * pill with where it ended.
 */
export function requestClient(summary: RequestSummary): string {
    return summary.client ?? 'Cliente sin capturar';
}

export function requestTitle(summary: RequestSummary): string {
    return summary.client
        ? `Solicitud de ${summary.client}`
        : 'Cliente sin capturar';
}

/** «Reactivación · S12 · $5,000»: type, product and requested amount, when captured. */
export function requestFacts(summary: RequestSummary): string[] {
    const amount = summary.requested_amount
        ? normalizeAmount(summary.requested_amount)
        : null;
    const requested = amount ? formatMoney(amount) : summary.requested_amount;

    return [summary.type, summary.product, requested].filter(
        (fact): fact is string => Boolean(fact),
    );
}

/** «Crédito aprobado $3,000», «Rechazada», «Completada», «Cerrada», «No es solicitud»... */
export function resultLabel(result: RequestSummaryResult): string {
    return result.value === 'approved' && result.amount !== null
        ? `${result.label} ${formatMoney(result.amount)}`
        : result.label;
}

/** Text and border tones of the pill: green approved, red rejected, neutral otherwise. */
export function resultClasses(result: RequestSummaryResult): string {
    switch (result.value) {
        case 'approved':
            return 'border-success/40 bg-success-soft text-success-soft-foreground';
        case 'rejected':
            return 'border-destructive/40 bg-destructive-soft text-destructive-soft-foreground';
        case 'open':
            return 'border-info/40 bg-info-soft text-info-soft-foreground';
        default:
            return 'border-border bg-muted text-muted-foreground';
    }
}

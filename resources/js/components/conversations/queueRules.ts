import { computed } from 'vue';
import type { ComputedRef, InjectionKey } from 'vue';
import {
    formatCountdown,
    secondsUntil,
    useServerNow,
} from '@/composables/useServerNow';
import type {
    AcceptanceCode,
    ConversationReadinessValue,
    ConversationSummary,
} from '@/types';

/**
 * Queue rules as the inbox shows them (QueueRules on the server): whether a
 * pending request can be taken right now and, if not, why. The server
 * decides again under lock on every accept; this only explains.
 */

/** Operations the inbox page offers to every row and to the chat header. */
export type QueueActions = {
    /** Open the dialog that justifies an exception to the queue rules. */
    override: (conversation: ConversationSummary) => void;
    /** Open the dialog that confirms the request or returns it. */
    readiness: (
        conversation: ConversationSummary,
        target: ConversationReadinessValue,
    ) => void;
    /** Open another conversation (the one the viewer can take). */
    open: (id: number) => void;
};

export const QUEUE_ACTIONS: InjectionKey<QueueActions> =
    Symbol('queue-actions');

export const noQueueActions: QueueActions = {
    override: () => {},
    readiness: () => {},
    open: () => {},
};

/** The three rules an exception may skip, as people read them. */
export const QUEUE_RULE_LABELS: Record<string, string> = {
    needs_info: 'Solicitud completa',
    min_wait: 'Tiempo mínimo de espera',
    queue_order: 'Orden de llegada',
};

/** What skipping each rule means, for the exception dialog. */
export const QUEUE_RULE_CONSEQUENCES: Record<string, string> = {
    needs_info:
        'Tomarás una solicitud que todavía no se confirmó como completa: puede faltarle información para atenderla.',
    min_wait:
        'Tomarás la solicitud antes de que cumpla el tiempo mínimo de espera en la cola.',
    queue_order:
        'Tomarás esta solicitud antes que otra que llegó primero y sigue esperando.',
};

export type AcceptanceView = {
    /** «Aceptar» must stay disabled. */
    blocked: boolean;
    /** Reason to show and to describe the disabled button with; null when nothing blocks. */
    code: AcceptanceCode | null;
    text: string | null;
    /** `min_wait`: the countdown as `02:35`, null otherwise. */
    countdown: string | null;
    /** `queue_order`: the request the viewer can take instead. */
    blockingId: number | null;
    /** The viewer may justify an exception to the blocking rule. */
    overridable: boolean;
};

/**
 * The acceptance of a pending request, counted down against the server
 * clock: a minimum wait reaching zero stops blocking by itself (the page
 * also reads the list again at that moment, so any other reason appears).
 */
export function useAcceptance(
    conversation: () => ConversationSummary,
): ComputedRef<AcceptanceView> {
    const now = useServerNow();

    return computed<AcceptanceView>(() => {
        const acceptance = conversation().acceptance ?? null;

        if (acceptance === null || acceptance.allowed) {
            return {
                blocked: false,
                code: null,
                text: null,
                countdown: null,
                blockingId: null,
                overridable: false,
            };
        }

        if (acceptance.code === 'min_wait') {
            const left = secondsUntil(acceptance.available_at, now.value) ?? 0;

            if (left <= 0) {
                return {
                    blocked: false,
                    code: null,
                    text: null,
                    countdown: null,
                    blockingId: null,
                    overridable: false,
                };
            }

            const countdown = formatCountdown(left);

            return {
                blocked: true,
                code: 'min_wait',
                text: `Disponible para atención en ${countdown}`,
                countdown,
                blockingId: null,
                overridable: acceptance.overridable,
            };
        }

        return {
            blocked: true,
            code: acceptance.code,
            text: acceptance.message,
            countdown: null,
            blockingId: acceptance.blocking_conversation_id,
            overridable: acceptance.overridable,
        };
    });
}

/**
 * The earliest moment a listed request ends its minimum wait (ISO 8601), so
 * the page reads the list again exactly then, without polling.
 */
export function nextAvailability(
    rows: ConversationSummary[],
    nowMs: number,
): string | null {
    let next: string | null = null;
    let nextMs = Infinity;

    for (const row of rows) {
        const at = row.acceptance?.available_at;

        if (!at) {
            continue;
        }

        const ms = Date.parse(at);

        if (!Number.isNaN(ms) && ms > nowMs && ms < nextMs) {
            next = at;
            nextMs = ms;
        }
    }

    return next;
}

import { reactive } from 'vue';
import { canAdvanceDeliveryState } from '@/components/conversations/messageFormat';
import type { RealtimePayload } from '@/lib/realtime';
import type {
    ConversationMessagePreview,
    ConversationSummary,
    MessageStatusValue,
} from '@/types';

/**
 * Delivery states reported in real time for the latest message of the
 * inbox rows, by message id. Fed by the list's `inbox.message_status`
 * signal and by the open thread's `message.status`, so the row and the
 * thread never disagree; applied over the list rows only when they move
 * the shown state forward (canAdvanceDeliveryState), and forgotten once a
 * list reload brings the same state or a fresher one. Nothing is polled.
 */
type ReportedState = {
    status: MessageStatusValue;
    error_code: string | null;
    /** Version of the state (messages.updated_at), when known. */
    updated_at: string | null;
};

const STATUSES: readonly MessageStatusValue[] = [
    'received',
    'queued',
    'sending',
    'accepted',
    'sent',
    'delivered',
    'read',
    'failed',
];

const reported = reactive(new Map<number, ReportedState>());

function timeOf(at: string | null | undefined): number | null {
    const time = Date.parse(at ?? '');

    return Number.isNaN(time) ? null : time;
}

/**
 * Whether the row already shows this state or a fresher one: a later
 * version (for example a manual retry that queued it again), or a state the
 * reported one cannot advance.
 */
function isSettledBy(
    preview: ConversationMessagePreview,
    state: ReportedState,
): boolean {
    const rowAt = timeOf(preview.updated_at);
    const stateAt = timeOf(state.updated_at);

    if (rowAt !== null && stateAt !== null && rowAt > stateAt) {
        return true;
    }

    // A later version always wins, even going "back" (a retry re-queues a
    // failed message).
    if (rowAt !== null && stateAt !== null && stateAt > rowAt) {
        return false;
    }

    return !canAdvanceDeliveryState(preview, state);
}

function isNewerOrForward(
    current: ReportedState,
    next: ReportedState,
): boolean {
    const currentAt = timeOf(current.updated_at);
    const nextAt = timeOf(next.updated_at);

    if (currentAt !== null && nextAt !== null && nextAt !== currentAt) {
        return nextAt > currentAt;
    }

    return canAdvanceDeliveryState(current, next);
}

/** Records a delivery state reported by a real-time signal. */
export function reportMessageStatus(payload: RealtimePayload): void {
    const id = Number(payload.message_id);
    const status = String(payload.status ?? '') as MessageStatusValue;

    if (!Number.isInteger(id) || id <= 0 || !STATUSES.includes(status)) {
        return;
    }

    const next: ReportedState = {
        status,
        error_code:
            typeof payload.error_code === 'string' ? payload.error_code : null,
        updated_at:
            typeof payload.updated_at === 'string' ? payload.updated_at : null,
    };
    const current = reported.get(id);

    // Several channels (and the thread) may repeat the same signal, and
    // signals may arrive out of order: only a forward move or a later
    // version (a retry re-queues a failed message) is kept.
    if (current && !isNewerOrForward(current, next)) {
        return;
    }

    reported.set(id, next);
}

/** The row with the reported state of its latest message, when newer. */
export function withReportedStatus(
    row: ConversationSummary,
): ConversationSummary {
    const preview = row.last_message;
    const state = preview ? reported.get(preview.id) : undefined;

    if (!preview || !state || isSettledBy(preview, state)) {
        return row;
    }

    return {
        ...row,
        last_message: {
            ...preview,
            status: state.status,
            error_code: state.error_code,
        },
    };
}

/**
 * States kept for messages the list does not show (yet): a status may
 * arrive before the reload that brings its message as the row's latest.
 */
const UNLISTED_LIMIT = 100;

/**
 * Forgets the reported states the rows read from the server already show
 * (or superseded). States of messages not listed are kept, up to a small
 * bound (oldest first), since their row may still be on its way.
 */
export function forgetSettledStatuses(rows: ConversationSummary[]): void {
    const latest = new Map<number, ConversationMessagePreview>();

    for (const row of rows) {
        if (row.last_message) {
            latest.set(row.last_message.id, row.last_message);
        }
    }

    const unlisted: number[] = [];

    for (const [id, state] of reported.entries()) {
        const preview = latest.get(id);

        if (!preview) {
            unlisted.push(id);
        } else if (isSettledBy(preview, state)) {
            reported.delete(id);
        }
    }

    unlisted
        .sort((a, b) => a - b)
        .slice(0, Math.max(0, unlisted.length - UNLISTED_LIMIT))
        .forEach((id) => reported.delete(id));
}

import {
    calendarDay,
    calendarDayLabel,
} from '@/components/conversations/dayGroups';
import { formatDateTime, platformTimezone } from '@/lib/format';
import type {
    ConversationMessagePreview,
    MessageStatusValue,
    MessageTypeValue,
} from '@/types';

/**
 * Human labels of the delivery states (same table as MessageStatus::label()).
 * Presentation only: queued and sending read "Enviando", accepted and sent
 * read "Enviado". "Entregado" and "Leído" only appear when the provider
 * reported them; nothing is assumed.
 */
export const messageStatusLabels: Record<MessageStatusValue, string> = {
    received: 'Recibido',
    queued: 'Enviando',
    sending: 'Enviando',
    accepted: 'Enviado',
    sent: 'Enviado',
    delivered: 'Entregado',
    read: 'Leído',
    failed: 'Fallido',
};

export const messageTypeLabels: Record<MessageTypeValue, string> = {
    text: 'Mensaje',
    image: 'Imagen',
    audio: 'Audio',
    video: 'Video',
    document: 'Documento',
    sticker: 'Sticker',
    location: 'Ubicación',
    contacts: 'Contacto compartido',
    template: 'Plantilla',
    interactive: 'Respuesta interactiva',
    reaction: 'Reacción',
    unsupported: 'Mensaje no compatible',
    button: 'Respuesta de botón',
};

/**
 * Error code of a reply whose outcome is unknown: Meta may have received
 * it. It is a failure of confirmation, not a definitive refusal.
 */
export const DELIVERY_UNKNOWN = 'delivery_unknown';

export const deliveryUnknownLabel = 'Entrega no confirmada';

/**
 * Order of the delivery states when the delivery attempt is unknown (shared
 * by the thread and the inbox list): replies only move forward, and a
 * failure, being terminal until an explicit retry, wins a tie.
 */
export const STATUS_RANK: Record<MessageStatusValue, number> = {
    received: 0,
    queued: 1,
    sending: 2,
    accepted: 3,
    sent: 4,
    delivered: 5,
    read: 6,
    failed: 7,
};

/** A delivery state as the list shows it. */
export type MessageDeliveryState = {
    status: MessageStatusValue;
    error_code: string | null;
};

/**
 * Whether a reported delivery state may replace the one shown, exactly as
 * the server's MessageStatus::canAdvanceTo() (plus RecordMessageStatus'
 * rule that Meta's evidence wins over a failure of unknown outcome):
 * - inbound messages ("received") never change;
 * - a failure is terminal, except delivery_unknown, which Meta's later
 *   accepted/sent/delivered/read evidence replaces;
 * - a failure only replaces a state before "delivered";
 * - otherwise only a higher rank moves forward (queued → sending →
 *   accepted → sent → delivered → read). Nothing is ever assumed: a state
 *   shows only once the server reported it.
 */
export function canAdvanceDeliveryState(
    current: MessageDeliveryState,
    next: MessageDeliveryState,
): boolean {
    if (current.status === 'received' || next.status === 'received') {
        return false;
    }

    if (current.status === 'failed') {
        return (
            current.error_code === DELIVERY_UNKNOWN &&
            STATUS_RANK[next.status] >= STATUS_RANK.accepted &&
            next.status !== 'failed'
        );
    }

    if (next.status === 'failed') {
        return STATUS_RANK[current.status] < STATUS_RANK.delivered;
    }

    return STATUS_RANK[next.status] > STATUS_RANK[current.status];
}

export function statusLabel(status: string, errorCode?: string | null): string {
    if (status === 'failed' && errorCode === DELIVERY_UNKNOWN) {
        return deliveryUnknownLabel;
    }

    return messageStatusLabels[status as MessageStatusValue] ?? status;
}

export function typeLabel(type: string): string {
    return messageTypeLabels[type as MessageTypeValue] ?? 'Mensaje';
}

/**
 * Text of a list row preview: the body, or the kind of content.
 */
export function previewText(message: ConversationMessagePreview): string {
    const body = message.body?.trim();

    if (message.type === 'text' || message.type === 'button') {
        return body || typeLabel(message.type);
    }

    return body
        ? `${typeLabel(message.type)}: ${body}`
        : typeLabel(message.type);
}

/** Calendar day ("YYYY-MM-DD") of an instant in the platform time zone. */
export function dayKey(iso: string | null | undefined): string {
    return calendarDay(iso, platformTimezone()) ?? '';
}

/** Today in the platform time zone. */
export function todayKey(): string {
    return calendarDay(new Date(), platformTimezone()) ?? '';
}

/** "Hoy", "Ayer" or the full date, for day separators. */
export function dayLabel(iso: string | null | undefined): string {
    return calendarDayLabel(dayKey(iso), todayKey());
}

export function formatTime(iso: string | null | undefined): string {
    return formatDateTime(iso, { timeStyle: 'short' });
}

/**
 * Time shown in a list row: the hour today, "Ayer", or a short date.
 */
export function listTime(iso: string | null | undefined): string {
    if (!iso) {
        return '';
    }

    const label = dayLabel(iso);

    if (label === 'Hoy') {
        return formatTime(iso);
    }

    if (label === 'Ayer') {
        return 'Ayer';
    }

    return formatDateTime(iso, { day: '2-digit', month: 'short' });
}

/** A fresh idempotency key for one send attempt. */
export function newClientReference(): string {
    const source: Crypto = globalThis.crypto;

    // randomUUID only exists in secure contexts (HTTPS or localhost).
    if (typeof source.randomUUID === 'function') {
        return source.randomUUID();
    }

    const bytes = source.getRandomValues(new Uint8Array(16));
    bytes[6] = (bytes[6] & 0x0f) | 0x40;
    bytes[8] = (bytes[8] & 0x3f) | 0x80;
    const hex = [...bytes].map((byte) => byte.toString(16).padStart(2, '0'));

    return `${hex.slice(0, 4).join('')}-${hex.slice(4, 6).join('')}-${hex.slice(6, 8).join('')}-${hex.slice(8, 10).join('')}-${hex.slice(10).join('')}`;
}

import { formatDateTime } from '@/lib/format';
import type {
    ConversationMessagePreview,
    MessageStatusValue,
    MessageTypeValue,
} from '@/types';

/**
 * Human labels of the delivery states. "Entregado" and "Leído" only appear
 * when Meta reported them; nothing is assumed.
 */
export const messageStatusLabels: Record<MessageStatusValue, string> = {
    received: 'Recibido',
    queued: 'En cola de envío',
    sending: 'Enviando',
    accepted: 'Aceptado por WhatsApp',
    sent: 'Enviado',
    delivered: 'Entregado',
    read: 'Leído',
    failed: 'No se envió',
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

export function statusLabel(status: string): string {
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

export function formatFileSize(bytes: number | null | undefined): string {
    if (bytes === null || bytes === undefined) {
        return '';
    }

    if (bytes >= 1024 * 1024) {
        return `${(bytes / 1024 / 1024).toFixed(1).replace(/\.0$/, '')} MB`;
    }

    return `${Math.max(1, Math.ceil(bytes / 1024))} KB`;
}

/** Calendar day of an instant in the platform time zone (grouping key). */
export function dayKey(iso: string | null | undefined): string {
    return formatDateTime(iso, {
        year: 'numeric',
        month: '2-digit',
        day: '2-digit',
    });
}

/** "Hoy", "Ayer" or the full date, for day separators. */
export function dayLabel(iso: string | null | undefined): string {
    const key = dayKey(iso);
    const today = dayKey(new Date().toISOString());
    const yesterday = dayKey(
        new Date(Date.now() - 24 * 60 * 60 * 1000).toISOString(),
    );

    if (key === today) {
        return 'Hoy';
    }

    if (key === yesterday) {
        return 'Ayer';
    }

    return formatDateTime(iso, {
        weekday: 'long',
        day: 'numeric',
        month: 'long',
        year: 'numeric',
    });
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

import type {
    ChatPreviewAttachment,
    ChatPreviewItem,
    ChatPreviewLocation,
} from '@/components/chat-preview/types';
import {
    previewText,
    typeLabel,
} from '@/components/conversations/messageFormat';
import { viewerKind } from '@/lib/conversations/media';
import { firstUrl } from '@/lib/links';
import type { ConversationMessage } from '@/types';

function attachmentKind(type: string): ChatPreviewAttachment['kind'] {
    return (
        ['image', 'sticker', 'audio', 'video', 'document'] as const
    ).includes(type as never)
        ? (type as ChatPreviewAttachment['kind'])
        : 'other';
}

function author(message: ConversationMessage, contactName: string): string {
    if (message.direction === 'inbound') {
        return contactName;
    }

    if (message.automatic) {
        return 'Mensaje automático';
    }

    return (
        message.sender?.name ??
        message.imported?.sender_name ??
        message.integration?.name ??
        'Equipo'
    );
}

function numberOrNull(value: unknown): number | null {
    return typeof value === 'number' && Number.isFinite(value) ? value : null;
}

function textOrNull(value: unknown): string | null {
    return typeof value === 'string' && value.trim() !== ''
        ? value.trim()
        : null;
}

/** A shared location, as the chat draws it (MessageBubble). */
function locationOf(message: ConversationMessage): ChatPreviewLocation | null {
    if (message.type !== 'location' || message.revoked_at) {
        return null;
    }

    const content = (message.content ?? {}) as Record<string, unknown>;
    const name = textOrNull(content.name);
    const address = textOrNull(content.address);
    const body = textOrNull(message.body);
    const repeatsPlace =
        !body ||
        body === name ||
        body === address ||
        body === [name, address].filter(Boolean).join(', ') ||
        /^(Ubicación: )?-?\d+(\.\d+)?, ?-?\d+(\.\d+)?$/u.test(body);

    return {
        name,
        address,
        latitude: numberOrNull(content.latitude),
        longitude: numberOrNull(content.longitude),
        live: content.live === true,
        website: textOrNull(content.website),
        comment: repeatsPlace ? null : body,
    };
}

/**
 * A conversation message as a read-only preview bubble draws it
 * (ChatPreviewBubble): the inbox preview and «Conversación completa».
 * `contactName` names the customer's messages.
 */
export function toPreviewItem(
    message: ConversationMessage,
    contactName: string,
): ChatPreviewItem {
    const media = ['image', 'sticker', 'audio', 'video', 'document'];
    const attachments: ChatPreviewAttachment[] = message.attachments.map(
        (attachment) => ({
            // A PDF opens in the viewer, as in the chat.
            kind:
                viewerKind(attachment, message) === 'pdf'
                    ? 'pdf'
                    : attachmentKind(message.type),
            label: attachment.file_name ?? typeLabel(message.type),
            src: attachment.available
                ? message.type === 'image'
                    ? (attachment.thumb_url ?? attachment.url)
                    : attachment.url
                : null,
            href: attachment.available ? attachment.url : null,
            downloadUrl: attachment.available ? attachment.download_url : null,
        }),
    );

    if (attachments.length === 0 && media.includes(message.type)) {
        attachments.push({
            kind: attachmentKind(message.type),
            label: typeLabel(message.type),
        });
    }

    const location = locationOf(message);
    const text = location
        ? null
        : message.type === 'text' || media.includes(message.type)
          ? (message.body?.trim() ?? '') || null
          : previewText(message, false);
    const url = firstUrl(text);

    return {
        id: message.id,
        author: author(message, contactName),
        mine: message.direction === 'outbound',
        text,
        formatted: true,
        location,
        link: url ? { url, endpoint: message.link_preview_url ?? null } : null,
        attachments,
        at: message.sent_at ?? message.created_at,
        note:
            message.status === 'failed'
                ? 'No se pudo entregar'
                : message.template
                  ? `Plantilla ${message.template.name}`
                  : null,
    };
}

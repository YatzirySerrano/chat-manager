import {
    getContentType,
    isHostedLidUser,
    isHostedPnUser,
    isJidBot,
    isJidBroadcast,
    isJidGroup,
    isJidMetaAI,
    isJidNewsletter,
    isJidStatusBroadcast,
    isLidUser,
    isPnUser,
    jidDecode,
    normalizeMessageContent,
    type proto,
    type WAMessage,
} from 'baileys';
import type { InboundMessage, MediaDescriptor, MediaKind } from './types.js';

/**
 * Turns a Baileys message into the InboundMessage of the contract (§5).
 * Pure: no network, no socket. Groups, statuses, broadcasts, channels and
 * bots are never notified.
 */

/** Chats the service never reads from or writes to. */
export function isIgnoredJid(jid: string | null | undefined): boolean {
    if (!jid) {
        return true;
    }

    return Boolean(
        isJidGroup(jid) ||
        isJidBroadcast(jid) ||
        isJidStatusBroadcast(jid) ||
        isJidNewsletter(jid) ||
        isJidBot(jid) ||
        isJidMetaAI(jid) ||
        !isUserJid(jid),
    );
}

/** A person: phone-number JID or the privacy identifier (@lid). */
export function isUserJid(jid: string | null | undefined): boolean {
    return Boolean(
        jid &&
        (isPnUser(jid) ||
            isLidUser(jid) ||
            isHostedPnUser(jid) ||
            isHostedLidUser(jid)),
    );
}

/** Digits of a phone-number JID ("5215512345678@s.whatsapp.net"), or null. */
export function phoneOfJid(jid: string | null | undefined): string | null {
    if (!jid || !(isPnUser(jid) || isHostedPnUser(jid))) {
        return null;
    }

    const user = jidDecode(jid)?.user ?? '';

    return /^\d{5,20}$/.test(user) ? user : null;
}

function toNumber(value: unknown): number | null {
    if (value === null || value === undefined) {
        return null;
    }

    if (typeof value === 'number') {
        return Number.isFinite(value) ? value : null;
    }

    if (
        typeof value === 'object' &&
        'toNumber' in value &&
        typeof value.toNumber === 'function'
    ) {
        return (value as { toNumber: () => number }).toNumber();
    }

    const parsed = Number(value);

    return Number.isFinite(parsed) ? parsed : null;
}

function text(value: string | null | undefined): string | null {
    return typeof value === 'string' && value !== '' ? value : null;
}

type MediaMessage =
    | proto.Message.IImageMessage
    | proto.Message.IVideoMessage
    | proto.Message.IAudioMessage
    | proto.Message.IDocumentMessage
    | proto.Message.IStickerMessage;

function descriptor(
    kind: MediaKind,
    media: MediaMessage,
): MediaDescriptor | null {
    if (!media.mediaKey || (!media.directPath && !media.url)) {
        return null;
    }

    return {
        kind,
        media_key: Buffer.from(media.mediaKey).toString('base64'),
        direct_path: text(media.directPath),
        url: text(media.url),
        mime: text(media.mimetype),
        size: toNumber(media.fileLength),
    };
}

/** Phone numbers of a vCard: the WhatsApp id when present, otherwise the number as written. */
export function phonesOfVcard(vcard: string | null | undefined): string[] {
    const phones: string[] = [];

    for (const line of (vcard ?? '').split(/\r?\n/)) {
        if (!/^(item\d+\.)?TEL/i.test(line)) {
            continue;
        }

        const waid = /waid=(\d{5,20})/i.exec(line)?.[1];
        const written = line
            .slice(line.lastIndexOf(':') + 1)
            .replace(/[^\d+]/g, '');
        const phone = waid ?? written;

        if (phone !== '' && !phones.includes(phone)) {
            phones.push(phone);
        }
    }

    return phones;
}

export type PhoneResolver = (lid: string) => Promise<string | null>;

/**
 * Null when the message is not something a person wrote in a one-to-one
 * chat (protocol messages, group traffic, empty stubs).
 */
export async function normalizeMessage(
    message: WAMessage,
    resolvePhone: PhoneResolver,
): Promise<InboundMessage | null> {
    const key = message.key;
    const remoteJid = key?.remoteJid;

    if (!key?.id || !remoteJid || isIgnoredJid(remoteJid)) {
        return null;
    }

    const content = normalizeMessageContent(message.message);
    const contentType = getContentType(content);

    if (!content || !contentType) {
        return null;
    }

    // Edits, deletions, key distribution and other protocol traffic.
    if (
        contentType === 'protocolMessage' ||
        contentType === 'senderKeyDistributionMessage' ||
        content.protocolMessage
    ) {
        return null;
    }

    let phone = phoneOfJid(remoteJid) ?? phoneOfJid(key.remoteJidAlt);

    if (phone === null && isLidUser(remoteJid)) {
        phone = await resolvePhone(remoteJid).catch(() => null);
    }

    const base: InboundMessage = {
        provider_message_id: key.id,
        remote_jid: remoteJid,
        phone,
        push_name: key.fromMe ? null : text(message.pushName),
        from_me: Boolean(key.fromMe),
        timestamp:
            toNumber(message.messageTimestamp) ?? Math.floor(Date.now() / 1000),
        type: 'unsupported',
        text: null,
        quoted: null,
    };

    const withQuote = (
        context: proto.IContextInfo | null | undefined,
    ): void => {
        if (context?.stanzaId) {
            base.quoted = { provider_message_id: context.stanzaId };
        }
    };

    const withMedia = (
        kind: MediaKind,
        media: MediaMessage,
        extra: {
            fileName?: string | null;
            seconds?: number | null;
            voice?: boolean;
        } = {},
    ): void => {
        const described = descriptor(kind, media);

        if (described === null) {
            base.type = 'unsupported';

            return;
        }

        base.type = kind;
        base.media = {
            mime: described.mime,
            size: described.size,
            file_name: text(extra.fileName),
            seconds: extra.seconds ?? null,
            voice: extra.voice ?? false,
            descriptor: described,
        };
        withQuote(media.contextInfo);
    };

    if (contentType === 'conversation') {
        base.type = 'text';
        base.text = text(content.conversation);
    } else if (
        contentType === 'extendedTextMessage' &&
        content.extendedTextMessage
    ) {
        base.type = 'text';
        base.text = text(content.extendedTextMessage.text);
        withQuote(content.extendedTextMessage.contextInfo);
    } else if (contentType === 'imageMessage' && content.imageMessage) {
        withMedia('image', content.imageMessage);
        base.text = text(content.imageMessage.caption);
    } else if (
        (contentType === 'videoMessage' && content.videoMessage) ||
        (contentType === 'ptvMessage' && content.ptvMessage)
    ) {
        const video = (content.videoMessage ??
            content.ptvMessage) as proto.Message.IVideoMessage;

        withMedia('video', video, { seconds: toNumber(video.seconds) });
        base.text = text(video.caption);
    } else if (contentType === 'audioMessage' && content.audioMessage) {
        withMedia('audio', content.audioMessage, {
            seconds: toNumber(content.audioMessage.seconds),
            voice: Boolean(content.audioMessage.ptt),
        });
    } else if (contentType === 'documentMessage' && content.documentMessage) {
        withMedia('document', content.documentMessage, {
            fileName:
                content.documentMessage.fileName ??
                content.documentMessage.title,
        });
        base.text = text(content.documentMessage.caption);
    } else if (contentType === 'stickerMessage' && content.stickerMessage) {
        withMedia('sticker', content.stickerMessage);
    } else if (
        (contentType === 'locationMessage' && content.locationMessage) ||
        (contentType === 'liveLocationMessage' && content.liveLocationMessage)
    ) {
        const location = content.locationMessage ?? content.liveLocationMessage;
        const latitude = toNumber(location?.degreesLatitude);
        const longitude = toNumber(location?.degreesLongitude);

        if (latitude !== null && longitude !== null) {
            base.type = 'location';
            base.location = {
                latitude,
                longitude,
                name: text(content.locationMessage?.name),
                address: text(content.locationMessage?.address),
            };
            withQuote(location?.contextInfo);
        }
    } else if (contentType === 'contactMessage' && content.contactMessage) {
        base.type = 'contacts';
        base.contacts = [
            {
                name: text(content.contactMessage.displayName),
                phones: phonesOfVcard(content.contactMessage.vcard),
            },
        ];
        withQuote(content.contactMessage.contextInfo);
    } else if (
        contentType === 'contactsArrayMessage' &&
        content.contactsArrayMessage
    ) {
        base.type = 'contacts';
        base.contacts = (content.contactsArrayMessage.contacts ?? []).map(
            (contact) => ({
                name: text(contact.displayName),
                phones: phonesOfVcard(contact.vcard),
            }),
        );
        withQuote(content.contactsArrayMessage.contextInfo);
    } else if (
        contentType === 'reactionMessage' &&
        content.reactionMessage?.key?.id
    ) {
        base.type = 'reaction';
        base.text = text(content.reactionMessage.text);
        base.reaction = {
            provider_message_id: content.reactionMessage.key.id,
            emoji: content.reactionMessage.text ?? '',
        };
    }

    return base;
}

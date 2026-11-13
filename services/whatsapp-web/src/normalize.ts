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
import type {
    DeliveryStatus,
    InboundMessage,
    MediaDescriptor,
    MediaKind,
    MessageEdit,
    MessageRevocation,
} from './types.js';

/**
 * Turns a Baileys message into the InboundMessage of the contract (§5).
 * Pure: no network, no socket. Groups, statuses, broadcasts, channels and
 * bots are never notified.
 *
 * Everything a person can see in a one-to-one chat is represented: what the
 * platform draws natively keeps its type, and the rest arrives as
 * "unsupported" with the name of what it was (a call, an event, a
 * payment...), never as an anonymous placeholder. Traffic that is not a
 * message a person wrote (keys, votes of a poll, pins, receipts) is not
 * notified at all; edits and deletions are reported apart (protocolChange).
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

/** Largest embedded preview forwarded (WhatsApp's are a few KB). */
const THUMBNAIL_MAX_BYTES = 24 * 1024;

/** The JPEG preview WhatsApp embeds in the message, in base64. */
function thumbnail(value: Uint8Array | null | undefined): string | null {
    if (!value || value.length < 16 || value.length > THUMBNAIL_MAX_BYTES) {
        return null;
    }

    const bytes = Buffer.from(value);

    // Only JPEG (FF D8): the platform serves it as image/jpeg.
    return bytes[0] === 0xff && bytes[1] === 0xd8
        ? bytes.toString('base64')
        : null;
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

/** What a message contains, without who sent it. */
type Described = Pick<
    InboundMessage,
    | 'type'
    | 'text'
    | 'media'
    | 'location'
    | 'contacts'
    | 'reaction'
    | 'poll'
    | 'interactive'
    | 'unsupported'
    | 'view_once'
> & { context: proto.IContextInfo | null };

/** Content that is not something a person wrote: never notified. */
const SILENT_TYPES = new Set([
    'protocolMessage',
    'senderKeyDistributionMessage',
    'fastRatchetKeySenderKeyDistributionMessage',
    'pollUpdateMessage',
    'encReactionMessage',
    'encCommentMessage',
    'encEventResponseMessage',
    'secretEncryptedMessage',
    'keepInChatMessage',
    'pinInChatMessage',
    'stickerSyncRmrMessage',
    'albumMessage',
    'botInvokeMessage',
    'messageHistoryBundle',
    'messageHistoryNotice',
    'chat',
    'deviceSentMessage',
    'placeholderMessage',
    'associatedChildMessage',
    'lottieStickerMessage',
    'statusMentionMessage',
    'statusNotificationMessage',
    'newsletterAdminInviteMessage',
    'limitSharingMessage',
]);

/** What "unsupported" was, in the words the interface shows. */
const UNSUPPORTED_LABELS: Record<string, { kind: string; label: string }> = {
    call: { kind: 'call', label: 'Llamada' },
    bcallMessage: { kind: 'call', label: 'Llamada' },
    callLogMesssage: { kind: 'call', label: 'Llamada' },
    scheduledCallCreationMessage: {
        kind: 'call',
        label: 'Llamada programada',
    },
    scheduledCallEditMessage: { kind: 'call', label: 'Llamada programada' },
    eventMessage: { kind: 'event', label: 'Evento' },
    groupInviteMessage: {
        kind: 'group_invite',
        label: 'Invitación a un grupo',
    },
    productMessage: { kind: 'product', label: 'Producto del catálogo' },
    orderMessage: { kind: 'order', label: 'Pedido' },
    invoiceMessage: { kind: 'invoice', label: 'Factura' },
    requestPaymentMessage: { kind: 'payment', label: 'Solicitud de pago' },
    sendPaymentMessage: { kind: 'payment', label: 'Pago' },
    paymentInviteMessage: { kind: 'payment', label: 'Invitación de pago' },
    declinePaymentRequestMessage: { kind: 'payment', label: 'Pago rechazado' },
    cancelPaymentRequestMessage: { kind: 'payment', label: 'Pago cancelado' },
    requestPhoneNumberMessage: {
        kind: 'phone_request',
        label: 'Solicitud de número de teléfono',
    },
    newsletterFollowerInviteMessageV2: {
        kind: 'channel_invite',
        label: 'Invitación a un canal',
    },
    pollResultSnapshotMessage: {
        kind: 'poll_result',
        label: 'Resultados de una encuesta',
    },
    questionMessage: { kind: 'question', label: 'Pregunta' },
};

/** proto.WebMessageInfo.StubType values a one-to-one chat shows. */
const STUB = {
    revoke: 1,
    callMissedVoice: 40,
    callMissedVideo: 41,
} as const;

/** Name of a kind of content, for the excerpt of a quote. */
const KIND_LABELS: Record<string, string> = {
    image: 'Imagen',
    video: 'Video',
    audio: 'Audio',
    document: 'Documento',
    sticker: 'Sticker',
    location: 'Ubicación',
    contacts: 'Contacto',
    poll: 'Encuesta',
    interactive: 'Respuesta',
    unsupported: 'Mensaje',
};

function firstText(...values: (string | null | undefined)[]): string | null {
    for (const value of values) {
        if (typeof value === 'string' && value.trim() !== '') {
            return value;
        }
    }

    return null;
}

/**
 * The content of a message as the contract describes it, or null when it is
 * not something a person wrote.
 */
function describe(
    original: proto.IMessage | null | undefined,
): Described | null {
    const content = normalizeMessageContent(original);
    // "call" is the one kind of content whose field is not named "…Message".
    const contentType: string | undefined =
        getContentType(content) ?? (content?.call ? 'call' : undefined);

    if (!content || !contentType) {
        return null;
    }

    // Edits, deletions, key distribution and other protocol traffic.
    if (content.protocolMessage || SILENT_TYPES.has(contentType)) {
        return null;
    }

    const described: Described = {
        type: 'unsupported',
        text: null,
        context: null,
    };

    const viewOnce = Boolean(
        original?.viewOnceMessage ||
        original?.viewOnceMessageV2 ||
        original?.viewOnceMessageV2Extension ||
        original?.ephemeralMessage?.message?.viewOnceMessage ||
        original?.ephemeralMessage?.message?.viewOnceMessageV2,
    );

    const withMedia = (
        kind: MediaKind,
        media: MediaMessage,
        extra: {
            fileName?: string | null;
            seconds?: number | null;
            voice?: boolean;
            gif?: boolean;
            animated?: boolean;
            width?: number | null;
            height?: number | null;
            thumbnail?: string | null;
        } = {},
    ): void => {
        const described_ = descriptor(kind, media);

        described.type = kind;
        described.media = {
            mime: text(media.mimetype)?.split(';')[0]?.trim() || null,
            size: toNumber(media.fileLength),
            file_name: text(extra.fileName),
            seconds: extra.seconds ?? null,
            voice: extra.voice ?? false,
            descriptor: described_,
            ...(extra.gif ? { gif: true } : {}),
            ...(extra.animated ? { animated: true } : {}),
            ...(extra.width && extra.height
                ? { width: extra.width, height: extra.height }
                : {}),
            ...(extra.thumbnail ? { thumbnail: extra.thumbnail } : {}),
        };
        described.context = media.contextInfo ?? null;

        if (viewOnce || (media as { viewOnce?: boolean | null }).viewOnce) {
            described.view_once = true;
        }
    };

    if (contentType === 'conversation') {
        described.type = 'text';
        described.text = text(content.conversation);
    } else if (
        contentType === 'extendedTextMessage' &&
        content.extendedTextMessage
    ) {
        described.type = 'text';
        described.text = text(content.extendedTextMessage.text);
        described.context = content.extendedTextMessage.contextInfo ?? null;
    } else if (contentType === 'imageMessage' && content.imageMessage) {
        const image = content.imageMessage;

        withMedia('image', image, {
            width: toNumber(image.width),
            height: toNumber(image.height),
            thumbnail: thumbnail(image.jpegThumbnail),
        });
        described.text = text(image.caption);
    } else if (
        (contentType === 'videoMessage' && content.videoMessage) ||
        (contentType === 'ptvMessage' && content.ptvMessage)
    ) {
        const video = (content.videoMessage ??
            content.ptvMessage) as proto.Message.IVideoMessage;

        withMedia('video', video, {
            seconds: toNumber(video.seconds),
            gif: Boolean(video.gifPlayback),
            width: toNumber(video.width),
            height: toNumber(video.height),
            thumbnail: thumbnail(video.jpegThumbnail),
        });
        described.text = text(video.caption);
    } else if (contentType === 'audioMessage' && content.audioMessage) {
        withMedia('audio', content.audioMessage, {
            seconds: toNumber(content.audioMessage.seconds),
            voice: Boolean(content.audioMessage.ptt),
        });
    } else if (contentType === 'documentMessage' && content.documentMessage) {
        const document = content.documentMessage;

        withMedia('document', document, {
            fileName: document.fileName ?? document.title,
            thumbnail: thumbnail(document.jpegThumbnail),
        });
        described.text = text(document.caption);
    } else if (contentType === 'stickerMessage' && content.stickerMessage) {
        const sticker = content.stickerMessage;

        withMedia('sticker', sticker, {
            animated: Boolean(sticker.isAnimated || sticker.isLottie),
            width: toNumber(sticker.width),
            height: toNumber(sticker.height),
        });
    } else if (
        (contentType === 'locationMessage' && content.locationMessage) ||
        (contentType === 'liveLocationMessage' && content.liveLocationMessage)
    ) {
        const location = content.locationMessage ?? content.liveLocationMessage;
        const latitude = toNumber(location?.degreesLatitude);
        const longitude = toNumber(location?.degreesLongitude);

        if (latitude !== null && longitude !== null) {
            described.type = 'location';
            described.location = {
                latitude,
                longitude,
                name: text(content.locationMessage?.name),
                address: text(content.locationMessage?.address),
                ...(content.liveLocationMessage ? { live: true } : {}),
            };
            described.text = text(
                content.liveLocationMessage?.caption ??
                    content.locationMessage?.comment,
            );
            described.context = location?.contextInfo ?? null;
        } else {
            described.unsupported = { kind: 'location', label: 'Ubicación' };
        }
    } else if (contentType === 'contactMessage' && content.contactMessage) {
        described.type = 'contacts';
        described.contacts = [
            {
                name: text(content.contactMessage.displayName),
                phones: phonesOfVcard(content.contactMessage.vcard),
            },
        ];
        described.context = content.contactMessage.contextInfo ?? null;
    } else if (
        contentType === 'contactsArrayMessage' &&
        content.contactsArrayMessage
    ) {
        described.type = 'contacts';
        described.contacts = (content.contactsArrayMessage.contacts ?? []).map(
            (contact) => ({
                name: text(contact.displayName),
                phones: phonesOfVcard(contact.vcard),
            }),
        );
        described.context = content.contactsArrayMessage.contextInfo ?? null;
    } else if (contentType === 'reactionMessage') {
        if (!content.reactionMessage?.key?.id) {
            return null;
        }

        described.type = 'reaction';
        described.text = text(content.reactionMessage.text);
        described.reaction = {
            provider_message_id: content.reactionMessage.key.id,
            emoji: content.reactionMessage.text ?? '',
        };
    } else if (
        contentType === 'pollCreationMessage' ||
        contentType === 'pollCreationMessageV2' ||
        contentType === 'pollCreationMessageV3' ||
        contentType === 'pollCreationMessageV4' ||
        contentType === 'pollCreationMessageV5'
    ) {
        const poll = (content as Record<string, unknown>)[contentType] as
            | proto.Message.IPollCreationMessage
            | null
            | undefined;
        const nested = (
            poll as { message?: proto.IMessage | null } | null | undefined
        )?.message;
        const creation =
            poll?.name || poll?.options?.length
                ? poll
                : (nested?.pollCreationMessage ??
                  nested?.pollCreationMessageV2 ??
                  nested?.pollCreationMessageV3 ??
                  poll);
        const options = (creation?.options ?? [])
            .map((option) => text(option.optionName))
            .filter((option): option is string => option !== null);

        described.type = 'poll';
        described.poll = {
            question: text(creation?.name),
            options,
            multiple: toNumber(creation?.selectableOptionsCount) !== 1,
        };
        described.text = [
            `Encuesta: ${creation?.name ?? ''}`.trim(),
            ...options.map((option) => `• ${option}`),
        ].join('\n');
        described.context = creation?.contextInfo ?? null;
    } else if (
        contentType === 'buttonsResponseMessage' &&
        content.buttonsResponseMessage
    ) {
        const reply = content.buttonsResponseMessage;

        described.type = 'interactive';
        described.interactive = {
            kind: 'button_reply',
            title: firstText(reply.selectedDisplayText, reply.selectedButtonId),
            description: null,
        };
        described.text = described.interactive.title;
        described.context = reply.contextInfo ?? null;
    } else if (
        contentType === 'templateButtonReplyMessage' &&
        content.templateButtonReplyMessage
    ) {
        const reply = content.templateButtonReplyMessage;

        described.type = 'interactive';
        described.interactive = {
            kind: 'button_reply',
            title: firstText(reply.selectedDisplayText, reply.selectedId),
            description: null,
        };
        described.text = described.interactive.title;
        described.context = reply.contextInfo ?? null;
    } else if (
        contentType === 'listResponseMessage' &&
        content.listResponseMessage
    ) {
        const reply = content.listResponseMessage;

        described.type = 'interactive';
        described.interactive = {
            kind: 'list_reply',
            title: firstText(reply.title, reply.singleSelectReply?.selectedRowId),
            description: text(reply.description),
        };
        described.text = described.interactive.title;
        described.context = reply.contextInfo ?? null;
    } else if (
        contentType === 'interactiveResponseMessage' &&
        content.interactiveResponseMessage
    ) {
        const reply = content.interactiveResponseMessage;

        described.type = 'interactive';
        described.interactive = {
            kind: 'button_reply',
            title: firstText(
                reply.body?.text,
                reply.nativeFlowResponseMessage?.name,
            ),
            description: null,
        };
        described.text = described.interactive.title;
        described.context = reply.contextInfo ?? null;
    } else if (contentType === 'buttonsMessage' && content.buttonsMessage) {
        // Messages with buttons, lists or templates that a business account
        // sends: their text is what a person reads.
        const buttons = content.buttonsMessage;

        described.type = 'text';
        described.text = firstText(
            buttons.contentText,
            buttons.text,
            buttons.footerText,
        );
        described.context = buttons.contextInfo ?? null;
    } else if (contentType === 'listMessage' && content.listMessage) {
        const list = content.listMessage;

        described.type = 'text';
        described.text = firstText(
            [list.title, list.description]
                .filter((part) => typeof part === 'string' && part !== '')
                .join('\n'),
            list.buttonText,
        );
        described.context = list.contextInfo ?? null;
    } else if (contentType === 'templateMessage' && content.templateMessage) {
        const template =
            content.templateMessage.hydratedTemplate ??
            content.templateMessage.hydratedFourRowTemplate;

        described.type = 'text';
        described.text = firstText(
            template?.hydratedContentText,
            template?.hydratedTitleText,
            template?.hydratedFooterText,
        );
        described.context = content.templateMessage.contextInfo ?? null;
    } else if (
        contentType === 'interactiveMessage' &&
        content.interactiveMessage
    ) {
        const interactive = content.interactiveMessage;

        described.type = 'text';
        described.text = firstText(
            interactive.body?.text,
            interactive.header?.title,
            interactive.footer?.text,
        );
        described.context = interactive.contextInfo ?? null;
    } else if (
        contentType === 'highlyStructuredMessage' &&
        content.highlyStructuredMessage
    ) {
        described.type = 'text';
        described.text = firstText(
            content.highlyStructuredMessage.hydratedHsm?.hydratedTemplate
                ?.hydratedContentText,
        );
    } else {
        described.unsupported = UNSUPPORTED_LABELS[contentType] ?? {
            kind: contentType.replace(/Message(V\d+)?$/, '') || 'unknown',
            label: 'Mensaje no compatible',
        };
        described.text = firstText(
            content.eventMessage?.name,
            content.groupInviteMessage?.groupName,
            content.productMessage?.product?.title,
            content.orderMessage?.orderTitle,
            content.orderMessage?.message,
        );
    }

    // A text-like message that ended up with nothing to read.
    if (described.type === 'text' && described.text === null) {
        described.type = 'unsupported';
        described.unsupported = {
            kind: contentType.replace(/Message(V\d+)?$/, '') || 'unknown',
            label: 'Mensaje no compatible',
        };
    }

    // Seen-once media usually reaches a linked device without its download
    // key: the message is kept and says what it was.
    if (viewOnce) {
        described.view_once = true;
    }

    return described;
}

/** A short excerpt of a quoted message: its text, or the name of what it was. */
function excerpt(message: proto.IMessage | null | undefined): string | null {
    const described = describe(message);

    if (described === null) {
        return null;
    }

    const body =
        described.text ??
        described.media?.file_name ??
        described.unsupported?.label ??
        KIND_LABELS[described.type] ??
        null;

    return body === null ? null : [...body].slice(0, 280).join('');
}

/** Same person, whichever of their identifiers (phone or @lid) each side used. */
function sameUser(
    left: string | null | undefined,
    right: string | null | undefined,
): boolean {
    const a = left ? jidDecode(left)?.user : undefined;
    const b = right ? jidDecode(right)?.user : undefined;

    return a !== undefined && a !== '' && a === b;
}

function quoteOf(
    context: proto.IContextInfo | null,
    key: WAMessage['key'],
): InboundMessage['quoted'] {
    if (!context?.stanzaId) {
        return null;
    }

    const quoted: NonNullable<InboundMessage['quoted']> = {
        provider_message_id: context.stanzaId,
    };

    // In a one-to-one chat the quoted author is either the other person or us.
    if (context.participant) {
        quoted.from_me = !(
            sameUser(context.participant, key.remoteJid) ||
            sameUser(context.participant, key.remoteJidAlt)
        );
    }

    const preview = excerpt(context.quotedMessage);

    if (preview !== null) {
        quoted.text = preview;
    }

    return quoted;
}

/** proto.WebMessageInfo.Status → the delivery state of the contract. */
function deliveryStatus(value: unknown): DeliveryStatus | null {
    switch (toNumber(value)) {
        case 0:
            return 'failed';
        case 2:
            return 'sent';
        case 3:
            return 'delivered';
        case 4:
        case 5:
            return 'read';
        default:
            return null;
    }
}

/**
 * Null when the message is not something a person wrote in a one-to-one
 * chat (protocol messages, group traffic, empty stubs).
 *
 * `historical` adds what only the history WhatsApp hands to a new device
 * carries: the delivery state of our own messages, the reactions attached
 * to each one and the messages their sender had already deleted.
 */
export async function normalizeMessage(
    message: WAMessage,
    resolvePhone: PhoneResolver,
    options: { historical?: boolean } = {},
): Promise<InboundMessage | null> {
    const key = message.key;
    const remoteJid = key?.remoteJid;

    if (!key?.id || !remoteJid || isIgnoredJid(remoteJid)) {
        return null;
    }

    const stub = toNumber(message.messageStubType);
    let described = describe(message.message);
    let revoked = false;

    if (described === null) {
        if (stub === STUB.callMissedVoice || stub === STUB.callMissedVideo) {
            described = {
                type: 'unsupported',
                text: null,
                context: null,
                unsupported: {
                    kind: 'call_missed',
                    label:
                        stub === STUB.callMissedVideo
                            ? 'Videollamada perdida'
                            : 'Llamada perdida',
                },
            };
        } else if (
            options.historical &&
            stub === STUB.revoke &&
            !message.message
        ) {
            described = { type: 'text', text: null, context: null };
            revoked = true;
        } else {
            return null;
        }
    }

    let phone = phoneOfJid(remoteJid) ?? phoneOfJid(key.remoteJidAlt);

    if (phone === null && isLidUser(remoteJid)) {
        phone = await resolvePhone(remoteJid).catch(() => null);
    }

    const { context, ...content } = described;
    const base: InboundMessage = {
        provider_message_id: key.id,
        remote_jid: remoteJid,
        phone,
        push_name: key.fromMe ? null : text(message.pushName),
        from_me: Boolean(key.fromMe),
        timestamp:
            toNumber(message.messageTimestamp) ?? Math.floor(Date.now() / 1000),
        ...content,
        quoted: content.type === 'reaction' ? null : quoteOf(context, key),
    };

    if (options.historical) {
        if (revoked) {
            base.revoked = true;
        }

        if (base.from_me) {
            base.status = deliveryStatus(message.status);
        }

        const reactions = (message.reactions ?? [])
            .filter((reaction) => typeof reaction.text === 'string')
            .map((reaction) => ({
                emoji: reaction.text as string,
                from_me: Boolean(reaction.key?.fromMe),
            }))
            .filter((reaction) => reaction.emoji !== '');

        if (reactions.length > 0) {
            base.reactions = reactions.slice(0, 4);
        }
    }

    return base;
}

/** proto.Message.ProtocolMessage.Type. */
const PROTOCOL = { revoke: 0, edit: 14 } as const;

export type ProtocolChange =
    | { kind: 'edit'; data: MessageEdit }
    | { kind: 'revoke'; data: MessageRevocation };

/** The new content of an edited message, as the contract reports it. */
export function editOf(
    target: { id: string; remoteJid: string; fromMe: boolean },
    edited: proto.IMessage | null | undefined,
    timestamp: number | null,
): MessageEdit | null {
    const described = describe(edited);

    if (described === null || isIgnoredJid(target.remoteJid)) {
        return null;
    }

    return {
        provider_message_id: target.id,
        remote_jid: target.remoteJid,
        from_me: target.fromMe,
        timestamp: timestamp ?? Math.floor(Date.now() / 1000),
        type: described.type,
        text: described.text,
    };
}

/**
 * The edit or the "delete for everyone" a protocol message carries, or null
 * when the message is anything else. WhatsApp sends both as a message of
 * their own that names the original one.
 */
export function protocolChange(message: WAMessage): ProtocolChange | null {
    const key = message.key;
    const remoteJid = key?.remoteJid;
    const protocol = normalizeMessageContent(message.message)?.protocolMessage;
    const targetId = protocol?.key?.id;

    if (!protocol || !targetId || !remoteJid || isIgnoredJid(remoteJid)) {
        return null;
    }

    const sentAt =
        toNumber(message.messageTimestamp) ?? Math.floor(Date.now() / 1000);
    const target = {
        id: targetId,
        remoteJid,
        fromMe: Boolean(key.fromMe),
    };

    if (protocol.type === PROTOCOL.revoke) {
        return {
            kind: 'revoke',
            data: {
                provider_message_id: targetId,
                remote_jid: remoteJid,
                from_me: target.fromMe,
                timestamp: sentAt,
            },
        };
    }

    if (protocol.type === PROTOCOL.edit) {
        const editedAt = toNumber(protocol.timestampMs);
        const data = editOf(
            target,
            protocol.editedMessage,
            editedAt === null ? sentAt : Math.floor(editedAt / 1000),
        );

        return data === null ? null : { kind: 'edit', data };
    }

    return null;
}

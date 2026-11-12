import type { Readable } from 'node:stream';
import {
    Browsers,
    downloadContentFromMessage,
    fetchLatestWaWebVersion,
    makeCacheableSignalKeyStore,
    makeWASocket,
    type AnyMessageContent,
    type WAMessage,
    type WAVersion,
} from 'baileys';
import { pino } from 'pino';
import type { Logger } from './logger.js';
import { isIgnoredJid, phoneOfJid } from './normalize.js';
import type { MediaDescriptor } from './types.js';
import type {
    MediaDownloader,
    WaConnection,
    WaFactory,
    WaOutgoing,
    WaQuoted,
} from './wa.js';

/**
 * The only module that talks to Baileys. It opens one WhatsApp Web socket
 * per call and exposes it through the WaConnection surface (src/wa.ts).
 *
 * Privacy and scope:
 * - history is never synchronized (syncFullHistory false, history
 *   notifications refused);
 * - groups, statuses, broadcasts and channels are ignored at the socket;
 * - the device does not mark the account as online, so the phone keeps
 *   receiving its notifications;
 * - the library logs nothing unless WAWEB_LIBRARY_LOG_LEVEL asks for it,
 *   because its debug output carries message content.
 */
const FIRST_QR_MS = 60_000;
const NEXT_QR_MS = 20_000;
const VERSION_TTL_MS = 6 * 3600 * 1000;

let cachedVersion: { version: WAVersion; at: number } | null = null;

/** WhatsApp refuses clients that announce an old web version; ask for the current one. */
async function currentVersion(logger: Logger): Promise<WAVersion | undefined> {
    if (
        cachedVersion !== null &&
        Date.now() - cachedVersion.at < VERSION_TTL_MS
    ) {
        return cachedVersion.version;
    }

    try {
        const latest = await fetchLatestWaWebVersion({
            signal: AbortSignal.timeout(8000),
        });

        if (!latest.error && Array.isArray(latest.version)) {
            cachedVersion = { version: latest.version, at: Date.now() };

            return latest.version;
        }
    } catch {
        // Falls back to the version bundled with the library.
    }

    logger.warn(
        'The current WhatsApp Web version could not be read; using the one bundled with the library',
    );

    return cachedVersion?.version;
}

function outgoingContent(content: WaOutgoing): AnyMessageContent {
    const file = content.filePath === null ? null : { url: content.filePath };
    const caption = content.text ?? undefined;
    const mimetype = content.mime ?? undefined;

    if (content.kind === 'text' || file === null) {
        return { text: content.text ?? '' };
    }

    switch (content.kind) {
        case 'image':
            return { image: file, caption, mimetype };
        case 'video':
            return { video: file, caption, mimetype };
        case 'audio':
            return {
                audio: file,
                mimetype: mimetype ?? 'audio/ogg; codecs=opus',
                ptt: content.voice,
            };
        case 'sticker':
            return { sticker: file, mimetype };
        case 'document':
            return {
                document: file,
                mimetype: mimetype ?? 'application/octet-stream',
                fileName: content.fileName ?? 'archivo',
                caption,
            };
    }
}

/** The quoted message as WhatsApp needs it to draw the reply. */
function quotedMessage(quoted: WaQuoted): WAMessage {
    return {
        key: {
            id: quoted.id,
            remoteJid: quoted.remoteJid,
            fromMe: quoted.fromMe,
        },
        message: { conversation: quoted.text ?? '' },
    };
}

export function createBaileysFactory(options: {
    logger: Logger;
    libraryLogLevel: string;
}): WaFactory {
    const libraryLogger = pino({
        level: options.libraryLogLevel,
        base: { component: 'baileys' },
    });

    return async ({ auth, onEvent }): Promise<WaConnection> => {
        const version = await currentVersion(options.logger);
        let closed = false;
        let qrCount = 0;

        const socket = makeWASocket({
            ...(version ? { version } : {}),
            auth: {
                creds: auth.state.creds,
                keys: makeCacheableSignalKeyStore(
                    auth.state.keys,
                    libraryLogger,
                ),
            },
            logger: libraryLogger,
            browser: Browsers.ubuntu('Chrome'),
            syncFullHistory: false,
            shouldSyncHistoryMessage: () => false,
            markOnlineOnConnect: false,
            emitOwnEvents: false,
            generateHighQualityLinkPreview: false,
            shouldIgnoreJid: (jid) => isIgnoredJid(jid),
            getMessage: async () => undefined,
        });

        socket.ev.on('creds.update', () => {
            void auth.saveCreds();
        });

        socket.ev.on('connection.update', (update) => {
            if (closed) {
                return;
            }

            if (update.qr) {
                qrCount += 1;
                onEvent({
                    type: 'qr',
                    value: update.qr,
                    ttlMs: qrCount === 1 ? FIRST_QR_MS : NEXT_QR_MS,
                });
            }

            if (update.isNewLogin) {
                onEvent({ type: 'pairing' });
            }

            if (update.connection === 'open') {
                onEvent({
                    type: 'open',
                    me: {
                        jid: socket.user?.id ?? '',
                        name: socket.user?.name ?? null,
                    },
                });
            }

            if (update.connection === 'close') {
                closed = true;

                const error = update.lastDisconnect?.error as
                    | (Error & { output?: { statusCode?: number } })
                    | undefined;

                onEvent({
                    type: 'close',
                    statusCode: error?.output?.statusCode ?? null,
                    detail: error?.message ?? 'closed',
                });
            }
        });

        socket.ev.on('messages.upsert', ({ messages, type }) => {
            if (!closed) {
                onEvent({ type: 'messages', upsert: type, messages });
            }
        });

        socket.ev.on('messages.update', (updates) => {
            if (closed) {
                return;
            }

            const statuses = updates
                .filter(
                    (update) =>
                        typeof update.update.status === 'number' &&
                        update.key.id &&
                        update.key.remoteJid,
                )
                .map((update) => ({
                    id: update.key.id as string,
                    remoteJid: update.key.remoteJid as string,
                    fromMe: Boolean(update.key.fromMe),
                    status: update.update.status as number,
                }));

            if (statuses.length > 0) {
                onEvent({ type: 'statuses', updates: statuses });
            }
        });

        return {
            async send(jid, content, { messageId, quoted }) {
                const sent = await socket.sendMessage(
                    jid,
                    outgoingContent(content),
                    {
                        messageId,
                        ...(quoted ? { quoted: quotedMessage(quoted) } : {}),
                    },
                );

                if (!sent?.key.id) {
                    throw new Error('WhatsApp returned no message');
                }

                const timestamp =
                    Number(sent.messageTimestamp ?? 0) ||
                    Math.floor(Date.now() / 1000);

                return { id: sent.key.id, timestamp };
            },

            async resolveJid(digits) {
                const [result] = (await socket.onWhatsApp(digits)) ?? [];

                return result?.exists ? result.jid : null;
            },

            async phoneForLid(lid) {
                const jid =
                    await socket.signalRepository.lidMapping.getPNForLID(lid);

                return phoneOfJid(jid?.replace(/:\d+@/, '@'));
            },

            async logout() {
                closed = true;
                await socket.logout();
            },

            close() {
                closed = true;
                void socket.end(undefined).catch(() => undefined);
            },
        };
    };
}

/** Downloads and decrypts a received file from WhatsApp's media servers. */
export const downloadMedia: MediaDownloader = async (
    descriptor: MediaDescriptor,
): Promise<Readable> =>
    downloadContentFromMessage(
        {
            mediaKey: Buffer.from(descriptor.media_key, 'base64'),
            directPath: descriptor.direct_path,
            url: descriptor.url,
        },
        descriptor.kind,
    );

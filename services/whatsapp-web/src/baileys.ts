import type { Readable } from 'node:stream';
import {
    Browsers,
    downloadContentFromMessage,
    fetchLatestWaWebVersion,
    makeCacheableSignalKeyStore,
    makeWASocket,
    type AnyMessageContent,
    type WABusinessProfile,
    type WAMessage,
    type WAVersion,
} from 'baileys';
import { pino } from 'pino';
import type { Logger } from './logger.js';
import { isIgnoredJid, isUserJid, phoneOfJid } from './normalize.js';
import type { MediaDescriptor } from './types.js';
import type {
    MediaDownloader,
    WaBusinessProfile,
    WaConnection,
    WaFactory,
    WaOutgoing,
    WaProfile,
    WaQuoted,
} from './wa.js';

/**
 * The only module that talks to Baileys. It opens one WhatsApp Web socket
 * per call and exposes it through the WaConnection surface (src/wa.ts).
 *
 * Privacy and scope:
 * - the chat history WhatsApp hands to a newly linked device is requested
 *   only for sessions linked with `history` (one-to-one chats only; see
 *   Session.onHistory). Such a session registers as a desktop client, which
 *   is what makes the phone share the whole history and not only the last
 *   weeks; a session linked without it keeps registering as before and
 *   refuses every history notification;
 * - groups, statuses, broadcasts and channels are ignored at the socket;
 * - the device does not mark the account as online, so the phone keeps
 *   receiving its notifications;
 * - the library logs nothing unless WAWEB_LIBRARY_LOG_LEVEL asks for it,
 *   because its debug output carries message content.
 */
const FIRST_QR_MS = 60_000;
const NEXT_QR_MS = 20_000;
const VERSION_TTL_MS = 6 * 3600 * 1000;

/** proto.WebMessageInfo.StubType.REVOKE. */
const STUB_REVOKE = 1;

/** proto.HistorySync.HistorySyncType, by value. */
const HISTORY_SYNC_TYPES: Record<number, string> = {
    0: 'initial_bootstrap',
    1: 'initial_status_v3',
    2: 'full',
    3: 'recent',
    4: 'push_name',
    5: 'non_blocking_data',
    6: 'on_demand',
};

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

    return async ({ auth, history, onEvent }): Promise<WaConnection> => {
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
            browser: history
                ? Browsers.macOS('Desktop')
                : Browsers.ubuntu('Chrome'),
            syncFullHistory: history,
            shouldSyncHistoryMessage: () => history,
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

        socket.ev.on(
            'messaging-history.set',
            ({ messages, contacts, progress, syncType }) => {
                if (closed || !history) {
                    return;
                }

                const names: Record<string, string> = {};

                for (const contact of contacts ?? []) {
                    const name = contact.name ?? contact.notify;

                    if (typeof name !== 'string' || name.trim() === '') {
                        continue;
                    }

                    for (const jid of [
                        contact.id,
                        contact.lid,
                        contact.phoneNumber,
                    ]) {
                        if (typeof jid === 'string' && isUserJid(jid)) {
                            names[jid] = name;
                        }
                    }
                }

                onEvent({
                    type: 'history',
                    messages: messages ?? [],
                    names,
                    progress: typeof progress === 'number' ? progress : null,
                    syncType:
                        syncType === null || syncType === undefined
                            ? null
                            : (HISTORY_SYNC_TYPES[Number(syncType)] ??
                              String(syncType)),
                });
            },
        );

        socket.ev.on('messages.update', (updates) => {
            if (closed) {
                return;
            }

            // Baileys reports an edit as the new content of the original
            // message, and a "delete for everyone" as that message emptied.
            const edits = [];
            const revokes = [];

            for (const { key, update } of updates) {
                if (!key.id || !key.remoteJid) {
                    continue;
                }

                const target = {
                    id: key.id,
                    remoteJid: key.remoteJid,
                    fromMe: Boolean(key.fromMe),
                };
                const edited = update.message?.editedMessage?.message;

                if (edited) {
                    edits.push({
                        ...target,
                        timestamp:
                            Number(update.messageTimestamp ?? 0) || null,
                        message: edited,
                    });
                } else if (
                    update.message === null &&
                    update.messageStubType === STUB_REVOKE
                ) {
                    revokes.push(target);
                }
            }

            if (edits.length > 0) {
                onEvent({ type: 'edits', updates: edits });
            }

            if (revokes.length > 0) {
                onEvent({ type: 'revokes', updates: revokes });
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

            async profile(jid) {
                // Each part fails on its own: a private picture must not
                // hide a public "about", and the other way around.
                const [picture, status, business] = await Promise.all([
                    orNothing(
                        socket.profilePictureUrl(
                            jid,
                            'image',
                            PROFILE_TIMEOUT_MS,
                        ),
                    ),
                    orNothing(socket.fetchStatus(jid)),
                    orNothing(socket.getBusinessProfile(jid)),
                ]);

                return {
                    picture_url: profilePicture(picture),
                    ...statusOf(status?.[0]?.status),
                    business: businessProfile(business),
                };
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

const PROFILE_TIMEOUT_MS = 10_000;

/** The answer, or undefined when WhatsApp refused it (privacy) or did not answer in time. */
function orNothing<T>(promise: Promise<T>): Promise<T | undefined> {
    let timer: NodeJS.Timeout | undefined;

    return Promise.race([
        promise.catch(() => undefined),
        new Promise<undefined>((resolve) => {
            timer = setTimeout(() => resolve(undefined), PROFILE_TIMEOUT_MS);
        }),
    ]).finally(() => clearTimeout(timer));
}

function text(value: unknown, max = 1000): string | null {
    if (typeof value !== 'string') {
        return null;
    }

    const trimmed = value.trim();

    return trimmed === '' ? null : trimmed.slice(0, max);
}

function profilePicture(url: unknown): string | null {
    const value = text(url, 2000);

    return value !== null && value.startsWith('https://') ? value : null;
}

function statusOf(
    data: unknown,
): Pick<WaProfile, 'about' | 'about_set_at'> {
    const status = data as { status?: unknown; setAt?: unknown } | undefined;
    const about = text(status?.status, 700);
    const at =
        status?.setAt instanceof Date
            ? Math.floor(status.setAt.getTime() / 1000)
            : 0;

    return {
        about,
        about_set_at: about !== null && at > 0 ? at : null,
    };
}

function minutes(value: unknown): number | null {
    const number = Number(value);

    return Number.isInteger(number) && number >= 0 && number <= 1440
        ? number
        : null;
}

function businessProfile(
    profile: WABusinessProfile | void | undefined,
): WaBusinessProfile | null {
    if (!profile) {
        return null;
    }

    const config =
        profile.business_hours?.business_config ??
        profile.business_hours?.config ??
        [];
    const days = config
        .filter((day) => typeof day?.day_of_week === 'string')
        .slice(0, 14)
        .map((day) => ({
            day: String(day.day_of_week).slice(0, 10),
            mode: String(day.mode ?? '').slice(0, 30),
            open: minutes(day.open_time),
            close: minutes(day.close_time),
        }));
    const result: WaBusinessProfile = {
        category: text(profile.category, 200),
        description: text(profile.description, 1000),
        email: text(profile.email, 200),
        website: (profile.website ?? [])
            .map((site) => text(site, 300))
            .filter((site): site is string => site !== null)
            .slice(0, 5),
        address: text(profile.address, 500),
        hours:
            days.length > 0
                ? {
                      timezone: text(profile.business_hours?.timezone, 60),
                      days,
                  }
                : null,
    };

    // WhatsApp answers an empty profile for a personal account.
    const empty =
        result.category === null &&
        result.description === null &&
        result.email === null &&
        result.website.length === 0 &&
        result.address === null &&
        result.hours === null;

    return empty ? null : result;
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

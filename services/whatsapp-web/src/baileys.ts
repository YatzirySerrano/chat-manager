import type { Readable } from 'node:stream';
import {
    Browsers,
    downloadContentFromMessage,
    fetchLatestWaWebVersion,
    makeCacheableSignalKeyStore,
    makeWASocket,
    type AnyMessageContent,
    type proto,
    type WABusinessProfile,
    type WAMessage,
    type WAVersion,
} from 'baileys';
import { pino } from 'pino';
import type { Logger } from './logger.js';
import { isIgnoredJid, isUserJid, phoneOfJid } from './normalize.js';
import type { MediaDescriptor } from './types.js';
import {
    CLOSE,
    type MediaDownloader,
    type WaBusinessProfile,
    type WaConnection,
    type WaFactory,
    type WaOutgoing,
    type WaProfile,
    type WaQuoted,
} from './wa.js';

/**
 * The only module that talks to Baileys. It opens one WhatsApp Web socket
 * per call and exposes it through the WaConnection surface (src/wa.ts).
 *
 * Privacy and scope:
 * - the chat history WhatsApp hands to a newly linked device is requested
 *   only for sessions linked with `history` (one-to-one chats only; see
 *   Session.onHistory). Every session registers as Chrome: since
 *   November 2026 WhatsApp ends with 428 (before any QR) the sockets that
 *   announce a "Desktop" client, so the history is the one WhatsApp shares
 *   with a web client; a session linked without `history` refuses every
 *   history notification;
 * - groups, statuses, broadcasts and channels are ignored at the socket;
 * - the device does not mark the account as online, so the phone keeps
 *   receiving its notifications;
 * - the library logs nothing unless WAWEB_LIBRARY_LOG_LEVEL asks for it,
 *   because its debug output carries message content.
 */
const FIRST_QR_MS = 60_000;
const NEXT_QR_MS = 20_000;
const VERSION_TTL_MS = 6 * 3600 * 1000;

/**
 * Socket timings, explicit so a library update never changes them
 * silently. Baileys pings every KEEP_ALIVE_MS and ends the socket itself
 * (408) when nothing arrived for that long plus 5 s.
 */
const KEEP_ALIVE_MS = 25_000;
const CONNECT_TIMEOUT_MS = 30_000;
const QUERY_TIMEOUT_MS = 60_000;
const RETRY_REQUEST_DELAY_MS = 500;
const MAX_MESSAGE_RETRIES = 5;

/**
 * Watchdog on top of the library's keepalive: a socket that receives no
 * frame for WATCHDOG_SILENCE_MS while open, or that never opens within
 * WATCHDOG_OPEN_MS with registered credentials, is closed and reported as
 * a lost connection (408), so the session reconnects instead of staying
 * "connected" on a dead stream (a half-open TCP connection, a hung
 * handshake, a library bug).
 */
export const WATCHDOG_INTERVAL_MS = 30_000;
export const WATCHDOG_SILENCE_MS = 3 * 60_000;
export const WATCHDOG_OPEN_MS = 2 * 60_000;

/**
 * Messages sent in the last hours, kept in memory only, so a recipient
 * whose phone could not decrypt one can ask for it again (getMessage) and
 * does not stay on "waiting for this message".
 */
const SENT_CACHE_LIMIT = 2000;
const SENT_CACHE_TTL_MS = 6 * 3600 * 1000;

export class RecentMessages {
    private readonly entries = new Map<
        string,
        { message: proto.IMessage; at: number }
    >();

    constructor(private readonly now: () => number = Date.now) {}

    remember(key: string, message: proto.IMessage | null | undefined): void {
        if (!message) {
            return;
        }

        this.entries.delete(key);
        this.entries.set(key, { message, at: this.now() });

        while (this.entries.size > SENT_CACHE_LIMIT) {
            const oldest = this.entries.keys().next().value;

            if (oldest === undefined) {
                break;
            }

            this.entries.delete(oldest);
        }
    }

    find(key: string): proto.IMessage | undefined {
        const entry = this.entries.get(key);

        if (!entry) {
            return undefined;
        }

        if (this.now() - entry.at > SENT_CACHE_TTL_MS) {
            this.entries.delete(key);

            return undefined;
        }

        return entry.message;
    }
}

/** Decides when a socket must be given up as dead (see WATCHDOG_*). */
export function watchdogVerdict(options: {
    now: number;
    createdAt: number;
    lastFrameAt: number;
    open: boolean;
    registered: boolean;
}): string | null {
    if (
        options.open &&
        options.now - options.lastFrameAt > WATCHDOG_SILENCE_MS
    ) {
        return 'watchdog: no frames from WhatsApp while connected';
    }

    if (
        !options.open &&
        options.registered &&
        options.now - options.createdAt > WATCHDOG_OPEN_MS
    ) {
        return 'watchdog: the connection never opened';
    }

    return null;
}

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
    const recent = new RecentMessages();

    return async ({
        connectionId,
        auth,
        history,
        onEvent,
    }): Promise<WaConnection> => {
        const version = await currentVersion(options.logger);
        let closed = false;
        let open = false;
        let qrCount = 0;
        const createdAt = Date.now();
        let lastFrameAt = createdAt;

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
            // A "Desktop" browser is refused by WhatsApp with 428 (no QR).
            // Fixed for the life of the device (it depends only on how it
            // was linked), so WhatsApp sees the same companion on every start.
            browser: history
                ? Browsers.macOS('Chrome')
                : Browsers.ubuntu('Chrome'),
            syncFullHistory: history,
            shouldSyncHistoryMessage: () => history,
            markOnlineOnConnect: false,
            emitOwnEvents: false,
            generateHighQualityLinkPreview: false,
            keepAliveIntervalMs: KEEP_ALIVE_MS,
            connectTimeoutMs: CONNECT_TIMEOUT_MS,
            defaultQueryTimeoutMs: QUERY_TIMEOUT_MS,
            retryRequestDelayMs: RETRY_REQUEST_DELAY_MS,
            maxMsgRetryCount: MAX_MESSAGE_RETRIES,
            shouldIgnoreJid: (jid) => isIgnoredJid(jid),
            getMessage: async (key) =>
                key.id ? recent.find(`${connectionId}:${key.id}`) : undefined,
        });

        socket.ev.on('creds.update', () => {
            void auth.saveCreds().catch((error: unknown) => {
                options.logger.error(
                    { connection_id: connectionId, err: String(error) },
                    'The session credentials could not be saved',
                );
            });
        });

        socket.ws.on('frame', () => {
            lastFrameAt = Date.now();
        });

        const watchdog = setInterval(() => {
            if (closed) {
                clearInterval(watchdog);

                return;
            }

            const verdict = watchdogVerdict({
                now: Date.now(),
                createdAt,
                lastFrameAt,
                open,
                registered: auth.isRegistered(),
            });

            if (verdict === null) {
                return;
            }

            options.logger.warn(
                { connection_id: connectionId, detail: verdict },
                'Closing a WhatsApp socket that stopped answering',
            );
            closed = true;
            clearInterval(watchdog);
            onEvent({
                type: 'close',
                statusCode: CLOSE.timedOut,
                detail: verdict,
            });
            void socket.end(undefined).catch(() => undefined);
        }, WATCHDOG_INTERVAL_MS);

        watchdog.unref();

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
                open = true;
                lastFrameAt = Date.now();
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
                clearInterval(watchdog);

                const error = update.lastDisconnect?.error as
                    (Error & { output?: { statusCode?: number } }) | undefined;

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
                        timestamp: Number(update.messageTimestamp ?? 0) || null,
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

        /**
         * The contact's picture: the full one first, the preview when
         * WhatsApp refuses or does not serve the full one, and the same
         * through the contact's @lid when its phone JID gets nothing (since
         * the LID migration WhatsApp answers many pictures only that way).
         * `unavailable` when WhatsApp did not answer at all (timeout, socket
         * error): the stored copy must be kept, not removed.
         */
        const pictureOf = async (
            jid: string,
        ): Promise<{ url: string | null; unavailable: boolean }> => {
            const targets = [jid];

            if (jid.endsWith('@s.whatsapp.net')) {
                const lid = await orNothing(
                    socket.signalRepository.lidMapping.getLIDForPN(jid),
                );

                if (typeof lid === 'string' && lid.endsWith('@lid')) {
                    targets.push(lid.replace(/:\d+@/, '@'));
                }
            }

            for (const target of targets) {
                for (const type of ['image', 'preview'] as const) {
                    const answer = await pictureAnswer(
                        socket.profilePictureUrl(
                            target,
                            type,
                            PROFILE_TIMEOUT_MS,
                        ),
                    );

                    if (answer.kind === 'url') {
                        const url = profilePicture(answer.url);

                        if (url !== null) {
                            return { url, unavailable: false };
                        }
                    } else if (answer.kind === 'failed') {
                        // No answer: asking again now would only add timeouts.
                        return { url: null, unavailable: true };
                    }
                }
            }

            return { url: null, unavailable: false };
        };

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

                recent.remember(`${connectionId}:${sent.key.id}`, sent.message);

                const timestamp =
                    Number(sent.messageTimestamp ?? 0) ||
                    Math.floor(Date.now() / 1000);

                return { id: sent.key.id, timestamp };
            },

            async modify(jid, messageId, change) {
                const key = { remoteJid: jid, fromMe: true, id: messageId };

                await socket.sendMessage(
                    jid,
                    change.kind === 'edit'
                        ? { text: change.text, edit: key }
                        : { delete: key },
                );
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
                    pictureOf(jid),
                    orNothing(socket.fetchStatus(jid)),
                    orNothing(socket.getBusinessProfile(jid)),
                ]);

                return {
                    picture_url: picture.url,
                    ...(picture.unavailable && picture.url === null
                        ? { picture_unavailable: true }
                        : {}),
                    ...statusOf(status?.[0]?.status),
                    business: businessProfile(business),
                };
            },

            async logout() {
                closed = true;
                clearInterval(watchdog);
                await socket.logout();
            },

            close() {
                closed = true;
                clearInterval(watchdog);
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

/**
 * One picture query: its URL, "none" when WhatsApp says there is none or
 * it is private (401 not-authorized, 404 item-not-found), or "failed" when
 * it did not answer (timeout, closed socket, anything else).
 */
async function pictureAnswer(
    promise: Promise<string | undefined>,
): Promise<
    { kind: 'url'; url: unknown } | { kind: 'none' } | { kind: 'failed' }
> {
    let timer: NodeJS.Timeout | undefined;

    try {
        return await Promise.race([
            promise.then(
                (url) =>
                    url
                        ? { kind: 'url' as const, url }
                        : { kind: 'none' as const },
                (error: unknown) => {
                    const code = (
                        error as {
                            output?: { statusCode?: number };
                            data?: unknown;
                        } | null
                    )?.output?.statusCode;
                    const data = (error as { data?: unknown } | null)?.data;

                    return code === 401 ||
                        code === 404 ||
                        data === 401 ||
                        data === 404
                        ? { kind: 'none' as const }
                        : { kind: 'failed' as const };
                },
            ),
            new Promise<{ kind: 'failed' }>((resolve) => {
                timer = setTimeout(
                    () => resolve({ kind: 'failed' }),
                    PROFILE_TIMEOUT_MS + 1_000,
                );
            }),
        ]);
    } finally {
        clearTimeout(timer);
    }
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

function statusOf(data: unknown): Pick<WaProfile, 'about' | 'about_set_at'> {
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

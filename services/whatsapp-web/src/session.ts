import { rm } from 'node:fs/promises';
import { join } from 'node:path';
import {
    clearAuthState,
    useEncryptedAuthState,
    type EncryptedAuthState,
} from './auth-state.js';
import { maskJid, safeErrorMessage, type Logger } from './logger.js';
import type { FetchedMedia, MediaFetcher } from './media-fetch.js';
import {
    isIgnoredJid,
    isUserJid,
    normalizeMessage,
    phoneOfJid,
} from './normalize.js';
import type { SentStore } from './sent-store.js';
import {
    ApiError,
    type OutboundKind,
    type OutboundMessage,
    type ServiceEvent,
    type SessionError,
    type SessionSnapshot,
    type SessionState,
    type StateReason,
} from './types.js';
import { ensurePrivateDir, type Vault } from './vault.js';
import {
    CLOSE,
    WA_STATUS,
    type WaConnection,
    type WaEvent,
    type WaFactory,
    type WaOutgoing,
} from './wa.js';

/**
 * One WhatsApp Web session (one connection of ChatManager): its state
 * machine (§3), its socket, its credentials on disk and its messages.
 *
 * The session never decides on its own to link again: a revoked session
 * stays "logged_out" until an administrator asks for a new QR.
 */
type Meta = {
    /** What the administrator wants: a session meant to run is restored after a restart. */
    desired: 'running' | 'stopped';
    /** The device finished pairing at least once and its credentials are on disk. */
    linked: boolean;
    linked_at: number | null;
    phone: string | null;
    push_name: string | null;
    /** A resting state that must survive a restart. */
    terminal: 'logged_out' | 'error' | null;
    last_error: SessionError | null;
};

const emptyMeta = (): Meta => ({
    desired: 'stopped',
    linked: false,
    linked_at: null,
    phone: null,
    push_name: null,
    terminal: null,
    last_error: null,
});

const ACTIVE: readonly SessionState[] = [
    'starting',
    'qr_pending',
    'pairing',
    'connected',
    'reconnecting',
];

const OUTBOUND_KINDS: readonly OutboundKind[] = [
    'text',
    'image',
    'video',
    'audio',
    'document',
    'sticker',
];

const REASON_MESSAGES: Record<StateReason, string> = {
    qr_timeout: 'El código QR caducó sin escanearse.',
    logged_out_from_phone: 'La sesión se cerró desde el teléfono.',
    replaced: 'Otra instancia abrió esta misma sesión.',
    banned: 'WhatsApp rechazó la sesión de este número.',
    network: 'No se pudo restablecer la conexión con WhatsApp.',
    restart: 'WhatsApp pidió reiniciar la conexión.',
    bad_session: 'Las credenciales de la sesión dejaron de ser válidas.',
};

export type Cancel = () => void;

export type SessionOptions = {
    connectionId: number;
    /** sessions/{connectionId}. */
    directory: string;
    vault: Vault;
    factory: WaFactory;
    emit: (event: ServiceEvent) => void;
    sent: SentStore;
    fetchMedia: MediaFetcher;
    logger: Logger;
    limits: {
        qrMaxRefresh: number;
        reconnectMaxAttempts: number;
        sendTimeoutMs: number;
    };
    /** Milliseconds. */
    now?: () => number;
    random?: () => number;
    schedule?: (task: () => void, ms: number) => Cancel;
};

export class Session {
    private state: SessionState = 'unconfigured';
    private meta: Meta = emptyMeta();
    private connection: WaConnection | null = null;
    /** Credentials of the current (or last) socket, to wait for their writes. */
    private auth: EncryptedAuthState | null = null;
    /** Events of a socket that is no longer the current one are ignored. */
    private generation = 0;
    private events: Promise<void> = Promise.resolve();
    private qr: { value: string; expires_at: number } | null = null;
    private qrCount = 0;
    private attempts = 0;
    private reconnects = 0;
    private connectedSince: number | null = null;
    private lastEventAt: number | null = null;
    private lastInboundAt: number | null = null;
    private lastOutboundAt: number | null = null;
    private retry: Cancel | null = null;
    private readonly jids = new Map<string, string>();
    private readonly recentIds = new Set<string>();
    private readonly sending = new Map<
        string,
        Promise<{ provider_message_id: string; timestamp: number }>
    >();

    private constructor(private readonly options: SessionOptions) {}

    static async load(options: SessionOptions): Promise<Session> {
        const session = new Session(options);
        const stored = await options.vault
            .readJson<Meta>(session.metaPath, 'meta')
            .catch(() => null);

        if (stored !== null) {
            session.meta = { ...emptyMeta(), ...stored };
            session.state = session.restingState();
        }

        return session;
    }

    get connectionId(): number {
        return this.options.connectionId;
    }

    /** Sessions the administrator left running come back after a restart. */
    get shouldRestore(): boolean {
        return (
            this.meta.linked &&
            this.meta.desired === 'running' &&
            this.meta.terminal === null
        );
    }

    get isActive(): boolean {
        return ACTIVE.includes(this.state);
    }

    snapshot(): SessionSnapshot {
        return {
            connection_id: this.options.connectionId,
            state: this.state,
            phone: this.meta.phone,
            push_name: this.meta.push_name,
            qr: this.state === 'qr_pending' ? this.qr : null,
            connected_since:
                this.state === 'connected' ? this.connectedSince : null,
            last_event_at: this.lastEventAt,
            last_inbound_at: this.lastInboundAt,
            last_outbound_at: this.lastOutboundAt,
            reconnects: this.reconnects,
            last_error: this.meta.last_error,
        };
    }

    async start(mode: 'link' | 'restore'): Promise<SessionSnapshot> {
        if (this.isActive) {
            return this.snapshot();
        }

        if (mode === 'restore') {
            if (!this.meta.linked) {
                throw new ApiError(409, 'not_linked');
            }

            this.meta.desired = 'running';
            this.meta.terminal = null;
        } else {
            // Valid credentials are never thrown away by a "link" request.
            if (this.meta.linked) {
                return this.snapshot();
            }

            await this.clearCredentials();
            this.meta = { ...emptyMeta(), desired: 'running' };
            this.qrCount = 0;
        }

        this.attempts = 0;
        await this.saveMeta();
        this.transition('starting');
        await this.open();

        return this.snapshot();
    }

    /**
     * Temporary disconnection: the socket closes, the credentials stay.
     * With `notify: false` nothing is reported to Laravel (it no longer
     * knows the connection).
     */
    async stop(options: { notify?: boolean } = {}): Promise<SessionSnapshot> {
        const wasActive = this.isActive;
        const notify = options.notify ?? true;

        this.detach()?.close();
        this.connectedSince = null;

        if (this.meta.linked) {
            const changed = this.meta.desired !== 'stopped' || wasActive;

            this.meta.desired = 'stopped';

            if (changed) {
                await this.saveMeta();
            }

            if (this.meta.terminal === null && this.state !== 'disconnected') {
                this.transition('disconnected', undefined, notify);
            }
        } else if (wasActive) {
            // A linking that never finished leaves nothing behind.
            await this.clearCredentials();
            this.meta = emptyMeta();
            await this.saveMeta();
            this.transition('unconfigured', undefined, notify);
        }

        return this.snapshot();
    }

    /** Unlinks the device and deletes its credentials: a new QR is required. */
    async logout(): Promise<SessionSnapshot> {
        const connection = this.detach();

        if (connection !== null) {
            if (this.state === 'connected') {
                await withTimeout(connection.logout(), 10_000).catch(
                    (error: unknown) => {
                        this.options.logger.warn(
                            {
                                connection_id: this.connectionId,
                                err: safeErrorMessage(error),
                            },
                            'WhatsApp did not confirm the logout; the local credentials are removed anyway',
                        );
                    },
                );
            }

            connection.close();
        }

        await this.clearCredentials();
        this.meta = { ...emptyMeta(), terminal: 'logged_out' };
        this.connectedSince = null;
        await this.saveMeta();
        this.transition('logged_out');

        return this.snapshot();
    }

    /** Closes the session and deletes everything it stored. Nothing is notified. */
    async destroy(): Promise<void> {
        this.detach()?.close();
        this.state = 'unconfigured';
        this.meta = emptyMeta();
        await rm(this.options.directory, { recursive: true, force: true });
    }

    /** Resolves when no event of the session is being processed. */
    async idle(): Promise<void> {
        let current: Promise<void>;

        do {
            current = this.events;
            await current.catch(() => undefined);
        } while (current !== this.events);
    }

    /** Closes the socket for a shutdown of the service: no state change, no logout. */
    async shutdown(): Promise<void> {
        this.detach()?.close();
        await this.auth?.settled();
    }

    send(
        message: OutboundMessage,
    ): Promise<{ provider_message_id: string; timestamp: number }> {
        const inFlight = this.sending.get(message.idempotency_key);

        if (inFlight) {
            return inFlight;
        }

        const task = this.deliver(message).finally(() =>
            this.sending.delete(message.idempotency_key),
        );

        this.sending.set(message.idempotency_key, task);

        return task;
    }

    // -- state machine ----------------------------------------------------

    private restingState(): SessionState {
        if (this.meta.terminal === 'logged_out') {
            return 'logged_out';
        }

        if (this.meta.terminal === 'error') {
            return 'error';
        }

        return this.meta.linked ? 'disconnected' : 'unconfigured';
    }

    private transition(
        state: SessionState,
        reason?: StateReason,
        notify = true,
    ): void {
        this.state = state;
        this.lastEventAt = this.seconds();

        if (state !== 'qr_pending') {
            this.qr = null;
        }

        this.options.logger.info(
            { connection_id: this.connectionId, state, reason: reason ?? null },
            'Session state',
        );

        if (!notify) {
            return;
        }

        this.options.emit({
            type: 'session.state',
            data: { ...this.snapshot(), ...(reason ? { reason } : {}) },
        });
    }

    /** Takes the socket out of the session so its later events are ignored. */
    private detach(): WaConnection | null {
        const connection = this.connection;

        this.generation += 1;
        this.connection = null;
        this.retry?.();
        this.retry = null;

        return connection;
    }

    private async open(): Promise<void> {
        const generation = ++this.generation;

        try {
            // The previous socket may still be writing what this one reads.
            await this.auth?.settled();

            if (generation !== this.generation) {
                return;
            }

            const auth = await useEncryptedAuthState(
                this.authDirectory,
                this.options.vault,
            );

            this.auth = auth;

            if (generation !== this.generation) {
                return;
            }

            const connection = await this.options.factory({
                connectionId: this.connectionId,
                auth,
                onEvent: (event) => {
                    if (generation !== this.generation) {
                        return;
                    }

                    this.events = this.events
                        .then(() =>
                            generation === this.generation
                                ? this.handle(event)
                                : undefined,
                        )
                        .catch((error: unknown) => {
                            this.options.logger.error(
                                {
                                    connection_id: this.connectionId,
                                    event: event.type,
                                    err: safeErrorMessage(error),
                                },
                                'A WhatsApp event could not be processed',
                            );
                        });
                },
            });

            if (generation !== this.generation) {
                connection.close();

                return;
            }

            this.connection = connection;
        } catch (error) {
            if (generation === this.generation) {
                await this.onClose(null, safeErrorMessage(error));
            }
        }
    }

    private async handle(event: WaEvent): Promise<void> {
        switch (event.type) {
            case 'qr':
                return this.onQr(event.value, event.ttlMs);
            case 'pairing':
                if (!this.meta.linked) {
                    this.transition('pairing');
                }

                return;
            case 'open':
                return this.onOpen(event.me);
            case 'close':
                return this.onClose(event.statusCode, event.detail);
            case 'messages':
                return this.onMessages(event);
            case 'statuses':
                return this.onStatuses(event.updates);
        }
    }

    private async onQr(value: string, ttlMs: number): Promise<void> {
        // A linked device never needs a QR: registered credentials do not ask for one.
        if (this.meta.linked) {
            return;
        }

        this.qrCount += 1;

        if (this.qrCount > this.options.limits.qrMaxRefresh + 1) {
            await this.endLinking('qr_timeout');

            return;
        }

        this.attempts = 0;
        this.qr = {
            value,
            expires_at: this.seconds() + Math.max(1, Math.round(ttlMs / 1000)),
        };
        this.transition('qr_pending');
    }

    private async onOpen(me: {
        jid: string;
        name: string | null;
    }): Promise<void> {
        this.attempts = 0;
        this.qrCount = 0;
        this.meta.linked = true;
        this.meta.linked_at ??= this.seconds();
        this.meta.phone =
            phoneOfJid(me.jid.replace(/:\d+@/, '@')) ?? this.meta.phone;
        this.meta.push_name = me.name ?? this.meta.push_name;
        this.meta.desired = 'running';
        this.meta.terminal = null;
        this.meta.last_error = null;
        this.connectedSince = this.seconds();
        await this.saveMeta();
        this.transition('connected');
    }

    private async onClose(
        statusCode: number | null,
        detail: string,
    ): Promise<void> {
        this.detach();
        this.connectedSince = null;

        const linked = this.meta.linked;

        this.options.logger.warn(
            {
                connection_id: this.connectionId,
                status: statusCode,
                detail,
                state: this.state,
            },
            'WhatsApp connection closed',
        );

        // WhatsApp asks for a fresh socket (always right after pairing).
        if (statusCode === CLOSE.restartRequired) {
            if (this.state === 'connected') {
                this.transition('reconnecting', 'restart');
            }

            await this.open();

            return;
        }

        const revoked: Record<number, StateReason> = {
            [CLOSE.loggedOut]: 'logged_out_from_phone',
            [CLOSE.forbidden]: 'banned',
            [CLOSE.multideviceMismatch]: 'bad_session',
            [CLOSE.badSession]: 'bad_session',
        };
        const reason = statusCode === null ? undefined : revoked[statusCode];

        if (reason !== undefined) {
            await this.clearCredentials();
            this.meta = {
                ...emptyMeta(),
                terminal: linked ? 'logged_out' : null,
                last_error: { code: reason, message: REASON_MESSAGES[reason] },
            };
            await this.saveMeta();
            this.transition(linked ? 'logged_out' : 'unconfigured', reason);

            return;
        }

        // Another process took the session: reconnecting would start a fight.
        if (statusCode === CLOSE.connectionReplaced) {
            this.meta.desired = 'stopped';
            this.meta.terminal = 'error';
            this.meta.last_error = {
                code: 'replaced',
                message: REASON_MESSAGES.replaced,
            };
            await this.saveMeta();
            this.transition('error', 'replaced');

            return;
        }

        // Baileys ends the socket with 408 when every QR went unscanned.
        if (
            !linked &&
            this.state === 'qr_pending' &&
            statusCode === CLOSE.timedOut
        ) {
            await this.endLinking('qr_timeout');

            return;
        }

        await this.scheduleReconnect();
    }

    private async scheduleReconnect(): Promise<void> {
        this.attempts += 1;

        if (this.attempts > this.options.limits.reconnectMaxAttempts) {
            this.meta.last_error = {
                code: 'network',
                message: REASON_MESSAGES.network,
            };

            if (!this.meta.linked) {
                await this.clearCredentials();
                this.meta = {
                    ...emptyMeta(),
                    last_error: this.meta.last_error,
                };
            }

            await this.saveMeta();
            this.transition('error', 'network');

            return;
        }

        if (this.meta.linked) {
            this.reconnects += 1;
            this.transition('reconnecting', 'network');
        } else if (this.state !== 'starting' && this.state !== 'pairing') {
            this.transition('starting');
        }

        const delay = this.backoff(this.attempts);
        const generation = this.generation;
        const schedule = this.options.schedule ?? defaultSchedule;

        this.retry = schedule(() => {
            this.retry = null;

            if (generation === this.generation) {
                this.events = this.events.then(() =>
                    generation === this.generation ? this.open() : undefined,
                );
            }
        }, delay);
    }

    /** Exponential backoff (2 s, 4 s, 8 s... capped at 60 s) with ±25 % of jitter. */
    private backoff(attempt: number): number {
        const base = Math.min(60_000, 2000 * 2 ** Math.min(attempt - 1, 10));
        const random = this.options.random ?? Math.random;

        return Math.round(base * (0.75 + random() * 0.5));
    }

    private async endLinking(reason: StateReason): Promise<void> {
        this.detach()?.close();
        await this.clearCredentials();
        this.meta = {
            ...emptyMeta(),
            last_error: { code: reason, message: REASON_MESSAGES[reason] },
        };
        this.qrCount = 0;
        await this.saveMeta();
        this.transition('unconfigured', reason);
    }

    // -- messages ---------------------------------------------------------

    private async onMessages(
        event: Extract<WaEvent, { type: 'messages' }>,
    ): Promise<void> {
        // Only new messages of a linked session: never history.
        if (event.upsert !== 'notify' || !this.meta.linked) {
            return;
        }

        for (const message of event.messages) {
            const id = message.key?.id;

            if (!id || (message.key.fromMe && this.options.sent.isOwn(id))) {
                continue;
            }

            const normalized = await normalizeMessage(
                message,
                async (lid) => this.connection?.phoneForLid(lid) ?? null,
            );

            if (normalized === null) {
                continue;
            }

            // Messages older than the link belong to the history WhatsApp
            // replays to a new device.
            if (
                this.meta.linked_at !== null &&
                normalized.timestamp < this.meta.linked_at - 60
            ) {
                continue;
            }

            if (!this.remember(`${normalized.from_me ? 'o' : 'i'}:${id}`)) {
                continue;
            }

            if (normalized.from_me) {
                this.lastOutboundAt = this.seconds();
                this.options.emit({
                    type: 'message.outbound_echo',
                    data: normalized,
                });
            } else {
                this.lastInboundAt = this.seconds();
                this.options.emit({
                    type: 'message.inbound',
                    data: normalized,
                });
            }

            this.lastEventAt = this.seconds();
        }
    }

    private async onStatuses(
        updates: Extract<WaEvent, { type: 'statuses' }>['updates'],
    ): Promise<void> {
        for (const update of updates) {
            if (!update.fromMe || isIgnoredJid(update.remoteJid)) {
                continue;
            }

            const status =
                update.status === WA_STATUS.error
                    ? 'failed'
                    : update.status === WA_STATUS.serverAck
                      ? 'sent'
                      : update.status === WA_STATUS.deliveryAck
                        ? 'delivered'
                        : update.status === WA_STATUS.read ||
                            update.status === WA_STATUS.played
                          ? 'read'
                          : null;

            if (status === null) {
                continue;
            }

            this.options.emit({
                type: 'message.status',
                data: {
                    provider_message_id: update.id,
                    status,
                    timestamp: this.seconds(),
                    error:
                        status === 'failed'
                            ? {
                                  code: 'send_failed',
                                  message:
                                      'WhatsApp no pudo entregar el mensaje.',
                              }
                            : null,
                },
            });
        }
    }

    private async deliver(
        message: OutboundMessage,
    ): Promise<{ provider_message_id: string; timestamp: number }> {
        const connection = this.connection;

        if (this.state !== 'connected' || connection === null) {
            throw new ApiError(409, 'not_connected', { state: this.state });
        }

        const existing = this.options.sent.find(
            this.connectionId,
            message.idempotency_key,
        );

        if (existing?.state === 'sent') {
            return {
                provider_message_id: existing.id,
                timestamp: existing.timestamp ?? existing.at,
            };
        }

        if (!OUTBOUND_KINDS.includes(message.type)) {
            throw new ApiError(422, 'unsupported_type');
        }

        if (message.type === 'text' ? !message.text : !message.media?.url) {
            throw new ApiError(422, 'invalid_request');
        }

        const jid = await this.recipient(connection, message.to);
        const messageId =
            existing?.id ??
            this.options.sent.messageId(
                this.connectionId,
                message.idempotency_key,
            );
        let media: FetchedMedia | null = null;

        try {
            if (message.type !== 'text' && message.media) {
                media = await this.options.fetchMedia(message.media.url);
            }

            const content: WaOutgoing = {
                kind: message.type,
                text: message.text ?? null,
                filePath: media?.path ?? null,
                mime: message.media?.mime ?? media?.mime ?? null,
                fileName: message.media?.file_name ?? null,
                voice: Boolean(message.media?.voice),
            };
            const quoted = message.quoted?.provider_message_id
                ? {
                      id: message.quoted.provider_message_id,
                      remoteJid: message.quoted.remote_jid ?? jid,
                      fromMe: Boolean(message.quoted.from_me),
                      text:
                          (message.quoted as { text?: string | null }).text ??
                          null,
                  }
                : null;

            // Written before sending: if the outcome is never known, the
            // retry reuses this same message id.
            await this.options.sent.save({
                key: message.idempotency_key,
                connection_id: this.connectionId,
                id: messageId,
                state: 'pending',
                timestamp: null,
            });

            const result = await withTimeout(
                connection.send(jid, content, { messageId, quoted }),
                this.options.limits.sendTimeoutMs,
                () => new ApiError(504, 'send_timeout'),
            );

            await this.options.sent.save({
                key: message.idempotency_key,
                connection_id: this.connectionId,
                id: result.id,
                state: 'sent',
                timestamp: result.timestamp,
            });
            this.lastOutboundAt = this.seconds();
            this.lastEventAt = this.lastOutboundAt;

            return {
                provider_message_id: result.id,
                timestamp: result.timestamp,
            };
        } catch (error) {
            if (error instanceof ApiError) {
                throw error;
            }

            this.options.logger.error(
                {
                    connection_id: this.connectionId,
                    to: maskJid(jid),
                    kind: message.type,
                    err: safeErrorMessage(error),
                },
                'A message could not be sent',
            );

            if (this.state !== 'connected') {
                throw new ApiError(409, 'not_connected', { state: this.state });
            }

            throw new ApiError(500, 'internal');
        } finally {
            await media?.cleanup();
        }
    }

    /** The JID to write to: the one given, or the one WhatsApp registered for the number. */
    private async recipient(
        connection: WaConnection,
        to: string,
    ): Promise<string> {
        const target = String(to ?? '').trim();

        if (target.includes('@')) {
            if (!isUserJid(target) || isIgnoredJid(target)) {
                throw new ApiError(422, 'invalid_recipient');
            }

            return target;
        }

        const digits = target.replace(/^\+/, '');

        if (!/^\d{7,15}$/.test(digits)) {
            throw new ApiError(422, 'invalid_recipient');
        }

        const cached = this.jids.get(digits);

        if (cached) {
            return cached;
        }

        let jid: string | null;

        try {
            jid = await withTimeout(connection.resolveJid(digits), 15_000);
        } catch (error) {
            this.options.logger.error(
                {
                    connection_id: this.connectionId,
                    err: safeErrorMessage(error),
                },
                'The recipient could not be looked up',
            );

            throw this.state === 'connected'
                ? new ApiError(500, 'internal')
                : new ApiError(409, 'not_connected', { state: this.state });
        }

        if (jid === null) {
            throw new ApiError(422, 'invalid_recipient');
        }

        if (this.jids.size > 5000) {
            this.jids.clear();
        }

        this.jids.set(digits, jid);

        return jid;
    }

    /** False when the id was already seen recently (a repeated delivery of the same event). */
    private remember(id: string): boolean {
        if (this.recentIds.has(id)) {
            return false;
        }

        this.recentIds.add(id);

        if (this.recentIds.size > 2000) {
            const oldest = this.recentIds.values().next().value;

            if (oldest !== undefined) {
                this.recentIds.delete(oldest);
            }
        }

        return true;
    }

    private seconds(): number {
        return Math.floor((this.options.now?.() ?? Date.now()) / 1000);
    }

    /** Deletes the credentials once nothing is still being written to them. */
    private async clearCredentials(): Promise<void> {
        await this.auth?.settled();
        this.auth = null;
        await clearAuthState(this.authDirectory);
    }

    private get authDirectory(): string {
        return join(this.options.directory, 'auth');
    }

    private get metaPath(): string {
        return join(this.options.directory, 'meta.bin');
    }

    private async saveMeta(): Promise<void> {
        await ensurePrivateDir(this.options.directory);
        await this.options.vault.writeJson(this.metaPath, this.meta, 'meta');
    }
}

const defaultSchedule = (task: () => void, ms: number): Cancel => {
    const timer = setTimeout(task, ms);

    return () => clearTimeout(timer);
};

export function withTimeout<T>(
    promise: Promise<T>,
    ms: number,
    onTimeout: () => Error = () => new Error('timeout'),
): Promise<T> {
    let timer: NodeJS.Timeout;

    return Promise.race([
        promise,
        new Promise<never>((_, reject) => {
            timer = setTimeout(() => reject(onTimeout()), ms);
        }),
    ]).finally(() => clearTimeout(timer));
}

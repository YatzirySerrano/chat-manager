import { readdir } from 'node:fs/promises';
import { join } from 'node:path';
import type { Logger } from './logger.js';
import type { MediaFetcher } from './media-fetch.js';
import type { Outbox } from './outbox.js';
import type { SentStore } from './sent-store.js';
import { Session, type SessionOptions } from './session.js';
import {
    ApiError,
    type OutboundMessage,
    type SessionSnapshot,
} from './types.js';
import { ensurePrivateDir, KeyedQueue, type Vault } from './vault.js';
import type { WaFactory } from './wa.js';

/**
 * Owns every session of the process: one per connection id, each with its
 * own directory, and one operation at a time per session (start, stop,
 * logout and delete never interleave for the same connection).
 */
export type ManagerOptions = {
    sessionsDirectory: string;
    vault: Vault;
    factory: WaFactory;
    outbox: Outbox;
    sent: SentStore;
    fetchMedia: MediaFetcher;
    logger: Logger;
    limits: SessionOptions['limits'];
    session?: Partial<Pick<SessionOptions, 'now' | 'random' | 'schedule'>>;
};

export class SessionManager {
    private readonly sessions = new Map<number, Session>();
    private readonly queue = new KeyedQueue();
    private readonly background = new Set<Promise<unknown>>();

    constructor(private readonly options: ManagerOptions) {}

    /** Loads the sessions on disk and reopens the ones that were meant to run. */
    async restore(): Promise<number> {
        await ensurePrivateDir(this.options.sessionsDirectory);

        let restored = 0;

        for (const name of await readdir(this.options.sessionsDirectory)) {
            if (!/^\d+$/.test(name)) {
                continue;
            }

            const session = await this.load(Number(name));

            if (session.shouldRestore) {
                restored += 1;
                this.inBackground(
                    this.run(session.connectionId, () =>
                        session.start('restore'),
                    ).catch((error: unknown) => {
                        this.options.logger.error(
                            {
                                connection_id: session.connectionId,
                                err: String(error),
                            },
                            'A session could not be restored',
                        );
                    }),
                );
            }
        }

        return restored;
    }

    async snapshot(connectionId: number): Promise<SessionSnapshot> {
        const session = await this.find(connectionId);

        return session?.snapshot() ?? emptySnapshot(connectionId);
    }

    async all(): Promise<SessionSnapshot[]> {
        return [...this.sessions.values()]
            .map((session) => session.snapshot())
            .sort((a, b) => a.connection_id - b.connection_id);
    }

    start(
        connectionId: number,
        mode: 'link' | 'restore',
    ): Promise<SessionSnapshot> {
        return this.run(connectionId, async () => {
            const session =
                (await this.find(connectionId)) ??
                (mode === 'link' ? await this.load(connectionId) : null);

            if (session === null) {
                throw new ApiError(409, 'not_linked');
            }

            return session.start(mode);
        });
    }

    stop(connectionId: number): Promise<SessionSnapshot> {
        return this.run(connectionId, async () =>
            (await this.known(connectionId)).stop(),
        );
    }

    logout(connectionId: number): Promise<SessionSnapshot> {
        return this.run(connectionId, async () =>
            (await this.known(connectionId)).logout(),
        );
    }

    /** Closes the session and forgets everything about it, including its queued events. */
    destroy(connectionId: number): Promise<void> {
        return this.run(connectionId, async () => {
            const session = await this.find(connectionId);

            await session?.destroy();
            this.sessions.delete(connectionId);
            await this.options.outbox.discard(connectionId);
        });
    }

    /** Laravel answered 404 for this connection: stop it without unlinking. */
    stopBecauseGone(connectionId: number): void {
        this.inBackground(
            this.run(connectionId, async () => {
                await (await this.find(connectionId))?.stop({ notify: false });
            }).catch(() => undefined),
        );
    }

    /** Resolves when no session is processing an event or a background operation. */
    async idle(): Promise<void> {
        do {
            await Promise.all(this.background);
            await Promise.all(
                [...this.sessions.values()].map((session) => session.idle()),
            );
        } while (this.background.size > 0);
    }

    private inBackground(task: Promise<unknown>): void {
        this.background.add(task);
        void task.finally(() => this.background.delete(task));
    }

    async send(
        connectionId: number,
        message: OutboundMessage,
    ): Promise<{ provider_message_id: string; timestamp: number }> {
        return (await this.known(connectionId)).send(message);
    }

    async exists(connectionId: number): Promise<boolean> {
        return (await this.find(connectionId)) !== null;
    }

    /** Closes every socket for a shutdown; sessions keep their credentials and intent. */
    async shutdown(): Promise<void> {
        await Promise.all(
            [...this.sessions.values()].map((session) => session.shutdown()),
        );
    }

    private run<T>(connectionId: number, task: () => Promise<T>): Promise<T> {
        return this.queue.run(String(connectionId), task);
    }

    private async known(connectionId: number): Promise<Session> {
        const session = await this.find(connectionId);

        if (session === null) {
            throw new ApiError(404, 'unknown_session');
        }

        return session;
    }

    /** The session, when it exists in memory or on disk. */
    private async find(connectionId: number): Promise<Session | null> {
        const loaded = this.sessions.get(connectionId);

        if (loaded) {
            return loaded;
        }

        const names = await readdir(this.options.sessionsDirectory).catch(
            () => [] as string[],
        );

        return names.includes(String(connectionId))
            ? this.load(connectionId)
            : null;
    }

    private async load(connectionId: number): Promise<Session> {
        const existing = this.sessions.get(connectionId);

        if (existing) {
            return existing;
        }

        const session = await Session.load({
            connectionId,
            directory: join(
                this.options.sessionsDirectory,
                String(connectionId),
            ),
            vault: this.options.vault,
            factory: this.options.factory,
            emit: (event) => {
                this.options.outbox.enqueue(connectionId, event);
            },
            sent: this.options.sent,
            fetchMedia: this.options.fetchMedia,
            logger: this.options.logger,
            limits: this.options.limits,
            ...this.options.session,
        });

        this.sessions.set(connectionId, session);

        return session;
    }
}

export function emptySnapshot(connectionId: number): SessionSnapshot {
    return {
        connection_id: connectionId,
        state: 'unconfigured',
        phone: null,
        push_name: null,
        qr: null,
        connected_since: null,
        last_event_at: null,
        last_inbound_at: null,
        last_outbound_at: null,
        reconnects: 0,
        last_error: null,
    };
}

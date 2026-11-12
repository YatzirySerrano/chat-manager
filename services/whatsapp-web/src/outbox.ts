import { randomUUID } from 'node:crypto';
import { readdir, rm } from 'node:fs/promises';
import { join } from 'node:path';
import type { Logger } from './logger.js';
import type { EventEnvelope, ServiceEvent } from './types.js';
import { ensurePrivateDir, type Vault } from './vault.js';

/**
 * Durable queue of the events owed to Laravel (§5): each one is sealed on
 * disk before it is sent and deleted only after Laravel acknowledged it, so
 * a restart or an outage of Laravel loses nothing. Events of one connection
 * are delivered in order; connections do not block each other.
 */
export type DeliveryResult = 'ok' | 'drop' | 'gone' | 'retry';

export type Deliver = (envelope: EventEnvelope) => Promise<DeliveryResult>;

type Entry = {
    file: string;
    envelope: EventEnvelope;
    persisted: Promise<void>;
    inFlight: boolean;
};

export type OutboxOptions = {
    directory: string;
    vault: Vault;
    deliver: Deliver;
    /** Laravel no longer knows the connection: its session must stop. */
    onGone: (connectionId: number) => void;
    logger: Logger;
    now?: () => number;
    /** Delay before retry number `attempt` (1-based), in milliseconds. */
    retryDelay?: (attempt: number) => number;
    sleep?: (ms: number) => Promise<void>;
};

const defaultRetryDelay = (attempt: number): number => {
    const base = Math.min(30_000, 1000 * 2 ** Math.min(attempt - 1, 5));

    return Math.round(base * (0.75 + Math.random() * 0.5));
};

export class Outbox {
    private readonly queues = new Map<number, Entry[]>();
    private readonly workers = new Map<number, Promise<void>>();
    private readonly wakers = new Map<number, () => void>();
    private sequence = 0;
    private stopped = false;
    private delivered = 0;
    private dropped = 0;

    constructor(private readonly options: OutboxOptions) {}

    /** Queues again what a previous run left on disk. */
    async load(): Promise<void> {
        await ensurePrivateDir(this.options.directory);

        const files = (await readdir(this.options.directory))
            .filter((file) => file.endsWith('.evt'))
            .sort();

        for (const name of files) {
            const file = join(this.options.directory, name);

            try {
                const envelope =
                    await this.options.vault.readJson<EventEnvelope>(
                        file,
                        'outbox',
                    );

                if (envelope !== null) {
                    this.queue(envelope.connection_id).push({
                        file,
                        envelope,
                        persisted: Promise.resolve(),
                        inFlight: false,
                    });
                }
            } catch {
                this.options.logger.warn(
                    { file: name },
                    'Unreadable queued event removed',
                );
                await rm(file, { force: true });
            }
        }

        for (const connectionId of this.queues.keys()) {
            this.work(connectionId);
        }
    }

    enqueue(connectionId: number, event: ServiceEvent): EventEnvelope {
        const now = this.options.now?.() ?? Date.now();
        const envelope = {
            ...event,
            event_id: randomUUID(),
            connection_id: connectionId,
            occurred_at: Math.floor(now / 1000),
        } as EventEnvelope;

        const queue = this.queue(connectionId);

        // Only the latest state of a session matters (and an old QR must not
        // be delivered late): replace the states still waiting.
        if (event.type === 'session.state') {
            for (let index = queue.length - 1; index >= 0; index--) {
                const entry = queue[index] as Entry;

                if (
                    entry.envelope.type === 'session.state' &&
                    !entry.inFlight
                ) {
                    queue.splice(index, 1);
                    void entry.persisted
                        .then(() => rm(entry.file, { force: true }))
                        .catch(() => undefined);
                }
            }
        }

        this.sequence += 1;

        const name = `${String(now).padStart(15, '0')}-${String(this.sequence).padStart(8, '0')}-${connectionId}-${envelope.event_id}.evt`;
        const file = join(this.options.directory, name);
        const persisted = this.options.vault
            .writeJson(file, envelope, 'outbox')
            .catch((error: unknown) => {
                this.options.logger.error(
                    { err: String(error) },
                    'An event could not be written to the outbox',
                );
            });

        queue.push({ file, envelope, persisted, inFlight: false });
        this.work(connectionId);

        return envelope;
    }

    /** Forgets every queued event of a connection. */
    async discard(connectionId: number): Promise<void> {
        const queue = this.queues.get(connectionId) ?? [];

        this.queues.delete(connectionId);

        for (const entry of queue) {
            await entry.persisted;
            await rm(entry.file, { force: true });
        }
    }

    pending(connectionId?: number): number {
        if (connectionId !== undefined) {
            return this.queues.get(connectionId)?.length ?? 0;
        }

        let total = 0;

        for (const queue of this.queues.values()) {
            total += queue.length;
        }

        return total;
    }

    stats(): { pending: number; delivered: number; dropped: number } {
        return {
            pending: this.pending(),
            delivered: this.delivered,
            dropped: this.dropped,
        };
    }

    /** Resolves when nothing is being delivered (events owed stay on disk). */
    async stop(): Promise<void> {
        this.stopped = true;

        for (const wake of this.wakers.values()) {
            wake();
        }

        await Promise.all(this.workers.values());
        await this.persisted();
    }

    /** Resolves once every event queued so far is safely on disk. */
    async persisted(): Promise<void> {
        const writes: Promise<void>[] = [];

        for (const queue of this.queues.values()) {
            for (const entry of queue) {
                writes.push(entry.persisted);
            }
        }

        await Promise.all(writes);
    }

    /** Resolves once every queue is empty (tests). */
    async idle(): Promise<void> {
        while (this.pending() > 0 && !this.stopped) {
            await Promise.all(this.workers.values());
            await new Promise((resolve) => setImmediate(resolve));
        }
    }

    private queue(connectionId: number): Entry[] {
        let queue = this.queues.get(connectionId);

        if (!queue) {
            queue = [];
            this.queues.set(connectionId, queue);
        }

        return queue;
    }

    private work(connectionId: number): void {
        if (this.workers.has(connectionId) || this.stopped) {
            return;
        }

        const worker = this.drain(connectionId).finally(() => {
            this.workers.delete(connectionId);

            if (
                (this.queues.get(connectionId)?.length ?? 0) > 0 &&
                !this.stopped
            ) {
                this.work(connectionId);
            }
        });

        this.workers.set(connectionId, worker);
    }

    private async drain(connectionId: number): Promise<void> {
        let attempt = 0;

        while (!this.stopped) {
            const entry = this.queues.get(connectionId)?.[0];

            if (!entry) {
                return;
            }

            entry.inFlight = true;
            await entry.persisted;

            let result: DeliveryResult;

            try {
                result = await this.options.deliver(entry.envelope);
            } catch {
                result = 'retry';
            }

            entry.inFlight = false;

            if (result === 'retry') {
                attempt += 1;

                if (attempt === 1 || attempt % 10 === 0) {
                    this.options.logger.warn(
                        {
                            connection_id: connectionId,
                            attempt,
                            pending: this.pending(connectionId),
                        },
                        'Laravel did not accept an event; it will be retried',
                    );
                }

                await this.wait(
                    connectionId,
                    (this.options.retryDelay ?? defaultRetryDelay)(attempt),
                );

                continue;
            }

            attempt = 0;

            if (result === 'gone') {
                this.options.logger.warn(
                    { connection_id: connectionId },
                    'Laravel does not know this connection: its events are discarded',
                );
                await this.discard(connectionId);
                this.options.onGone(connectionId);

                return;
            }

            const queue = this.queues.get(connectionId);

            if (queue?.[0] === entry) {
                queue.shift();
            }

            await rm(entry.file, { force: true });

            if (result === 'ok') {
                this.delivered += 1;
            } else {
                this.dropped += 1;
                this.options.logger.error(
                    { connection_id: connectionId, type: entry.envelope.type },
                    'Laravel rejected an event as invalid; it was dropped',
                );
            }
        }
    }

    private wait(connectionId: number, ms: number): Promise<void> {
        const sleep =
            this.options.sleep ??
            ((delay: number) =>
                new Promise<void>((resolve) =>
                    setTimeout(resolve, delay).unref(),
                ));

        return Promise.race([
            sleep(ms),
            new Promise<void>((resolve) => {
                this.wakers.set(connectionId, resolve);
            }),
        ]).finally(() => {
            this.wakers.delete(connectionId);
        });
    }
}

import { createHash, createHmac } from 'node:crypto';
import { readdir, rm } from 'node:fs/promises';
import { join } from 'node:path';
import { ensurePrivateDir, type Vault } from './vault.js';

/**
 * Remembers, for 48 hours and across restarts, the provider message id of
 * every idempotency key (§4): a retry of the same key returns the earlier
 * result instead of sending again.
 *
 * The message id is derived from the key, so even a send whose outcome was
 * never confirmed (timeout, crash) is repeated with the same id and
 * WhatsApp treats it as the same message.
 */
export type SentRecord = {
    key: string;
    connection_id: number;
    id: string;
    state: 'pending' | 'sent';
    timestamp: number | null;
    at: number;
};

export const SENT_TTL_SECONDS = 48 * 3600;

export class SentStore {
    private readonly records = new Map<string, SentRecord>();
    private readonly ids = new Set<string>();

    constructor(
        private readonly directory: string,
        private readonly vault: Vault,
        private readonly secret: Buffer,
        private readonly now: () => number = () =>
            Math.floor(Date.now() / 1000),
    ) {}

    /** Loads the records of the last 48 hours and deletes the older ones. */
    async load(): Promise<void> {
        await ensurePrivateDir(this.directory);

        for (const file of await readdir(this.directory)) {
            if (!file.endsWith('.bin')) {
                continue;
            }

            const path = join(this.directory, file);

            try {
                const record = await this.vault.readJson<SentRecord>(
                    path,
                    'sent',
                );

                if (
                    record === null ||
                    this.now() - record.at > SENT_TTL_SECONDS
                ) {
                    await rm(path, { force: true });
                } else {
                    this.remember(record);
                }
            } catch {
                await rm(path, { force: true });
            }
        }
    }

    /** Stable WhatsApp message id of an idempotency key (same shape Baileys generates). */
    messageId(connectionId: number, key: string): string {
        return (
            '3EB0' +
            createHmac('sha256', this.secret)
                .update(`${connectionId}:${key}`)
                .digest('hex')
                .slice(0, 28)
                .toUpperCase()
        );
    }

    find(connectionId: number, key: string): SentRecord | null {
        return this.records.get(this.index(connectionId, key)) ?? null;
    }

    /** Whether the service itself sent a message with this id. */
    isOwn(messageId: string): boolean {
        return this.ids.has(messageId);
    }

    /** Stores the record, stamped with the time of this write (what the 48 hours count from). */
    async save(entry: Omit<SentRecord, 'at'>): Promise<void> {
        const record: SentRecord = { ...entry, at: this.now() };

        this.remember(record);
        await this.vault.writeJson(
            this.path(record.connection_id, record.key),
            record,
            'sent',
        );
    }

    async prune(): Promise<void> {
        for (const [index, record] of this.records) {
            if (this.now() - record.at > SENT_TTL_SECONDS) {
                this.records.delete(index);
                this.ids.delete(record.id);
                await rm(this.path(record.connection_id, record.key), {
                    force: true,
                });
            }
        }
    }

    private remember(record: SentRecord): void {
        this.records.set(this.index(record.connection_id, record.key), record);
        this.ids.add(record.id);
    }

    private index(connectionId: number, key: string): string {
        return `${connectionId}:${key}`;
    }

    private path(connectionId: number, key: string): string {
        return join(
            this.directory,
            createHash('sha256')
                .update(this.index(connectionId, key))
                .digest('hex') + '.bin',
        );
    }
}

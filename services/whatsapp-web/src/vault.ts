import { createCipheriv, createDecipheriv, randomBytes } from 'node:crypto';
import {
    chmod,
    mkdir,
    readFile,
    rename,
    rm,
    writeFile,
} from 'node:fs/promises';
import { dirname } from 'node:path';

const MAGIC = Buffer.from('CMW1');
const IV_BYTES = 12;
const TAG_BYTES = 16;

export class VaultError extends Error {}

/**
 * Encryption at rest (AES-256-GCM) of everything the service writes to
 * disk: session credentials, queued events and the sent-message store.
 * Each file is bound to a label (its logical name), so a sealed file moved
 * over another one does not open.
 */
export class Vault {
    constructor(private readonly key: Buffer) {
        if (key.length !== 32) {
            throw new VaultError('The vault key must be 32 bytes.');
        }
    }

    seal(plain: Buffer, label: string): Buffer {
        const iv = randomBytes(IV_BYTES);
        const cipher = createCipheriv('aes-256-gcm', this.key, iv);
        cipher.setAAD(Buffer.from(label, 'utf8'));
        const body = Buffer.concat([cipher.update(plain), cipher.final()]);

        return Buffer.concat([MAGIC, iv, cipher.getAuthTag(), body]);
    }

    open(sealed: Buffer, label: string): Buffer {
        if (
            sealed.length < MAGIC.length + IV_BYTES + TAG_BYTES ||
            !sealed.subarray(0, MAGIC.length).equals(MAGIC)
        ) {
            throw new VaultError('Not a sealed file.');
        }

        const iv = sealed.subarray(MAGIC.length, MAGIC.length + IV_BYTES);
        const tag = sealed.subarray(
            MAGIC.length + IV_BYTES,
            MAGIC.length + IV_BYTES + TAG_BYTES,
        );
        const body = sealed.subarray(MAGIC.length + IV_BYTES + TAG_BYTES);
        const decipher = createDecipheriv('aes-256-gcm', this.key, iv);
        decipher.setAAD(Buffer.from(label, 'utf8'));
        decipher.setAuthTag(tag);

        try {
            return Buffer.concat([decipher.update(body), decipher.final()]);
        } catch {
            throw new VaultError(
                'The sealed file could not be opened (wrong key or altered file).',
            );
        }
    }

    /** Atomic write: a reader sees the old file or the new one, never half. */
    async write(path: string, plain: Buffer, label: string): Promise<void> {
        await ensurePrivateDir(dirname(path));

        const temporary = `${path}.${randomBytes(6).toString('hex')}.tmp`;

        try {
            await writeFile(temporary, this.seal(plain, label), {
                mode: 0o600,
            });
            await rename(temporary, path);
        } catch (error) {
            await rm(temporary, { force: true });

            throw error;
        }
    }

    /** Null when the file does not exist. */
    async read(path: string, label: string): Promise<Buffer | null> {
        let sealed: Buffer;

        try {
            sealed = await readFile(path);
        } catch (error) {
            if ((error as NodeJS.ErrnoException).code === 'ENOENT') {
                return null;
            }

            throw error;
        }

        return this.open(sealed, label);
    }

    async writeJson(
        path: string,
        value: unknown,
        label: string,
    ): Promise<void> {
        await this.write(
            path,
            Buffer.from(JSON.stringify(value), 'utf8'),
            label,
        );
    }

    async readJson<T>(path: string, label: string): Promise<T | null> {
        const plain = await this.read(path, label);

        return plain === null
            ? null
            : (JSON.parse(plain.toString('utf8')) as T);
    }
}

/** Directory only its owner can enter (0700; a no-op on Windows). */
export async function ensurePrivateDir(path: string): Promise<void> {
    await mkdir(path, { recursive: true, mode: 0o700 });

    try {
        await chmod(path, 0o700);
    } catch {
        // Filesystems without POSIX permissions.
    }
}

/**
 * Runs the tasks of one key one after another (file writes, the operations
 * of one session), while different keys proceed in parallel.
 */
export class KeyedQueue {
    private readonly tails = new Map<string, Promise<unknown>>();

    run<T>(key: string, task: () => Promise<T>): Promise<T> {
        const previous = this.tails.get(key) ?? Promise.resolve();
        const next = previous.then(task, task);
        const settled = next.catch(() => undefined);

        this.tails.set(key, settled);
        void settled.then(() => {
            if (this.tails.get(key) === settled) {
                this.tails.delete(key);
            }
        });

        return next;
    }
}

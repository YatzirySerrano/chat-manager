import { readdir, rename, rm } from 'node:fs/promises';
import { join } from 'node:path';
import {
    BufferJSON,
    initAuthCreds,
    proto,
    type AuthenticationCreds,
    type AuthenticationState,
    type SignalDataTypeMap,
} from 'baileys';
import { ensurePrivateDir, KeyedQueue, type Vault } from './vault.js';

/**
 * Baileys authentication state stored encrypted at rest: one sealed file per
 * key (credentials, pre-keys, sessions, sender keys, LID mappings...),
 * written atomically. It replaces useMultiFileAuthState, which writes the
 * same data as plain JSON.
 */
export type EncryptedAuthState = {
    state: AuthenticationState;
    saveCreds: () => Promise<void>;
    /** Whether the device finished pairing (the credentials carry an account). */
    isRegistered: () => boolean;
    /**
     * Resolves once every write requested so far is on disk. The socket is
     * replaced right after pairing: the next one must read the credentials
     * the previous one was still saving.
     */
    settled: () => Promise<void>;
};

const CREDS_FILE = 'creds';
/**
 * Second copy of the credentials, written right after the main one: if the
 * main file can no longer be opened (disk error, a copy restored by hand),
 * the session comes back from this one instead of asking for a new QR.
 */
const CREDS_BACKUP_FILE = 'creds-backup';

/** Reports a stored file that could not be opened (never its content). */
export type CorruptFileReporter = (name: string, error: unknown) => void;

/** File name of a key: only characters every filesystem accepts. */
export function keyFileName(name: string): string {
    return (
        name.replace(
            /[^A-Za-z0-9._@-]/g,
            (char) => `~${char.charCodeAt(0).toString(16).padStart(2, '0')}`,
        ) + '.bin'
    );
}

export async function useEncryptedAuthState(
    directory: string,
    vault: Vault,
    onCorrupt: CorruptFileReporter = () => undefined,
): Promise<EncryptedAuthState> {
    await ensurePrivateDir(directory);
    await removeStrayTemporaryFiles(directory);

    const queue = new KeyedQueue();
    const pending = new Set<Promise<unknown>>();
    const track = <T>(task: Promise<T>): Promise<T> => {
        pending.add(task);
        void task.then(
            () => pending.delete(task),
            () => pending.delete(task),
        );

        return task;
    };
    const pathOf = (name: string): string => join(directory, keyFileName(name));

    const write = (name: string, value: unknown): Promise<void> =>
        track(
            queue.run(name, () =>
                vault.write(
                    pathOf(name),
                    Buffer.from(
                        JSON.stringify(value, BufferJSON.replacer),
                        'utf8',
                    ),
                    name,
                ),
            ),
        );

    const read = (name: string): Promise<unknown> =>
        queue.run(name, async () => {
            const plain = await vault.read(pathOf(name), name);

            return plain === null
                ? null
                : JSON.parse(plain.toString('utf8'), BufferJSON.reviver);
        });

    /**
     * A key file that cannot be opened (altered, truncated by a disk error)
     * is treated as missing and removed: Baileys recreates what it needs
     * (a Signal session is renegotiated with a retry, a pre-key is uploaded
     * again, an app-state key is asked to the phone). One bad file must
     * never stop the socket nor unlink the device.
     */
    const readKey = async (name: string): Promise<unknown> => {
        try {
            return await read(name);
        } catch (error) {
            onCorrupt(name, error);
            // Kept aside (never read again) instead of deleted, for diagnosis.
            await track(
                queue.run(name, () =>
                    rename(pathOf(name), `${pathOf(name)}.corrupt`),
                ),
            ).catch(() => undefined);

            return null;
        }
    };

    const remove = (name: string): Promise<void> =>
        track(queue.run(name, () => rm(pathOf(name), { force: true })));

    /**
     * The main copy, else the backup. When copies exist but none opens
     * (most likely a different WAWEB_SESSION_KEY), it throws: new
     * credentials must never silently replace the stored ones.
     */
    const readCreds = async (): Promise<AuthenticationCreds | null> => {
        let failure: unknown = null;

        for (const name of [CREDS_FILE, CREDS_BACKUP_FILE]) {
            try {
                const value = (await read(name)) as AuthenticationCreds | null;

                if (value !== null) {
                    return value;
                }
            } catch (error) {
                onCorrupt(name, error);
                failure ??= error;
            }
        }

        if (failure !== null) {
            throw failure;
        }

        return null;
    };

    const creds = (await readCreds()) ?? initAuthCreds();

    /** Both copies, one after the other: there is always one complete. */
    const saveCreds = (): Promise<void> =>
        track(
            queue.run(CREDS_FILE, async () => {
                const plain = Buffer.from(
                    JSON.stringify(creds, BufferJSON.replacer),
                    'utf8',
                );

                await vault.write(pathOf(CREDS_FILE), plain, CREDS_FILE);
                await vault.write(
                    pathOf(CREDS_BACKUP_FILE),
                    plain,
                    CREDS_BACKUP_FILE,
                );
            }),
        );

    return {
        state: {
            creds,
            keys: {
                get: async <T extends keyof SignalDataTypeMap>(
                    type: T,
                    ids: string[],
                ) => {
                    const found: { [id: string]: SignalDataTypeMap[T] } = {};

                    await Promise.all(
                        ids.map(async (id) => {
                            let value = await readKey(`${type}-${id}`);

                            if (type === 'app-state-sync-key' && value) {
                                value =
                                    proto.Message.AppStateSyncKeyData.fromObject(
                                        value as Record<string, unknown>,
                                    );
                            }

                            if (value !== null && value !== undefined) {
                                found[id] = value as SignalDataTypeMap[T];
                            }
                        }),
                    );

                    return found;
                },
                set: async (data) => {
                    const tasks: Promise<void>[] = [];

                    for (const [type, entries] of Object.entries(data)) {
                        for (const [id, value] of Object.entries(
                            entries ?? {},
                        )) {
                            const name = `${type}-${id}`;

                            tasks.push(
                                value ? write(name, value) : remove(name),
                            );
                        }
                    }

                    await Promise.all(tasks);
                },
            },
        },
        saveCreds,
        isRegistered: () => Boolean(creds.me?.id && creds.account),
        settled: async () => {
            while (pending.size > 0) {
                await Promise.allSettled(pending);
            }
        },
    };
}

/** Removes every credential of a session (logout, revoked session, new link). */
export async function clearAuthState(directory: string): Promise<void> {
    await rm(directory, { recursive: true, force: true });
}

/** Whether the directory holds any stored credential file. */
export async function hasStoredCredentials(
    directory: string,
): Promise<boolean> {
    try {
        const files = await readdir(directory);

        return (
            files.includes(keyFileName(CREDS_FILE)) ||
            files.includes(keyFileName(CREDS_BACKUP_FILE))
        );
    } catch {
        return false;
    }
}

/** Temporary files of writes interrupted by a crash (never complete). */
async function removeStrayTemporaryFiles(directory: string): Promise<void> {
    const files = await readdir(directory).catch(() => [] as string[]);

    await Promise.all(
        files
            .filter((file) => file.endsWith('.tmp'))
            .map((file) => rm(join(directory, file), { force: true })),
    );
}

import { readdir, rm } from 'node:fs/promises';
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
): Promise<EncryptedAuthState> {
    await ensurePrivateDir(directory);

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

    const remove = (name: string): Promise<void> =>
        track(queue.run(name, () => rm(pathOf(name), { force: true })));

    const creds =
        ((await read(CREDS_FILE)) as AuthenticationCreds | null) ??
        initAuthCreds();

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
                            let value = await read(`${type}-${id}`);

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
        saveCreds: () => write(CREDS_FILE, creds),
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
        return (await readdir(directory)).includes(keyFileName(CREDS_FILE));
    } catch {
        return false;
    }
}

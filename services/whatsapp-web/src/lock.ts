import { closeSync, openSync, readFileSync, rmSync, writeSync } from 'node:fs';

/**
 * Single-instance lock (§7): two processes over the same sessions would
 * make WhatsApp replace one connection with the other and could corrupt
 * the credentials. The lock file holds the PID of its owner; a file left
 * by a process that no longer exists is taken over.
 */
export class LockError extends Error {}

function isAlive(pid: number): boolean {
    try {
        process.kill(pid, 0);

        return true;
    } catch (error) {
        // EPERM: the process exists but belongs to another user.
        return (error as NodeJS.ErrnoException).code === 'EPERM';
    }
}

export function acquireLock(
    path: string,
    pid: number = process.pid,
): () => void {
    for (let attempt = 0; attempt < 2; attempt++) {
        try {
            const descriptor = openSync(path, 'wx', 0o600);

            writeSync(descriptor, String(pid));
            closeSync(descriptor);

            return () => {
                try {
                    if (readFileSync(path, 'utf8').trim() === String(pid)) {
                        rmSync(path, { force: true });
                    }
                } catch {
                    // Already gone.
                }
            };
        } catch (error) {
            if ((error as NodeJS.ErrnoException).code !== 'EEXIST') {
                throw error;
            }

            const owner = Number(readFileSync(path, 'utf8').trim());

            if (
                Number.isInteger(owner) &&
                owner > 0 &&
                owner !== pid &&
                isAlive(owner)
            ) {
                throw new LockError(
                    `Another instance of the service is running (PID ${owner}).`,
                );
            }

            // Stale lock: remove it and try once more.
            rmSync(path, { force: true });
        }
    }

    throw new LockError('The instance lock could not be acquired.');
}

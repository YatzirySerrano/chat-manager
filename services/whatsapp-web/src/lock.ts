import { closeSync, openSync, readFileSync, rmSync, writeSync } from 'node:fs';

/**
 * Single-instance lock (§7): two processes over the same sessions would
 * make WhatsApp replace one connection with the other (440) and could
 * corrupt the credentials. The lock file holds the PID of its owner and
 * the boot it started in; a file left by a process that no longer exists
 * is taken over.
 *
 * A crash, a kill -9 or a power cut leaves the file behind. After a reboot
 * its PID may belong to an unrelated process, which must not keep the
 * service from starting (Supervisor would give up after a few attempts
 * and the sessions would stay down): a lock of another boot, or whose PID
 * is not a Node.js process, is stale.
 */
export class LockError extends Error {}

export type LockProbe = {
    /** Whether a process with this PID exists. */
    isAlive: (pid: number) => boolean;
    /** Identifier of the current boot (Linux), or null where unknown. */
    bootId: () => string | null;
    /** Command line of a process (Linux), or null where unknown. */
    commandLine: (pid: number) => string | null;
};

type LockOwner = { pid: number; boot: string | null };

function isAlive(pid: number): boolean {
    try {
        process.kill(pid, 0);

        return true;
    } catch (error) {
        // EPERM: the process exists but belongs to another user.
        return (error as NodeJS.ErrnoException).code === 'EPERM';
    }
}

function readOrNull(path: string): string | null {
    try {
        return readFileSync(path, 'utf8');
    } catch {
        return null;
    }
}

export const systemProbe: LockProbe = {
    isAlive,
    bootId: () =>
        readOrNull('/proc/sys/kernel/random/boot_id')?.trim() || null,
    commandLine: (pid) =>
        readOrNull(`/proc/${pid}/cmdline`)?.replace(/\0/g, ' ').trim() ??
        null,
};

/** "1234" (older versions) or {"pid":1234,"boot":"..."}. */
function parseOwner(raw: string): LockOwner | null {
    try {
        const parsed = JSON.parse(raw.trim()) as unknown;

        if (typeof parsed === 'number') {
            return Number.isInteger(parsed) && parsed > 0
                ? { pid: parsed, boot: null }
                : null;
        }

        if (
            typeof parsed === 'object' &&
            parsed !== null &&
            Number.isInteger((parsed as LockOwner).pid)
        ) {
            const owner = parsed as LockOwner;

            return {
                pid: owner.pid,
                boot: typeof owner.boot === 'string' ? owner.boot : null,
            };
        }
    } catch {
        // Not JSON: an empty or half-written file.
    }

    return null;
}

/** Whether the owner written in the lock is a live instance of the service. */
export function ownerIsRunning(
    owner: LockOwner | null,
    pid: number,
    probe: LockProbe,
): boolean {
    if (owner === null || owner.pid <= 0 || owner.pid === pid) {
        return false;
    }

    const boot = probe.bootId();

    if (owner.boot !== null && boot !== null && owner.boot !== boot) {
        return false;
    }

    if (!probe.isAlive(owner.pid)) {
        return false;
    }

    const command = probe.commandLine(owner.pid);

    // Where the command line can be read, a PID reused by anything other
    // than Node.js is not the service.
    return command === null || command === '' || /node/i.test(command);
}

export function acquireLock(
    path: string,
    pid: number = process.pid,
    probe: LockProbe = systemProbe,
): () => void {
    const content = JSON.stringify({ pid, boot: probe.bootId() });

    for (let attempt = 0; attempt < 2; attempt++) {
        try {
            const descriptor = openSync(path, 'wx', 0o600);

            writeSync(descriptor, content);
            closeSync(descriptor);

            return () => {
                try {
                    if (parseOwner(readFileSync(path, 'utf8'))?.pid === pid) {
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

            const owner = parseOwner(readOrNull(path) ?? '');

            if (ownerIsRunning(owner, pid, probe)) {
                throw new LockError(
                    `Another instance of the service is running (PID ${owner?.pid}).`,
                );
            }

            // Stale lock: remove it and try once more.
            rmSync(path, { force: true });
        }
    }

    throw new LockError('The instance lock could not be acquired.');
}

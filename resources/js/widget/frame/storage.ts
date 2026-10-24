/**
 * Where the frame keeps the visitor's session token: the iframe's own
 * localStorage (partitioned by host site in modern browsers, out of reach
 * of the host's JavaScript) under 'cm:v1:{publicKey}'. Every access is
 * guarded: when storage is blocked (private mode, disabled site data) the
 * token lives in memory for the life of the frame.
 */
export type TokenStorage = {
    get(): string | null;
    set(token: string): void;
    clear(): void;
};

export function createTokenStorage(publicKey: string): TokenStorage {
    const key = `cm:v1:${publicKey}`;
    let memory: string | null = null;

    return {
        get(): string | null {
            try {
                const stored = window.localStorage.getItem(key);

                if (stored !== null) {
                    return stored;
                }
            } catch {
                // Storage blocked: fall back to memory.
            }

            return memory;
        },
        set(token: string): void {
            memory = token;

            try {
                window.localStorage.setItem(key, token);
            } catch {
                // Storage blocked or full: memory only.
            }
        },
        clear(): void {
            memory = null;

            try {
                window.localStorage.removeItem(key);
            } catch {
                // Nothing stored.
            }
        },
    };
}

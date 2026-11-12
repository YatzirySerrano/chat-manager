import {
    createHash,
    createHmac,
    randomUUID,
    timingSafeEqual,
} from 'node:crypto';

/**
 * Internal authentication between Laravel and this service
 * (docs/whatsapp-web-qr-contrato.md §2): HMAC-SHA256 over
 * "{timestamp}.{nonce}.{METHOD}.{path}.{sha256_hex(body)}".
 */
export const MAX_SKEW_SECONDS = 60;
export const NONCE_TTL_SECONDS = 300;

export function sha256Hex(body: Buffer | string): string {
    return createHash('sha256').update(body).digest('hex');
}

export function signature(
    secret: string,
    timestamp: string,
    nonce: string,
    method: string,
    path: string,
    body: Buffer | string,
): string {
    return createHmac('sha256', secret)
        .update(
            `${timestamp}.${nonce}.${method.toUpperCase()}.${path}.${sha256Hex(body)}`,
        )
        .digest('hex');
}

/** Headers of an outgoing signed request. */
export function signedHeaders(
    secret: string,
    method: string,
    path: string,
    body: Buffer | string,
    now: number = Date.now(),
): Record<string, string> {
    const timestamp = String(Math.floor(now / 1000));
    const nonce = randomUUID();

    return {
        'X-CM-Timestamp': timestamp,
        'X-CM-Nonce': nonce,
        'X-CM-Signature': signature(
            secret,
            timestamp,
            nonce,
            method,
            path,
            body,
        ),
    };
}

/**
 * Remembers the nonces seen in the last five minutes so a captured request
 * cannot be replayed inside the timestamp window.
 */
export class NonceCache {
    private readonly seen = new Map<string, number>();

    constructor(
        private readonly ttlSeconds: number = NONCE_TTL_SECONDS,
        private readonly limit: number = 50_000,
    ) {}

    /** True when the nonce is new (and is now remembered). */
    claim(nonce: string, nowSeconds: number): boolean {
        this.prune(nowSeconds);

        if (this.seen.has(nonce)) {
            return false;
        }

        this.seen.set(nonce, nowSeconds + this.ttlSeconds);

        return true;
    }

    get size(): number {
        return this.seen.size;
    }

    // Insertion order is expiry order (one TTL for every nonce).
    private prune(nowSeconds: number): void {
        for (const [nonce, expires] of this.seen) {
            if (expires > nowSeconds && this.seen.size <= this.limit) {
                break;
            }

            this.seen.delete(nonce);
        }
    }
}

export type VerifyInput = {
    secret: string;
    method: string;
    /** Path with its query string, exactly as it was requested. */
    path: string;
    body: Buffer;
    timestamp: string | undefined;
    nonce: string | undefined;
    signature: string | undefined;
    nonces: NonceCache;
    now?: number;
};

export type VerifyFailure =
    | 'disabled'
    | 'missing'
    | 'stale'
    | 'mismatch'
    | 'replayed';

/** Null when the request is authentic; otherwise why it was refused. */
export function verify(input: VerifyInput): VerifyFailure | null {
    if (input.secret === '') {
        return 'disabled';
    }

    const { timestamp, nonce } = input;
    const given = input.signature;

    if (!timestamp || !nonce || !given) {
        return 'missing';
    }

    const nowSeconds = Math.floor((input.now ?? Date.now()) / 1000);

    if (
        !/^\d{1,12}$/.test(timestamp) ||
        Math.abs(nowSeconds - Number(timestamp)) > MAX_SKEW_SECONDS
    ) {
        return 'stale';
    }

    if (!/^[0-9a-zA-Z-]{16,64}$/.test(nonce)) {
        return 'missing';
    }

    const expected = Buffer.from(
        signature(
            input.secret,
            timestamp,
            nonce,
            input.method,
            input.path,
            input.body,
        ),
        'hex',
    );
    const received = /^[0-9a-f]{64}$/i.test(given)
        ? Buffer.from(given, 'hex')
        : Buffer.alloc(0);

    if (
        received.length !== expected.length ||
        !timingSafeEqual(received, expected)
    ) {
        return 'mismatch';
    }

    // Only an authentic request may consume a nonce.
    if (!input.nonces.claim(nonce, nowSeconds)) {
        return 'replayed';
    }

    return null;
}

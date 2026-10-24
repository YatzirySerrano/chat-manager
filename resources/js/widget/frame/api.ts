import type {
    FrameConfig,
    MessagePage,
    SignedIdentity,
    VisitorMessage,
    VisitorSession,
} from '../types';
import type { TokenStorage } from './storage';

/**
 * Client of the visitor API (/api/v1/widget), same origin as the frame:
 * no cookies or CORS, the session travels as "Authorization: Bearer wvs_…".
 * A 401 means the session expired or was revoked: the token is dropped and
 * the caller starts a new one. Sending is idempotent (client_message_id),
 * so network failures and 5xx answers are retried with the same id. Polling
 * uses ETag / If-None-Match by hand.
 */
export class ApiError extends Error {
    constructor(
        public status: number,
        public code: string | null,
        message: string,
        public retryAfter: number | null = null,
    ) {
        super(message);
    }
}

type StartBody = {
    ticket: string;
    profile?: { name?: string; email?: string };
    identity?: SignedIdentity;
    landing_url?: string;
};

const RETRY_DELAYS_MS = [1000, 3000, 6000];

function wait(ms: number): Promise<void> {
    return new Promise((resolve) => setTimeout(resolve, ms));
}

export function newClientMessageId(): string {
    const cryptoApi: Crypto = window.crypto;

    if (typeof cryptoApi.randomUUID === 'function') {
        return cryptoApi.randomUUID();
    }

    // randomUUID needs a secure context (https or localhost).
    const bytes = cryptoApi.getRandomValues(new Uint8Array(16));
    bytes[6] = (bytes[6] & 0x0f) | 0x40;
    bytes[8] = (bytes[8] & 0x3f) | 0x80;
    const hex = Array.from(bytes, (b: number) =>
        b.toString(16).padStart(2, '0'),
    );

    return `${hex.slice(0, 4).join('')}-${hex.slice(4, 6).join('')}-${hex.slice(6, 8).join('')}-${hex.slice(8, 10).join('')}-${hex.slice(10).join('')}`;
}

export class VisitorApi {
    private etag: string | null = null;

    constructor(
        private config: FrameConfig,
        private storage: TokenStorage,
        private onUnauthorized: () => void,
    ) {}

    get hasToken(): boolean {
        return this.storage.get() !== null;
    }

    get token(): string | null {
        return this.storage.get();
    }

    private async request<T>(
        method: string,
        url: string,
        body?: unknown,
        headers: Record<string, string> = {},
    ): Promise<{ status: number; data: T | null; response: Response }> {
        const token = this.storage.get();
        const response = await fetch(url, {
            method,
            credentials: 'omit',
            cache: 'no-store',
            headers: {
                Accept: 'application/json',
                ...(body !== undefined
                    ? { 'Content-Type': 'application/json' }
                    : {}),
                ...(token ? { Authorization: `Bearer ${token}` } : {}),
                ...headers,
            },
            body: body !== undefined ? JSON.stringify(body) : undefined,
        });

        if (response.status === 401 && token) {
            this.storage.clear();
            this.etag = null;
            this.onUnauthorized();
        }

        if (response.status === 204 || response.status === 304) {
            return { status: response.status, data: null, response };
        }

        const data = (await response.json().catch(() => null)) as
            | (T & { message?: string; code?: string })
            | null;

        if (!response.ok) {
            const retryAfter = Number(response.headers.get('Retry-After'));

            throw new ApiError(
                response.status,
                data?.code ?? null,
                data?.message ?? 'No se pudo completar la solicitud.',
                Number.isFinite(retryAfter) && retryAfter > 0
                    ? retryAfter
                    : null,
            );
        }

        return { status: response.status, data, response };
    }

    async start(body: StartBody): Promise<VisitorSession> {
        const { data } = await this.request<{
            token: string;
            data: VisitorSession;
        }>('POST', this.config.endpoints.sessions, body);

        this.storage.set(data!.token);
        this.etag = null;

        return data!.data;
    }

    async session(): Promise<VisitorSession> {
        const { data } = await this.request<{ data: VisitorSession }>(
            'GET',
            this.config.endpoints.session,
        );

        return data!.data;
    }

    async end(): Promise<void> {
        try {
            await this.request('DELETE', this.config.endpoints.session);
        } finally {
            this.storage.clear();
            this.etag = null;
        }
    }

    async identify(identity: SignedIdentity): Promise<VisitorSession> {
        const { data } = await this.request<{
            token?: string;
            data: VisitorSession;
        }>('POST', this.config.endpoints.identity, identity);

        if (data?.token) {
            this.storage.set(data.token);
            this.etag = null;
        }

        return data!.data;
    }

    /** Latest page, or the page before a cursor. */
    async history(before?: string): Promise<MessagePage> {
        const url = new URL(this.config.endpoints.messages, location.origin);

        if (before) {
            url.searchParams.set('before', before);
        }

        const { data, response } = await this.request<MessagePage>(
            'GET',
            url.toString(),
        );

        if (!before) {
            this.etag = response.headers.get('ETag');
        }

        return data!;
    }

    /** What arrived since the cursor; null when nothing changed (304). */
    async since(after: string): Promise<MessagePage | null> {
        const url = new URL(this.config.endpoints.messages, location.origin);
        url.searchParams.set('after', after);

        const { status, data, response } = await this.request<MessagePage>(
            'GET',
            url.toString(),
            undefined,
            this.etag ? { 'If-None-Match': this.etag } : {},
        );

        if (status === 304) {
            return null;
        }

        this.etag = response.headers.get('ETag');

        return data;
    }

    /**
     * Sends a message; retried with the same client id on network errors
     * and 5xx (the server deduplicates it).
     */
    async send(clientMessageId: string, body: string): Promise<VisitorMessage> {
        for (let attempt = 0; ; attempt++) {
            try {
                const { data } = await this.request<{ data: VisitorMessage }>(
                    'POST',
                    this.config.endpoints.messages,
                    { client_message_id: clientMessageId, body },
                );

                return data!.data;
            } catch (error) {
                const retryable =
                    !(error instanceof ApiError) || error.status >= 500;

                if (!retryable || attempt >= RETRY_DELAYS_MS.length) {
                    throw error;
                }

                await wait(RETRY_DELAYS_MS[attempt]);
            }
        }
    }

    async receipts(receipt: {
        delivered_up_to?: string;
        read_up_to?: string;
    }): Promise<void> {
        await this.request('POST', this.config.endpoints.receipts, receipt);
    }
}

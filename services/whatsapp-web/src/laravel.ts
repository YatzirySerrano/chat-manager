import type { Deliver, DeliveryResult } from './outbox.js';
import { signedHeaders } from './signing.js';

export const EVENTS_PATH = '/internal/whatsapp-web/events';

/**
 * Delivers one event to Laravel (§5), signed with the shared secret.
 * 2xx acknowledges it; 404 means Laravel does not have that connection;
 * a malformed-event answer drops it; anything else is retried later.
 */
export function createEventDelivery(options: {
    laravelUrl: string;
    secret: string;
    timeoutMs: number;
    fetch?: typeof fetch;
}): Deliver {
    const target = new URL(
        options.laravelUrl.replace(/\/+$/, '') + EVENTS_PATH,
    );
    const request = options.fetch ?? fetch;

    return async (envelope): Promise<DeliveryResult> => {
        const body = JSON.stringify(envelope);

        const response = await request(target, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                Accept: 'application/json',
                ...signedHeaders(
                    options.secret,
                    'POST',
                    target.pathname + target.search,
                    body,
                ),
            },
            body,
            redirect: 'error',
            signal: AbortSignal.timeout(options.timeoutMs),
        });

        await response.body?.cancel().catch(() => undefined);

        if (response.status >= 200 && response.status < 300) {
            return 'ok';
        }

        if (response.status === 404) {
            return 'gone';
        }

        if (
            response.status === 400 ||
            response.status === 413 ||
            response.status === 422
        ) {
            return 'drop';
        }

        return 'retry';
    };
}

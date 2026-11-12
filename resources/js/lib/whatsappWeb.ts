import type {
    LinkState,
    WhatsAppWebActionName,
    WhatsAppWebState,
} from '@/types';

/**
 * WhatsApp Web (QR): the six routes the browser uses (contract §8,
 * docs/whatsapp-web-qr-contrato.md). The interface only talks to Laravel,
 * never to the Node service.
 *
 * The URLs are written here, in one place, because the screens were built
 * against the contract before the backend routes existed. Once Wayfinder
 * generates them, replace `linkUrl` with the generated helpers
 * (`@/routes/connections/whatsapp-web`): nothing else builds these paths.
 */

/** Value of `connections.provider` for this way of connecting. */
export const WHATSAPP_WEB_PROVIDER = 'whatsapp_web';

/** A connection (or its option) operated through WhatsApp Web (QR). */
export function isWhatsAppWebProvider(
    provider: string | null | undefined,
): boolean {
    return provider === WHATSAPP_WEB_PROVIDER;
}

type Endpoint = 'session' | WhatsAppWebActionName;

function linkUrl(connectionId: number, endpoint: Endpoint): string {
    return `/connections/${connectionId}/whatsapp-web/${endpoint}`;
}

/**
 * States that change by themselves within seconds (a QR being drawn, a
 * phone finishing the link, a socket coming back): polled fast.
 */
const LIVE_STATES: readonly WhatsAppWebState[] = [
    'starting',
    'qr_pending',
    'pairing',
    'reconnecting',
];

export function isLiveState(state: WhatsAppWebState): boolean {
    return LIVE_STATES.includes(state);
}

/**
 * A failed request to the session routes: the HTTP status and the message
 * the server wrote for the person (validation or authorization text).
 */
export class WhatsAppWebRequestError extends Error {
    constructor(
        public readonly status: number,
        message: string,
    ) {
        super(message);
        this.name = 'WhatsAppWebRequestError';
    }
}

function xsrfToken(): string {
    const match = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]+)/);

    return match ? decodeURIComponent(match[1]) : '';
}

const FALLBACK_MESSAGES: Record<number, string> = {
    403: 'No tienes permiso para administrar esta conexión.',
    404: 'Esta conexión ya no existe o no es de WhatsApp Web.',
    409: 'La sesión cambió de estado. Revisa el estado actual e inténtalo de nuevo.',
    419: 'Tu sesión caducó. Recarga la página e inténtalo de nuevo.',
    429: 'Demasiados intentos seguidos. Espera un momento e inténtalo de nuevo.',
    503: 'El servicio de WhatsApp Web no responde en este momento.',
};

async function readError(response: Response): Promise<WhatsAppWebRequestError> {
    let message =
        FALLBACK_MESSAGES[response.status] ??
        'No se pudo completar la acción. Inténtalo de nuevo.';

    try {
        const body = (await response.json()) as {
            message?: unknown;
            errors?: Record<string, string[]>;
        };
        const first = body.errors ? Object.values(body.errors)[0]?.[0] : null;

        if (typeof first === 'string' && first !== '') {
            message = first;
        } else if (
            typeof body.message === 'string' &&
            body.message !== '' &&
            response.status !== 500
        ) {
            message = body.message;
        }
    } catch {
        // Not JSON (a proxy error page): keep the fallback message.
    }

    return new WhatsAppWebRequestError(response.status, message);
}

async function request(
    url: string,
    method: 'GET' | 'POST',
    signal?: AbortSignal,
): Promise<LinkState> {
    const response = await fetch(url, {
        method,
        headers: {
            Accept: 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            ...(method === 'POST' ? { 'X-XSRF-TOKEN': xsrfToken() } : {}),
        },
        credentials: 'same-origin',
        signal,
    });

    if (!response.ok) {
        throw await readError(response);
    }

    return (await response.json()) as LinkState;
}

/** Current state of the session (GET …/whatsapp-web/session). */
export function fetchLinkState(
    connectionId: number,
    signal?: AbortSignal,
): Promise<LinkState> {
    return request(linkUrl(connectionId, 'session'), 'GET', signal);
}

/** Runs one session action (POST …/whatsapp-web/{action}) and returns the new state. */
export function runLinkAction(
    connectionId: number,
    action: WhatsAppWebActionName,
): Promise<LinkState> {
    return request(linkUrl(connectionId, action), 'POST');
}

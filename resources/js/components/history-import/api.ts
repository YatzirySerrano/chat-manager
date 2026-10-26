/**
 * JSON requests of the history importer screens (inventory, rules,
 * mappings, summary, progress). They need the HTTP status and the conflict
 * code of a 409 (`summary_changed`, `batch_locked`, `not_resumable`), so
 * they use fetch with the session cookie and the XSRF token instead of an
 * Inertia visit.
 */

export type HistoryRequestMethod = 'GET' | 'POST' | 'PUT' | 'PATCH' | 'DELETE';

/** A failed request: status, conflict code, message and field errors. */
export class HistoryRequestError extends Error {
    constructor(
        public readonly status: number,
        message: string,
        public readonly code: string | null = null,
        public readonly errors: Record<string, string> = {},
    ) {
        super(message);
        this.name = 'HistoryRequestError';
    }

    get isConflict(): boolean {
        return this.status === 409;
    }
}

function xsrfToken(): string | null {
    const match = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]+)/);

    return match ? decodeURIComponent(match[1]) : null;
}

/** Message shown when the server gives none, by status. */
export function fallbackMessage(status: number): string {
    switch (status) {
        case 0:
            return 'No hay conexión con el servidor. Revisa tu red e inténtalo de nuevo.';
        case 403:
            return 'No tienes autorización para realizar esta acción.';
        case 404:
            return 'Lo que buscas ya no existe o no pertenece a esta importación.';
        case 419:
            return 'Tu sesión expiró. Recarga la página para continuar.';
        case 429:
            return 'Hiciste demasiadas acciones seguidas. Espera un minuto e inténtalo de nuevo.';
        case 422:
            return 'Revisa los datos e inténtalo de nuevo.';
        default:
            return 'No se pudo completar la acción. Inténtalo de nuevo en unos minutos.';
    }
}

function firstErrors(raw: unknown): Record<string, string> {
    const errors: Record<string, string> = {};

    if (raw && typeof raw === 'object') {
        for (const [key, value] of Object.entries(
            raw as Record<string, unknown>,
        )) {
            const message = Array.isArray(value) ? value[0] : value;

            if (typeof message === 'string') {
                errors[key] = message;
            }
        }
    }

    return errors;
}

/**
 * True when the request was superseded (aborted through its signal). The
 * caller ignores it: a newer request already owns the screen.
 */
export function isAborted(error: unknown): boolean {
    return error instanceof DOMException && error.name === 'AbortError';
}

/**
 * Keeps only the latest of a series of requests: `next()` aborts the
 * previous one and returns the signal of the new one; `cancel()` aborts the
 * one in flight (on unmount or when its inputs change).
 */
export function latestRequest(): {
    next: () => AbortSignal;
    cancel: () => void;
} {
    let controller: AbortController | null = null;

    return {
        next(): AbortSignal {
            controller?.abort();
            controller = new AbortController();

            return controller.signal;
        },
        cancel(): void {
            controller?.abort();
            controller = null;
        },
    };
}

export async function historyRequest<T>(
    method: HistoryRequestMethod,
    url: string,
    body?: unknown,
    signal?: AbortSignal,
): Promise<T> {
    const headers: Record<string, string> = {
        Accept: 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
    };
    const token = xsrfToken();

    if (token) {
        headers['X-XSRF-TOKEN'] = token;
    }

    if (body !== undefined) {
        headers['Content-Type'] = 'application/json';
    }

    let response: Response;

    try {
        response = await fetch(url, {
            method,
            credentials: 'same-origin',
            headers,
            body: body === undefined ? undefined : JSON.stringify(body),
            signal,
        });
    } catch (error) {
        if (isAborted(error)) {
            throw error;
        }

        throw new HistoryRequestError(0, fallbackMessage(0));
    }

    let payload: unknown = null;

    try {
        payload = await response.json();
    } catch {
        payload = null;
    }

    if (signal?.aborted) {
        throw new DOMException('Superseded request', 'AbortError');
    }

    if (!response.ok) {
        const data = (payload ?? {}) as {
            message?: unknown;
            code?: unknown;
            errors?: unknown;
        };
        const errors = firstErrors(data.errors);
        const message =
            response.status === 422
                ? (Object.values(errors)[0] ??
                  (typeof data.message === 'string'
                      ? data.message
                      : fallbackMessage(422)))
                : typeof data.message === 'string' &&
                    data.message !== '' &&
                    response.status !== 500
                  ? data.message
                  : fallbackMessage(response.status);

        throw new HistoryRequestError(
            response.status,
            message,
            typeof data.code === 'string' ? data.code : null,
            errors,
        );
    }

    return payload as T;
}

/** Message of any error thrown by historyRequest (or anything else). */
export function errorMessage(error: unknown): string {
    return error instanceof HistoryRequestError
        ? error.message
        : fallbackMessage(-1);
}

/** Query string values of a filter object (booleans as 1, empty dropped). */
export function filterQuery(
    filters: Record<string, unknown>,
): Record<string, string> {
    const query: Record<string, string> = {};

    for (const [key, value] of Object.entries(filters)) {
        if (value === true) {
            query[key] = '1';
        } else if (typeof value === 'string' && value.trim() !== '') {
            query[key] = value.trim();
        } else if (typeof value === 'number') {
            query[key] = String(value);
        }
    }

    return query;
}

/** The same filters without empty values (body of bulk actions and rules). */
export function compactFilters<T extends Record<string, unknown>>(
    filters: T,
): Partial<T> {
    const compact: Record<string, unknown> = {};

    for (const [key, value] of Object.entries(filters)) {
        if (value === true || (typeof value === 'string' && value !== '')) {
            compact[key] = value;
        }
    }

    return compact as Partial<T>;
}

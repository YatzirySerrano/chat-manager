/**
 * Refusals of conversation operations (accept, assign, transfer, release,
 * close, reopen, reply…), as the server tells them apart:
 *
 * - 404: the conversation is outside the user's reach (it looks missing);
 * - 403: the user can see it but may not perform the operation;
 * - 409: the operation is allowed, but the conversation changed meanwhile
 *   (`code`: state_changed, assigned_to_other or closed). The caller shows
 *   the message and reloads the conversation so its real state appears.
 *
 * JSON requests receive `{code, message}`; Inertia visits receive the
 * message under `errors.conversation` and the code under
 * `errors.conversation_conflict` (ConversationStateConflict on the server).
 */

export type ConversationConflictCode =
    | 'state_changed'
    | 'assigned_to_other'
    | 'closed';

export type ConversationActionErrorBody = {
    code?: string;
    message?: string;
} | null;

export const CONVERSATION_FORBIDDEN_MESSAGE =
    'No tienes autorización para realizar esta acción.';

/** 403 on imported history: it is read-only for good. */
export const CONVERSATION_IMPORTED_MESSAGE =
    'Esta conversación es un historial importado y es de solo consulta.';

export const CONVERSATION_CONFLICT_MESSAGES: Record<
    ConversationConflictCode,
    string
> = {
    state_changed:
        'Esta conversación cambió de estado. Actualiza la información para continuar.',
    assigned_to_other: 'Otro agente ya está atendiendo esta conversación.',
    closed: 'Esta conversación está cerrada. Revisa las opciones disponibles.',
};

/** Error bag key with the conflict code on Inertia visits. */
export const CONVERSATION_CONFLICT_ERROR_KEY = 'conversation_conflict';

const NOT_FOUND_MESSAGE =
    'Esta conversación ya no está disponible para ti. Actualiza la información para continuar.';
const SESSION_EXPIRED_MESSAGE =
    'Tu sesión expiró. Recarga la página para continuar.';
const TOO_MANY_MESSAGE =
    'Hiciste demasiadas solicitudes seguidas. Espera un momento e inténtalo de nuevo.';
const GENERIC_MESSAGE = 'No se pudo completar la acción. Inténtalo de nuevo.';

function isConflictCode(code: unknown): code is ConversationConflictCode {
    return typeof code === 'string' && code in CONVERSATION_CONFLICT_MESSAGES;
}

export function isConversationConflict(status: number): boolean {
    return status === 409;
}

/**
 * The explanation to show for a refused conversation operation.
 */
export function conversationActionErrorMessage(
    status: number,
    body?: ConversationActionErrorBody,
): string {
    const message = body?.message?.trim() || null;

    if (status === 403) {
        return message === CONVERSATION_IMPORTED_MESSAGE
            ? CONVERSATION_IMPORTED_MESSAGE
            : CONVERSATION_FORBIDDEN_MESSAGE;
    }

    if (isConversationConflict(status)) {
        if (isConflictCode(body?.code)) {
            return CONVERSATION_CONFLICT_MESSAGES[body.code];
        }

        // Other conflicts (an agent at their limit, another active
        // conversation…) carry their own explanation.
        return message ?? CONVERSATION_CONFLICT_MESSAGES.state_changed;
    }

    if (status === 404) {
        return NOT_FOUND_MESSAGE;
    }

    if (status === 419) {
        return SESSION_EXPIRED_MESSAGE;
    }

    if (status === 429) {
        return TOO_MANY_MESSAGE;
    }

    // Other client errors explain themselves; server errors never show
    // their technical text.
    return status >= 400 && status < 500 && message ? message : GENERIC_MESSAGE;
}

/**
 * The status and JSON body of a failed `useHttp` request (or null when the
 * failure is not an HTTP response: network error, cancellation).
 */
export function conversationActionFailure(
    failure: unknown,
): { status: number; body: ConversationActionErrorBody } | null {
    if (
        typeof failure !== 'object' ||
        failure === null ||
        !('response' in failure)
    ) {
        return null;
    }

    const response = (
        failure as { response: { status: number; data: unknown } }
    ).response;
    let body: ConversationActionErrorBody = null;

    if (typeof response.data === 'string') {
        try {
            body = JSON.parse(response.data) as ConversationActionErrorBody;
        } catch {
            body = null;
        }
    } else if (typeof response.data === 'object' && response.data !== null) {
        body = response.data as ConversationActionErrorBody;
    }

    return { status: response.status, body };
}

/**
 * The state conflict of an Inertia visit, if its errors carry one.
 */
export function conversationConflictFromErrors(
    errors: Record<string, string | undefined>,
): { code: ConversationConflictCode; message: string } | null {
    const code = errors[CONVERSATION_CONFLICT_ERROR_KEY];

    if (!isConflictCode(code)) {
        return null;
    }

    return {
        code,
        message: errors.conversation ?? CONVERSATION_CONFLICT_MESSAGES[code],
    };
}

/**
 * The errors to display, without the conflict code (not a message).
 */
export function withoutConflictCode(
    errors: Record<string, string | undefined>,
): Record<string, string | undefined> {
    return Object.fromEntries(
        Object.entries(errors).filter(
            ([key]) => key !== CONVERSATION_CONFLICT_ERROR_KEY,
        ),
    );
}

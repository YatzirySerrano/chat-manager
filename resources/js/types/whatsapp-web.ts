/**
 * WhatsApp Web (QR): an unofficial way to connect a number, next to the
 * official Cloud API. Types of docs/whatsapp-web-qr-contrato.md (§3 and §8).
 */

/** Real state of a session (§3). Only `connected` can send. */
export type WhatsAppWebState =
    | 'unconfigured'
    | 'starting'
    | 'qr_pending'
    | 'pairing'
    | 'connected'
    | 'reconnecting'
    | 'disconnected'
    | 'logged_out'
    | 'error';

export type WhatsAppWebTone =
    | 'success'
    | 'warning'
    | 'danger'
    | 'neutral'
    | 'info';

/** What the viewer may do with the session right now (the server decides). */
export type WhatsAppWebActions = {
    link: boolean;
    refresh: boolean;
    disconnect: boolean;
    reconnect: boolean;
    logout: boolean;
};

export type WhatsAppWebActionName = keyof WhatsAppWebActions;

/**
 * Answer of every `connections/{id}/whatsapp-web/*` route (§8). The QR is an
 * SVG drawn by the server and only travels here, while `state` is
 * `qr_pending`; session credentials never reach the browser.
 */
export type LinkState = {
    state: WhatsAppWebState;
    state_label: string;
    tone: WhatsAppWebTone;
    can_send: boolean;
    /** False when the Node service does not answer. */
    service_available: boolean;
    /** Formatted number ("+52 55 1234 5678"), once WhatsApp reports it. */
    phone: string | null;
    push_name: string | null;
    qr: { svg: string; expires_at: string; seconds_left: number } | null;
    connected_since: string | null;
    last_event_at: string | null;
    last_inbound_at: string | null;
    last_outbound_at: string | null;
    reconnects: number;
    /** Readable text in Spanish, or null. */
    last_error: string | null;
    actions: WhatsAppWebActions;
};

/** Session of a connection as lists show it (no QR, no actions). */
export type WhatsAppWebSessionSummary = {
    state: WhatsAppWebState;
    state_label: string;
    tone: WhatsAppWebTone;
    can_send: boolean;
    phone: string | null;
};

/** One session in Administración > Sistema: technical state only. */
export type WhatsAppWebSystemSession = WhatsAppWebSessionSummary & {
    connection_id: number;
    name: string;
    connected_since: string | null;
    last_event_at: string | null;
    last_inbound_at: string | null;
    last_outbound_at: string | null;
    reconnects: number;
    last_error: string | null;
};

/** State of the Node service and its sessions for the system page. */
export type WhatsAppWebSystem = {
    enabled: boolean;
    service: {
        available: boolean;
        version: string | null;
        library: string | null;
        uptime_seconds: number | null;
    } | null;
    sessions: WhatsAppWebSystemSession[];
};

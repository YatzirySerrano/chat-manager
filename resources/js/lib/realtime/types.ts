/**
 * Real-time configuration shared by the server on every page (the
 * "realtime" Inertia prop, App\Broadcasting\RealtimeClientConfig). Only
 * public values: the Reverb application key identifies the application,
 * channel authorizations are always signed by the server.
 */
export type RealtimeConfig = {
    enabled: boolean;
    key: string | null;
    host: string | null;
    port: number | null;
    scheme: 'http' | 'https' | null;
    path: string | null;
    /** Personal channel of the signed-in user ("users.{id}"). */
    user_channel: string | null;
    /** Inbox channels that match the user's current inbox and permissions. */
    inbox_channels: string[];
};

/**
 * - connecting: opening (or re-opening) the WebSocket;
 * - connected: signals arrive in real time;
 * - unavailable: the connection dropped or the server is unreachable, pages
 *   fall back to periodic refresh while it retries;
 * - disabled: real-time is not configured (BROADCAST_CONNECTION is not
 *   "reverb") or nobody is signed in: periodic refresh, silently.
 */
export type RealtimeState =
    | 'connecting'
    | 'connected'
    | 'unavailable'
    | 'disabled';

/**
 * Payload of a signal: identifiers and minimal metadata only, never message
 * bodies, phone numbers, note text or file names.
 */
export type RealtimePayload = Record<string, string | number | boolean | null>;

export type RealtimeHandler = (payload: RealtimePayload) => void;

/** A channel and the events of it that should refresh the page. */
export type LiveChannel = {
    name: string;
    events: string[];
};

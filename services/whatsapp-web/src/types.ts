/**
 * Shapes of the contract (docs/whatsapp-web-qr-contrato.md §3 to §5).
 */
export const SESSION_STATES = [
    'unconfigured',
    'starting',
    'qr_pending',
    'pairing',
    'connected',
    'reconnecting',
    'disconnected',
    'logged_out',
    'error',
] as const;

export type SessionState = (typeof SESSION_STATES)[number];

export type StateReason =
    | 'qr_timeout'
    | 'logged_out_from_phone'
    | 'replaced'
    | 'banned'
    | 'network'
    | 'restart'
    | 'bad_session';

export type SessionError = { code: string; message: string };

export type SessionSnapshot = {
    connection_id: number;
    state: SessionState;
    phone: string | null;
    push_name: string | null;
    qr: { value: string; expires_at: number } | null;
    connected_since: number | null;
    last_event_at: number | null;
    last_inbound_at: number | null;
    last_outbound_at: number | null;
    reconnects: number;
    last_error: SessionError | null;
};

export type MessageKind =
    | 'text'
    | 'image'
    | 'video'
    | 'audio'
    | 'document'
    | 'sticker'
    | 'location'
    | 'contacts'
    | 'reaction'
    | 'unsupported';

export type MediaKind = 'image' | 'video' | 'audio' | 'document' | 'sticker';

/** Everything needed to download and decrypt a received file again. */
export type MediaDescriptor = {
    kind: MediaKind;
    media_key: string;
    direct_path: string | null;
    url: string | null;
    mime: string | null;
    size: number | null;
};

export type InboundMessage = {
    provider_message_id: string;
    remote_jid: string;
    phone: string | null;
    push_name: string | null;
    from_me: boolean;
    timestamp: number;
    type: MessageKind;
    text: string | null;
    quoted: { provider_message_id: string } | null;
    media?: {
        mime: string | null;
        size: number | null;
        file_name: string | null;
        seconds: number | null;
        voice: boolean;
        descriptor: MediaDescriptor;
    };
    location?: {
        latitude: number;
        longitude: number;
        name: string | null;
        address: string | null;
    };
    contacts?: { name: string | null; phones: string[] }[];
    /** Reactions only: the emoji ("" when the reaction was removed). */
    reaction?: { provider_message_id: string; emoji: string };
};

export type DeliveryStatus = 'sent' | 'delivered' | 'read' | 'failed';

export type StatusUpdate = {
    provider_message_id: string;
    status: DeliveryStatus;
    timestamp: number;
    error: SessionError | null;
};

export type OutboundKind =
    | 'text'
    | 'image'
    | 'video'
    | 'audio'
    | 'document'
    | 'sticker';

export type OutboundMessage = {
    idempotency_key: string;
    to: string;
    type: OutboundKind;
    text?: string | null;
    quoted?: {
        provider_message_id: string;
        from_me?: boolean;
        remote_jid?: string;
    } | null;
    media?: {
        url: string;
        mime: string;
        file_name?: string | null;
        voice?: boolean;
    } | null;
};

export type ServiceEvent =
    | {
          type: 'session.state';
          data: SessionSnapshot & { reason?: StateReason };
      }
    | { type: 'message.inbound'; data: InboundMessage }
    | { type: 'message.outbound_echo'; data: InboundMessage }
    | { type: 'message.status'; data: StatusUpdate };

export type EventEnvelope = ServiceEvent & {
    event_id: string;
    connection_id: number;
    occurred_at: number;
};

/** Refusals of the control API, with their HTTP status (§4). */
export class ApiError extends Error {
    constructor(
        public readonly status: number,
        public readonly code: string,
        public readonly extra: Record<string, unknown> = {},
    ) {
        super(code);
    }
}

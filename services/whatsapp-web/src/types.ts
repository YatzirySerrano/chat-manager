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
    | 'poll'
    | 'interactive'
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
    /**
     * The message being answered. `from_me` and `text` (a short excerpt, or
     * the name of what was quoted) are only present when WhatsApp sent them:
     * they let Laravel draw the quote even when it never stored the original.
     */
    quoted: {
        provider_message_id: string;
        from_me?: boolean;
        text?: string | null;
    } | null;
    media?: {
        mime: string | null;
        size: number | null;
        file_name: string | null;
        seconds: number | null;
        voice: boolean;
        /** Null when WhatsApp sent no download key: the message is kept, the file is not. */
        descriptor: MediaDescriptor | null;
        /** Video that plays as a GIF (looped, muted). */
        gif?: boolean;
        /** Animated sticker. */
        animated?: boolean;
        width?: number | null;
        height?: number | null;
        /** Tiny JPEG preview WhatsApp embeds in the message, in base64. */
        thumbnail?: string | null;
    };
    location?: {
        latitude: number;
        longitude: number;
        name: string | null;
        address: string | null;
        /** Real-time location (only the position it was shared from is known). */
        live?: boolean;
    };
    contacts?: { name: string | null; phones: string[] }[];
    /** Reactions only: the emoji ("" when the reaction was removed). */
    reaction?: { provider_message_id: string; emoji: string };
    poll?: { question: string | null; options: string[]; multiple: boolean };
    /** Answer to a button, a list or a template of a business account. */
    interactive?: {
        kind: 'button_reply' | 'list_reply';
        title: string | null;
        description: string | null;
    };
    /** type = unsupported: what it was, in words a person understands. */
    unsupported?: { kind: string; label: string };
    /** The sender marked it to be seen once. */
    view_once?: boolean;

    // -- history only (history.batch) -------------------------------------
    /** Name of the chat in the phone's address book, when the history carries it. */
    chat_name?: string | null;
    /** Delivery state WhatsApp recorded for a message of the business. */
    status?: DeliveryStatus | null;
    /** The sender deleted it for everyone before it was imported. */
    revoked?: boolean;
    reactions?: { emoji: string; from_me: boolean }[];
};

/** The sender edited a message: its new text (or caption). */
export type MessageEdit = {
    provider_message_id: string;
    remote_jid: string;
    from_me: boolean;
    timestamp: number;
    type: MessageKind;
    text: string | null;
};

/** The sender deleted a message for everyone. */
export type MessageRevocation = {
    provider_message_id: string;
    remote_jid: string;
    from_me: boolean;
    timestamp: number;
};

/** A slice of the history WhatsApp hands to a newly linked device. */
export type HistoryBatch = {
    sync_type: string | null;
    /** 0 to 100 as WhatsApp reports it; null when unknown. */
    progress: number | null;
    messages: InboundMessage[];
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
        text?: string | null;
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
    | { type: 'message.status'; data: StatusUpdate }
    | { type: 'message.edited'; data: MessageEdit }
    | { type: 'message.revoked'; data: MessageRevocation }
    | { type: 'history.batch'; data: HistoryBatch };

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

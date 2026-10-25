import type { Appearance } from './protocol';

/**
 * Public types of the web chat widget: the loader's API (window.ChatManager)
 * and the data the frame receives from the server (embedded configuration
 * and the visitor API under /api/v1/widget).
 */
export type WidgetEvent = 'ready' | 'open' | 'close' | 'unread' | 'error';

export type WidgetListener = (payload?: unknown) => void;

export type SignedIdentity = {
    external_id: string;
    name?: string | null;
    email?: string | null;
    iat: number;
    nonce: string;
    signature: string;
};

export type WidgetOptions = {
    key: string;
    container?: Element;
    position?: 'right' | 'left';
    offset?: { x?: number; y?: number };
    zIndex?: number;
    launcher?: boolean;
    open?: boolean;
    locale?: string;
    onReady?: () => void;
    onOpen?: () => void;
    onClose?: () => void;
    onUnread?: (count: number) => void;
};

export type WidgetInstance = {
    open(): void;
    close(): void;
    toggle(): void;
    isOpen(): boolean;
    identify(identity: SignedIdentity): void;
    /** Ends the visitor's session (call it when the site's user signs out). */
    logout(): void;
    on(event: WidgetEvent, listener: WidgetListener): () => void;
    off(event: WidgetEvent, listener: WidgetListener): void;
    destroy(): void;
};

export type FrameState = 'ready' | 'offline' | 'unauthorized' | 'preview';

export type Availability = { online: boolean; message: string | null };

/** Embedded by resources/views/widget/frame.blade.php (WidgetFrameController). */
export type FrameConfig = {
    v: 1;
    state: FrameState;
    public_key: string;
    host_origin: string | null;
    allowed_origins: string[];
    ticket: string | null;
    appearance: Appearance;
    identity_mode: 'anonymous' | 'optional' | 'required';
    prechat: {
        fields: ('name' | 'email')[];
        required: boolean;
        privacy_notice: string | null;
    };
    availability: Availability | null;
    message_max_length: number;
    poll: { visible_seconds: number; hidden_seconds: number };
    endpoints: {
        sessions: string;
        session: string;
        identity: string;
        messages: string;
        receipts: string;
    };
};

/** `on_hold` is retired; it may still arrive for old conversations. */
export type ConversationState =
    | 'none'
    | 'pending'
    | 'open'
    | 'on_hold'
    | 'closed';

export type RealtimeConfig = {
    enabled: boolean;
    key: string | null;
    host: string | null;
    port: number | null;
    scheme: 'http' | 'https' | null;
    path: string | null;
    channel: string | null;
    auth_endpoint: string | null;
};

export type VisitorSession = {
    id: string;
    identified: boolean;
    profile: { name: string | null };
    expires_at: string;
    conversation: { status: ConversationState; agent_name: string | null };
    availability: Availability;
    realtime: RealtimeConfig;
    poll: { visible_seconds: number; hidden_seconds: number };
};

export type VisitorAttachment = {
    name: string;
    mime_type: string | null;
    size_bytes: number | null;
    url: string;
};

export type VisitorMessage = {
    cursor: string;
    direction: 'inbound' | 'outbound';
    author: 'visitor' | 'agent' | 'system';
    agent_name: string | null;
    body: string | null;
    attachments: VisitorAttachment[];
    created_at: string | null;
};

export type MessagePage = {
    data: VisitorMessage[];
    meta: {
        next_cursor: string | null;
        prev_cursor: string | null;
        limit: number;
    };
};

/** Delivery state the visitor sees on their own messages. */
export type LocalStatus = 'sending' | 'sent' | 'delivered' | 'read' | 'failed';

export type ThreadMessage = VisitorMessage & {
    /** Client id of the visitor's own messages (also while sending). */
    clientId?: string;
    localStatus?: LocalStatus;
    /** Sort key: the message id behind the cursor (Infinity while sending). */
    order: number;
};

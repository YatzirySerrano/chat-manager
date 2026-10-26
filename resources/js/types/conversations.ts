import type { AppColor } from './admin';
import type { ConnectionChannel } from './connections';
import type { ContactAttribution } from './contacts';
import type { ViewerChatAppearance } from './personalization';
import type { TagOption } from './tags';

/**
 * `on_hold` is a retired state: it only arrives for old conversations not
 * migrated yet (conversations:retire-on-hold); nothing offers it.
 */
export type ConversationStatusValue = 'pending' | 'open' | 'on_hold' | 'closed';

export type ConversationStatusFilter =
    | ConversationStatusValue
    | 'active'
    | 'all';

/** Operations the viewer may perform, resolved by the server's policy. */
export type ConversationAbilities = {
    view: boolean;
    accept: boolean;
    assign: boolean;
    transfer: boolean;
    release: boolean;
    close: boolean;
    reopen: boolean;
    addNote: boolean;
    reply: boolean;
    viewHistory: boolean;
    tag: boolean;
    /** Read-only supervision of the viewer's reach; never grants operations. */
    supervise: boolean;
    /** May send WhatsApp templates (reply + administrative templates.send). */
    send_templates: boolean;
};

export type ConversationSummary = {
    id: number;
    status: ConversationStatusValue;
    status_label: string;
    /** Sent back with every operation so decisions on stale data are rejected. */
    version: number;
    contact: {
        id: number;
        name: string;
        profile_name: string | null;
        phone_e164: string | null;
        /** The viewer's own private alias (never another user's). */
        alias: string | null;
        /** Name shown to the viewer: alias, else name, profile name or phone. */
        display_name: string;
        /** False while the customer is a provisional contact (not in the directory). */
        is_saved: boolean;
    };
    connection: ConversationConnection;
    department: {
        id: number;
        name: string;
        color: AppColor;
        is_active: boolean;
    } | null;
    assignee: { id: number | null; name: string; is_active: boolean } | null;
    last_message_at: string | null;
    last_inbound_at: string | null;
    assigned_at: string | null;
    closed_at: string | null;
    reopen_count: number;
    created_at: string | null;
    tags?: TagOption[];
    /** Customer messages after the viewer's read marker (list rows only). */
    unread_count?: number;
    last_message?: ConversationMessagePreview | null;
    /** The customer wrote last and nobody answered yet. */
    awaiting_reply: boolean;
    can: ConversationAbilities;
    /** The viewer's own chat colors (open conversation only). */
    appearance?: ViewerChatAppearance;
};

/** Connection a conversation arrived through. */
export type ConversationConnection = {
    id: number;
    name: string;
    phone: string | null;
    /** Channel of the connection (ConnectionChannel on the server). */
    channel: ConnectionChannel;
    channel_label: string;
    /** Paused (inactive) and deleted connections are told apart. */
    state: 'active' | 'inactive' | 'deleted';
    /** Whether a reply can be sent now; the server checks it again. */
    can_send: boolean;
    /** Why nothing can be sent through the connection, if so. */
    send_block_reason: string | null;
    /** Credentials accepted by Meta (a restored connection is not; always false for the web chat). */
    credentials_verified: boolean;
};

export type ConversationMessagePreview = {
    id: number;
    direction: MessageDirection;
    type: MessageTypeValue;
    body: string | null;
    status: MessageStatusValue;
    automatic: boolean;
    /** Error code of a failed message (e.g. delivery_unknown), null otherwise. */
    error_code: string | null;
    sent_at: string | null;
    /** Version of the delivery state (messages.updated_at). */
    updated_at?: string | null;
};

export type ConversationSort = 'priority' | 'recent' | 'oldest';

/**
 * A page of the inbox list. `next_cursor` points after its last row; the
 * rows that follow come from the list endpoint with `after`.
 */
export type ConversationList = {
    data: ConversationSummary[];
    has_more: boolean;
    next_cursor: string | null;
};

/** Where the inbox search looks: the contact, or the messages' content. */
export type ConversationSearchScope = 'contact' | 'messages';

/**
 * Views of the inbox: the general inbox of whoever attends conversations
 * (pending ones they may accept and their own open ones), the viewer's own
 * ones, every authorized conversation (team inboxes; the default of
 * supervisors) and 'queue', kept as an alias of general with
 * status=pending.
 */
export type InboxView = 'general' | 'mine' | 'queue' | 'all';

/** @deprecated Use InboxView ('general' is the default view of whoever attends conversations). */
export type ConversationInboxView = InboxView;

/** Counters of the inbox chips, computed in one query by the server. */
export type InboxCounts = {
    general: number;
    mine: number;
    pending: number;
    attending: number;
};

/** How several tags of the filter combine: any of them (OR) or all (AND). */
export type InboxTagMatch = 'any' | 'all';

/** What the inbox list shows the viewer. */
export type InboxViewer = {
    /**
     * Supervises or distributes conversations (or has global reach): the
     * rows show department, responsible agent and connection.
     */
    can_supervise: boolean;
};

export type ConversationInbox = {
    /** The viewer's reach does not go beyond their own assignments. */
    personal: boolean;
    /** Views the viewer may open; the first one is the default. */
    views: InboxView[];
    /** Data scope of the viewer's role, null without an active role. */
    scope: 'global' | 'departments' | 'assigned' | null;
    /**
     * Supervises a team reach: the inbox opens on every conversation of it
     * ('all'), whoever attends it.
     */
    supervises: boolean;
};

export type ConversationFilters = {
    view: InboxView;
    status: ConversationStatusFilter;
    department: string | null;
    agent: string | null;
    connection: number | null;
    /** ConnectionChannel value, or null for every channel. */
    channel: ConnectionChannel | null;
    search: string;
    search_in: ConversationSearchScope;
    /** The message search has no word it can look for (short words, stopwords). */
    search_ignored: boolean;
    /** Conversation tags of the filter (at most 10). */
    tags: number[];
    tag_match: InboxTagMatch;
    sort: ConversationSort;
    /** Only conversations with messages unread for the viewer. */
    unread: boolean;
};

export type MessageDirection = 'inbound' | 'outbound';

export type MessageTypeValue =
    | 'text'
    | 'image'
    | 'audio'
    | 'video'
    | 'document'
    | 'sticker'
    | 'location'
    | 'contacts'
    | 'template'
    | 'interactive'
    | 'reaction'
    | 'unsupported'
    | 'button';

/**
 * Delivery states: received (inbound), queued, sending, accepted (by the
 * provider), sent, delivered, read (only from Meta evidence) and failed.
 */
export type MessageStatusValue =
    | 'received'
    | 'queued'
    | 'sending'
    | 'accepted'
    | 'sent'
    | 'delivered'
    | 'read'
    | 'failed';

export type MessageAttachment = {
    id: number;
    mime_type: string;
    file_name: string | null;
    size_bytes: number | null;
    /** False while the file has not been downloaded from the provider. */
    available: boolean;
    status: string | null;
    url: string;
    download_url: string;
    /** 'link': the customer received a secure download link instead of the file. */
    delivery_method?: 'native' | 'link';
    /** Until when the customer's download link works (link delivery only). */
    link_expires_at?: string | null;
    /** When the download link was revoked (it no longer works). */
    link_revoked_at?: string | null;
    /** POST endpoint that revokes the customer's download link (outbound link deliveries). */
    link_revoke_url?: string | null;
};

export type MessageContent =
    | {
          latitude: number | null;
          longitude: number | null;
          name: string | null;
          address: string | null;
      }
    | { contacts: { name: string | null; phones: string[] }[] }
    | { kind: string | null; title: string | null; description: string | null }
    | { emoji: string | null; reacted_message_id: number | null };

export type ConversationMessage = {
    id: number;
    direction: MessageDirection;
    type: MessageTypeValue;
    body: string | null;
    status: MessageStatusValue;
    sender?: { id: number | null; name: string } | null;
    error_code: string | null;
    error_message: string | null;
    /** Partial delivery note (e.g. the file went out but its text did not). */
    delivery_warning?: string | null;
    automatic: boolean;
    /** Sent through the integration API (no human author). */
    integration?: { name: string } | null;
    /** Sent by the explicitly labelled simulated transport (development). */
    simulated: boolean;
    template: { name: string; language: string | null } | null;
    content: MessageContent | null;
    reply_to?: {
        id: number;
        direction: MessageDirection;
        type: MessageTypeValue;
        body: string | null;
    } | null;
    attachments: MessageAttachment[];
    sent_at: string | null;
    created_at: string | null;
    delivered_at: string | null;
    read_at: string | null;
    failed_at: string | null;
    /** Last change of the stored message (delivery state version). */
    updated_at?: string | null;
    /** Calls made to the provider; breaks a tie of updated_at between delivery states. */
    delivery_attempts?: number;
    /** Manual retries; a retry requeues without a new attempt, so it breaks the tie first. */
    delivery_retries?: number;
};

export type ConversationNote = {
    id: number;
    body: string;
    author?: { id: number | null; name: string } | null;
    created_at: string | null;
    edited_at: string | null;
    can: { update: boolean };
};

export type ConversationEvent = {
    id: number;
    type: string;
    label: string;
    actor: string | null;
    from_user: string | null;
    to_user: string | null;
    from_department: string | null;
    to_department: string | null;
    note: string | null;
    trigger: string | null;
    message_id: number | null;
    /** Error code of a failed or blocked message (e.g. delivery_unknown). */
    reason: string | null;
    created_at: string;
};

/** One page (or update) of the chat: messages, notes and events. */
export type ConversationTimelinePage = {
    messages: ConversationMessage[];
    notes: ConversationNote[];
    events: ConversationEvent[];
    has_more: boolean;
    /**
     * Set when the page was shortened because its span held too many notes
     * or events: the next older page must be asked with it.
     */
    until?: string | null;
};

export type ConversationDetails = {
    contact: {
        id: number;
        name: string;
        profile_name: string | null;
        phone_e164: string | null;
        /** The viewer's own private alias (never another user's). */
        alias: string | null;
        display_name: string;
        email: string | null;
        first_interaction_at: string | null;
        last_interaction_at: string | null;
        tags: TagOption[];
        /** False while the customer is a provisional contact (not in the directory). */
        is_saved: boolean;
        /** The viewer may save this provisional contact into the directory. */
        can_save: boolean;
    };
    origin: ContactAttribution[];
    history: {
        id: number;
        status: ConversationStatusValue;
        status_label: string;
        department: {
            id: number;
            name: string;
            color: AppColor;
            is_active: boolean;
        } | null;
        assignee: string | null;
        created_at: string | null;
        closed_at: string | null;
    }[];
    contactNotes:
        | {
              id: number;
              body: string;
              author: string | null;
              created_at: string | null;
          }[]
        | null;
    availableTags: TagOption[];
    canExportDossier: boolean;
};

/** One kind of file a channel accepts from the composer (ChannelAttachmentPolicy). */
export type AttachmentKindRule = {
    key: 'image' | 'document' | 'audio' | 'video' | 'archive';
    type: 'image' | 'document' | 'audio' | 'video';
    label: string;
    mimes: string[];
    extensions: string[];
    max_bytes: number;
    /** Whether text may travel with the file. */
    caption: boolean;
    /** 'link': sent as a secure expiring download link. */
    delivery: 'native' | 'link';
};

export type ChannelAttachmentRules = {
    channel: string;
    channel_label: string;
    max_bytes: number;
    /** Days a secure download link keeps working. */
    link_ttl_days: number;
    kinds: AttachmentKindRule[];
};

export type ComposerSettings = {
    body_max: number;
    caption_max: number;
    note_max: number;
    /** Attachment policy per channel (whatsapp, web_chat, messenger, instagram). */
    attachments: Record<string, ChannelAttachmentRules>;
};

/** State of the customer service window; 'not_applicable' for channels without one (web chat). */
export type CustomerWindowStatus =
    | 'none'
    | 'open'
    | 'expiring'
    | 'expired'
    | 'not_applicable';

/** WhatsApp's 24 hour customer service window, computed by the server in UTC. */
export type CustomerWindow = {
    status: CustomerWindowStatus;
    label: string;
    last_inbound_at: string | null;
    expires_at: string | null;
    remaining_seconds: number;
    allows_free_form: boolean;
    block_reason: string | null;
};

/**
 * A person who could receive the conversation. Only what is needed to
 * choose: never their conversations, email, role or load figures.
 */
export type TransferAgentOption = {
    id: number;
    name: string;
    /** Already attends their maximum of conversations for that department. */
    at_capacity: boolean;
};

/** Response of GET conversations/{id}/destinations. */
export type TransferOptions = {
    /** The viewer may hand it to someone unavailable or at their limit (with confirmation). */
    allows_exceptions: boolean;
    departments: { id: number; name: string; agents: TransferAgentOption[] }[];
    unrouted_agents: TransferAgentOption[];
};

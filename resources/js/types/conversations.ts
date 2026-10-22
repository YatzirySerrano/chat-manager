import type { AppColor } from './admin';
import type { ContactAttribution } from './contacts';
import type { TagOption } from './tags';

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
    hold: boolean;
    resume: boolean;
    close: boolean;
    reopen: boolean;
    addNote: boolean;
    reply: boolean;
    viewHistory: boolean;
    tag: boolean;
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
    };
    connection: { id: number; name: string; phone: string | null };
    department: {
        id: number;
        name: string;
        color: AppColor;
        is_active: boolean;
    } | null;
    assignee: { id: number; name: string; is_active: boolean } | null;
    last_message_at: string | null;
    last_inbound_at: string | null;
    assigned_at: string | null;
    on_hold_at: string | null;
    on_hold_reason: string | null;
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
};

export type ConversationMessagePreview = {
    id: number;
    direction: MessageDirection;
    type: MessageTypeValue;
    body: string | null;
    status: MessageStatusValue;
    automatic: boolean;
    sent_at: string | null;
};

export type ConversationSort = 'priority' | 'recent' | 'oldest';

export type ConversationList = {
    data: ConversationSummary[];
    has_more: boolean;
    limit: number;
};

/**
 * Views of the inbox: every authorized conversation (team inboxes), the
 * viewer's own ones, and the pending ones waiting to be accepted in the
 * viewer's departments (personal inboxes).
 */
export type ConversationInboxView = 'all' | 'mine' | 'queue';

export type ConversationInbox = {
    /** The viewer's reach does not go beyond their own assignments. */
    personal: boolean;
    /** Views the viewer may open; the first one is the default. */
    views: ConversationInboxView[];
};

export type AgentAvailabilityValue = 'available' | 'unavailable';

export type ViewerAvailability = {
    value: AgentAvailabilityValue;
    label: string;
    options: { value: string; label: string }[];
};

export type ConversationFilters = {
    view: ConversationInboxView;
    status: ConversationStatusFilter;
    department: string | null;
    agent: string | null;
    connection: number | null;
    search: string;
    tag: number | null;
    sort: ConversationSort;
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
    sender?: { id: number; name: string } | null;
    error_code: string | null;
    error_message: string | null;
    automatic: boolean;
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
};

export type ConversationNote = {
    id: number;
    body: string;
    author?: { id: number; name: string } | null;
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
    created_at: string;
};

/** One page (or update) of the chat: messages, notes and events. */
export type ConversationTimelinePage = {
    messages: ConversationMessage[];
    notes: ConversationNote[];
    events: ConversationEvent[];
    has_more: boolean;
};

export type ConversationDetails = {
    contact: {
        id: number;
        name: string;
        profile_name: string | null;
        phone_e164: string | null;
        email: string | null;
        first_interaction_at: string | null;
        last_interaction_at: string | null;
        tags: TagOption[];
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

export type ComposerSettings = {
    body_max: number;
    caption_max: number;
    note_max: number;
    attachments: {
        type: 'image' | 'document' | 'audio' | 'video';
        label: string;
        mimes: string[];
        max_bytes: number;
    }[];
};

/** WhatsApp's 24 hour customer service window, computed by the server in UTC. */
export type CustomerWindow = {
    status: 'none' | 'open' | 'expiring' | 'expired';
    label: string;
    last_inbound_at: string | null;
    expires_at: string | null;
    remaining_seconds: number;
    allows_free_form: boolean;
    block_reason: string | null;
};

export type TransferAgentOption = {
    id: number;
    name: string;
    availability: AgentAvailabilityValue;
    load: number;
};

export type TransferOptions = {
    departments: { id: number; name: string; agents: TransferAgentOption[] }[];
    unrouted_agents: TransferAgentOption[];
};

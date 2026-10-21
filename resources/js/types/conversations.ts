import type { ColorSwatch } from './admin';

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
        color: ColorSwatch;
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
    can: ConversationAbilities;
};

export type ConversationFilters = {
    status: ConversationStatusFilter;
    department: string | null;
    agent: string | null;
    connection: number | null;
    search: string;
    mine: boolean;
};

export type ConversationMessage = {
    id: number;
    direction: 'inbound' | 'outbound';
    type: string;
    body: string | null;
    status: string;
    sender?: { id: number; name: string } | null;
    error_message: string | null;
    sent_at: string | null;
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
    created_at: string;
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
    availability: 'available' | 'unavailable';
    load: number;
};

export type TransferOptions = {
    departments: { id: number; name: string; agents: TransferAgentOption[] }[];
    unrouted_agents: TransferAgentOption[];
};

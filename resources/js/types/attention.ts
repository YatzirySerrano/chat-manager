import type { ColorSwatch } from '@/types/admin';

export type AttentionAlertPriority = 'info' | 'important' | 'urgent';

export type AttentionAlertSound =
    | 'priority'
    | 'chime'
    | 'bell'
    | 'pulse'
    | 'siren'
    | 'none';

export type AttentionAlertAudience = 'individual' | 'selection' | 'everyone';

/** One agent of «Seguimiento de atención» (AttentionOverview::agents). */
export type AttentionAgent = {
    id: number;
    name: string;
    email: string;
    role: string | null;
    departments: { id: number; name: string }[];
    in_attention: number;
    /** Customers waiting for this agent's answer. */
    awaiting: number;
    /** Conversations where the agent answered last. */
    waiting_customer: number;
    /** Awaiting longer than the tolerance. */
    overdue: number;
    oldest_waiting_since: string | null;
    /** The control of new conversations applies and they are blocked. */
    blocked: boolean;
    /** Latest request of their sessions (null when unknown). */
    last_activity_at: string | null;
    last_login_at: string | null;
};

export type AttentionWaitingConversation = {
    id: number;
    contact: string;
    agent: { id: number; name: string | null } | null;
    department: { id: number; name: string; color: ColorSwatch } | null;
    waiting_since: string | null;
    overdue: boolean;
    url: string;
};

/** An alert of the history (AttentionOverview::presentAlert). */
export type AttentionAlertSummary = {
    id: number;
    title: string;
    body: string;
    priority: AttentionAlertPriority;
    priority_label: string;
    audience: AttentionAlertAudience;
    audience_label: string;
    color: ColorSwatch;
    sound: AttentionAlertSound;
    link_pending: boolean;
    sender: string;
    sent_at: string | null;
    recipients_count: number;
    delivered_count: number;
    acknowledged_count: number;
};

export type AttentionAlertRecipient = {
    id: number;
    name: string | null;
    delivered_at: string | null;
    acknowledged_at: string | null;
};

/** One of the signed-in person's alerts (AttentionInbox). */
export type MyAttentionAlert = {
    /** Recipient row id: what «Entendido» acknowledges. */
    id: number;
    alert_id: number;
    title: string;
    body: string;
    priority: AttentionAlertPriority;
    priority_label: string;
    color: ColorSwatch;
    /** Resolved: never 'priority'. */
    sound: Exclude<AttentionAlertSound, 'priority'>;
    action: 'pending_conversations' | null;
    sender: string;
    sent_at: string | null;
    delivered_at: string | null;
    acknowledged_at: string | null;
};

export type AttentionOption<T extends string = string> = {
    value: T;
    label: string;
    description?: string;
};

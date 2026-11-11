import type { AppColor } from './admin';
import type { ConversationSla } from './conversations';

/** Filter value of the SLA monitor. */
export type SlaMonitorState = 'on_time' | 'warning' | 'breached' | 'unmeasured';

type SlaNamed = { id: number; name: string };
type SlaColored = { id: number; name: string; color: AppColor };

/** One open response cycle (a customer waiting) in the SLA monitor. */
export type SlaMonitorRow = {
    id: number;
    conversation_id: number;
    contact: string;
    agent: { id: number | null; name: string | null } | null;
    department: SlaColored | null;
    category: SlaColored | null;
    connection: (SlaNamed & { channel_label: string }) | null;
    status: string | null;
    status_label: string | null;
    /** Null: no objective applied when the wait started (not measured). */
    sla: ConversationSla | null;
    kind_label: string;
    started_at: string;
    url: string;
};

export type SlaMonitorData = {
    summary: Record<SlaMonitorState, number>;
    rows: SlaMonitorRow[];
    page: number;
    per_page: number;
    total: number;
    last_page: number;
    /** Whether any response objective exists (platform, department or category). */
    configured: boolean;
};

/** One breach of the history, with what happened afterwards. */
export type SlaBreachRow = {
    id: number;
    conversation_id: number;
    contact: string;
    kind: 'first_response' | 'response';
    kind_label: string;
    target_seconds: number | null;
    business_hours_only: boolean;
    started_at: string;
    due_at: string | null;
    breached_at: string | null;
    /** Null: it was waiting in a queue, without a responsible agent. */
    responsible: string | null;
    department: SlaColored | null;
    category: SlaColored | null;
    exceeded_seconds: number | null;
    outcome: 'open' | 'answered' | 'closed' | null;
    responded_at: string | null;
    responder: string | null;
    ended_at: string | null;
    url: string;
};

export type SlaBreachHistory = {
    rows: SlaBreachRow[];
    page: number;
    per_page: number;
    total: number;
    last_page: number;
    from: string;
    to: string;
};

export type SlaMonitorFilters = {
    state: SlaMonitorState | null;
    department: number | null;
    category: number | null;
    agent: number | null;
    connection: number | null;
    page: number;
    from: string;
    to: string;
    history_page: number;
};

export type SlaMonitorOptions = {
    departments: SlaNamed[];
    categories: SlaColored[];
    agents: SlaNamed[];
    connections: SlaNamed[];
    states: { value: SlaMonitorState; label: string }[];
};

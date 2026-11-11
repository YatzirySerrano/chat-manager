import type {
    ActivityGranularity,
    AnalyticsActivity,
    ChannelChartRow,
} from './charts';

export * from './charts';

export type AnalyticsPreset =
    | 'today'
    | 'yesterday'
    | '7d'
    | '30d'
    | '90d'
    | 'month'
    | 'previous_month'
    | 'custom';

/**
 * Filters as they live in the query string (defaults omitted).
 */
export type AnalyticsQuery = {
    period?: AnalyticsPreset;
    from?: string;
    to?: string;
    department?: number;
    agent?: number;
    connection?: number;
    category?: number;
    status?: 'pending' | 'open' | 'closed';
    channel?: string;
    granularity?: AnalyticsGranularity;
};

export type AnalyticsGranularity = 'day' | 'week' | 'month';

export type AnalyticsPeriod = {
    preset: AnalyticsPreset;
    from: string;
    to: string;
    label: string;
    timezone: string;
    granularity: AnalyticsGranularity;
    /** Granularities the viewer may choose for this period. */
    granularities: AnalyticsGranularity[];
    previous: { from: string; to: string; label: string };
    /**
     * What the figures are compared against: the previous period, cut to
     * the same elapsed portion while the current one is in progress.
     */
    comparison: { label: string; partial: boolean; until: string | null };
};

export type AnalyticsOption = { id: number; name: string };

export type AnalyticsOptions = {
    departments: AnalyticsOption[];
    agents: AnalyticsOption[];
    connections: AnalyticsOption[];
    categories?: AnalyticsOption[];
    channels?: { id: string; name: string }[];
    statuses?: { id: string; name: string }[];
};

export type AnalyticsComparable = {
    value: number | null;
    previous: number | null;
};

export type AnalyticsDuration = AnalyticsComparable & { samples: number };

/**
 * Compliance against a configured service level objective (business time).
 * Null in the summary when no objective is configured.
 */
export type AnalyticsSla = {
    target_seconds: number;
    met: number;
    samples: number;
    rate: number | null;
    previous_rate: number | null;
};

export type AnalyticsRange = { min: number | null; max: number | null };

export type AnalyticsSummary = {
    received: AnalyticsComparable;
    /** Conversations whose first closing happened in the period (whenever they arrived). */
    closed_in_period: AnalyticsComparable;
    first_response_range: AnalyticsRange;
    resolution_range: AnalyticsRange;
    attended: AnalyticsComparable;
    responded: AnalyticsComparable;
    resolved: AnalyticsComparable;
    reopened: AnalyticsComparable;
    transfers: AnalyticsComparable;
    out_of_hours: AnalyticsComparable;
    out_of_hours_measurable: number;
    first_attention_seconds: AnalyticsDuration;
    first_response_seconds: AnalyticsDuration;
    resolution_seconds: AnalyticsDuration;
    first_response_business_seconds: AnalyticsDuration;
    resolution_business_seconds: AnalyticsDuration;
    first_response_sla: AnalyticsSla | null;
    resolution_sla: AnalyticsSla | null;
    messages_in: AnalyticsComparable;
    messages_out: AnalyticsComparable;
    failed_out: AnalyticsComparable;
    failure_rate: AnalyticsComparable;
};

export type AnalyticsBreakdownRow = {
    id: number | null;
    name: string;
    received: number;
    attended: number;
    responded: number;
    resolved: number;
    first_attention_seconds: number | null;
    first_response_seconds: number | null;
    /** Conversations with a measured first human response (base of the average). */
    first_response_samples: number;
    resolution_seconds: number | null;
    transfers: number;
    reopened: number;
    messages_in: number;
    messages_out: number;
    failed_out: number;
    out_of_hours: number;
    current_load: number | null;
};

export type AnalyticsTrendPoint = {
    key: string;
    label: string;
    received: number;
    resolved: number;
    previous_received: number;
};

export type OperationalAnalytics = {
    summary: AnalyticsSummary;
    current: {
        pending: number;
        /** In attention now (legacy on_hold rows included). */
        open: number;
        /** Active agents (active account and role able to attend). */
        agents: number | null;
    };
    statuses: { status: string; label: string; count: number }[];
    trend: { granularity: AnalyticsGranularity; points: AnalyticsTrendPoint[] };
    /** Received/attended and first response per hour (one-day periods), day or month. */
    activity: AnalyticsActivity;
    heatmap: { max: number; cells: number[][] };
    by_agent: AnalyticsBreakdownRow[];
    by_department: AnalyticsBreakdownRow[];
    by_connection: AnalyticsBreakdownRow[];
    by_category: AnalyticsBreakdownRow[];
    backlog: AnalyticsBacklog;
    response_trend: ResponseTrend;
    sla: SlaCompliance;
    productivity: AgentProductivity;
    /** Derived from by_connection; channels without conversations left out. */
    by_channel: ChannelChartRow[];
};

/** Unattended conversations accumulated at the end of each bucket (null = bucket not finished). */
export type AnalyticsBacklog = {
    now: number;
    start: number;
    end: number | null;
    granularity: AnalyticsGranularity;
    points: {
        key: string;
        label: string;
        received: number;
        left: number;
        backlog: number | null;
    }[];
};

/** Average first human response per bucket next to the aligned bucket of the previous period. */
export type ResponseTrend = {
    granularity: ActivityGranularity;
    points: {
        key: string;
        label: string;
        seconds: number | null;
        samples: number;
        previous_seconds: number | null;
        previous_samples: number;
    }[];
};

export type SlaTotals = {
    cycles: number;
    met: number;
    breached: number;
    in_progress: number;
    closed_unanswered: number;
    unmeasured: number;
    /** Judged cycles: met + breached (the base of the rate). */
    samples: number;
    /** Percent (0–100); null without judged cycles — never shown as compliance. */
    rate: number | null;
};

export type SlaGroupRow = {
    id: number | null;
    name: string;
    met: number;
    breached: number;
    in_progress: number;
    samples: number;
    rate: number | null;
    /** Average overrun of the cycles whose objective counts attention hours only. */
    exceeded_business_seconds: number | null;
    /** Average overrun of the cycles whose objective counts calendar time. */
    exceeded_calendar_seconds: number | null;
};

/** Service level from the response cycles (objectives frozen per cycle). */
export type SlaCompliance = {
    /** Local date of the first recorded cycle; null when there is none yet. */
    available_since: string | null;
    /** False when the period starts before the first recorded cycle. */
    covers_period: boolean;
    totals: SlaTotals;
    previous: { rate: number | null; samples: number };
    kinds: (SlaTotals & { kind: string; label: string })[];
    /** Time between a customer message and its answer (answered cycles). */
    response_time: {
        avg: number | null;
        min: number | null;
        max: number | null;
        samples: number;
    };
    trend: {
        granularity: AnalyticsGranularity;
        points: {
            key: string;
            label: string;
            met: number;
            breached: number;
            rate: number | null;
        }[];
    };
    by_department: SlaGroupRow[];
    by_category?: SlaGroupRow[];
};

export type AgentProductivityRow = {
    /** Null for a protected account shown as «Administración del sistema». */
    id: number | null;
    name: string;
    assigned: number;
    attended: number;
    closes: number;
    closed_conversations: number;
    replies: number;
    first_responses: number;
    first_response_seconds: number | null;
    handling_seconds: number | null;
    handling_samples: number;
    transfers_in: number;
    transfers_out: number;
    pending_now: number;
    overdue_now: number;
    sla_met: number;
    sla_breached: number;
    sla_late_answers: number;
    sla_samples: number;
    sla_rate: number | null;
};

export type AgentWorkload = {
    rows: {
        id: number | null;
        name: string;
        open: number;
        pending: number;
        overdue: number;
    }[];
    unassigned: number;
    unassigned_overdue: number;
};

export type AgentProductivity = {
    rows: AgentProductivityRow[];
    /** Distinct conversations attended behind the rows. */
    conversations: number;
    /** False while conversations of the period still lack their per-person rows (analytics not rebuilt yet): figures are partial. */
    complete: boolean;
    evolution: {
        granularity: AnalyticsGranularity;
        buckets: { key: string; label: string }[];
        agents: {
            id: number | null;
            name: string;
            attended: number[];
            closes: number[];
        }[];
    };
    workload: AgentWorkload;
};

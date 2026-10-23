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
};

export type AnalyticsPeriod = {
    preset: AnalyticsPreset;
    from: string;
    to: string;
    label: string;
    timezone: string;
    granularity: 'day' | 'month';
    previous: { from: string; to: string; label: string };
};

export type AnalyticsOption = { id: number; name: string };

export type AnalyticsOptions = {
    departments: AnalyticsOption[];
    agents: AnalyticsOption[];
    connections: AnalyticsOption[];
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

export type AnalyticsSummary = {
    received: AnalyticsComparable;
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
        open: number;
        on_hold: number;
        available_agents: number | null;
        agents: number | null;
    };
    statuses: { status: string; label: string; count: number }[];
    trend: { granularity: 'day' | 'month'; points: AnalyticsTrendPoint[] };
    heatmap: { max: number; cells: number[][] };
    by_agent: AnalyticsBreakdownRow[];
    by_department: AnalyticsBreakdownRow[];
    by_connection: AnalyticsBreakdownRow[];
};

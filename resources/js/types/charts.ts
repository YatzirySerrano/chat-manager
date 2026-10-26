import type { ConnectionChannel } from './connections';

/**
 * Chart data of the dashboard (`charts` deferred prop, group "charts") and
 * of the reports payload (`analytics.activity`, `analytics.by_channel`).
 * Built on the server (DashboardMetrics::chartsFor, OperationalAnalytics);
 * the client only draws it.
 */

export type ActivityGranularity = 'hour' | 'day' | 'month';

export type ActivityPoint = {
    /** "2026-10-21T09:00" (hour), "2026-10-21" (day) or "2026-10" (month), platform time zone. */
    key: string;
    /** "09:00", "21 oct" or "oct 26". */
    label: string;
    /** Conversations received in the bucket (0 when none). */
    received: number;
    /** Of the ones received in the bucket, how many were accepted/assigned. */
    attended: number;
    /** Average first human response; null when no response was measured (break the line, never draw 0). */
    first_response_seconds: number | null;
    first_response_samples: number;
};

/** Every bucket of the period, in order (counts of 0 included). Per hour when the period lasts one day. */
export type AnalyticsActivity = {
    granularity: ActivityGranularity;
    points: ActivityPoint[];
};

export type ChannelChartRow = {
    /** Null only if the connection record could not be resolved (label «Canal no disponible»). */
    channel: ConnectionChannel | null;
    label: string;
    received: number;
    attended: number;
};

/** Agent bar: top 8 by received + one «Otros» row (key "others") summing the rest. */
export type AgentChartRow = {
    /** Agent id as text, "none" for «Sin agente», "others" for «Otros». */
    key: string;
    id: number | null;
    name: string;
    received: number;
    attended: number;
    first_response_seconds: number | null;
    first_response_samples: number;
};

/** Department bar: top 8 by received + one «Otros» row (key "others") summing the rest. */
export type DepartmentChartRow = {
    /** Department id as text, "none" for «Sin departamento», "others" for «Otros». */
    key: string;
    id: number | null;
    name: string;
    received: number;
    resolved: number;
    first_response_seconds: number | null;
    first_response_samples: number;
};

export type StatusChartRow = { status: string; label: string; count: number };

/**
 * `null` = the user may not see it (or no channel has traffic yet): do not
 * render the card. `[]` / buckets with 0 = no data in the period (empty state).
 */
export type DashboardCharts = {
    activity: AnalyticsActivity | null;
    /** Only channels with conversations, most received first. */
    channels: ChannelChartRow[] | null;
    statuses: StatusChartRow[] | null;
    agents: AgentChartRow[] | null;
    departments: DepartmentChartRow[] | null;
};

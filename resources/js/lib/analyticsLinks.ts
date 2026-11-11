import { index as conversationsIndex } from '@/routes/conversations';
import type { AnalyticsQuery } from '@/types';

/**
 * Drill-down from a figure to the inbox list behind it. Only the filters
 * the inbox can really apply are carried over (connection, channel,
 * category, department, agent, status); a period cannot be, so links are
 * offered only on LIVE figures (what is pending or assigned right now).
 */
type InboxOverrides = {
    status?: 'active' | 'pending' | 'open' | 'closed' | 'all';
    department?: number | 'none' | null;
    agent?: number | 'none' | null;
    category?: number | null;
};

export function inboxLink(
    filters: AnalyticsQuery | undefined,
    overrides: InboxOverrides = {},
): string {
    const query: Record<string, string | number> = { view: 'all' };
    const department = overrides.department ?? filters?.department;
    const agent = overrides.agent ?? filters?.agent;
    const category = overrides.category ?? filters?.category;

    if (overrides.status) {
        query.status = overrides.status;
    }

    if (department !== undefined && department !== null) {
        query.department = department;
    }

    if (agent !== undefined && agent !== null) {
        query.agent = agent;
    }

    if (category !== undefined && category !== null) {
        query.category = category;
    }

    if (filters?.connection !== undefined) {
        query.connection = filters.connection;
    }

    if (filters?.channel !== undefined) {
        query.channel = filters.channel;
    }

    return conversationsIndex.url({ query });
}

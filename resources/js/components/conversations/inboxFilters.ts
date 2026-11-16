import type {
    ConversationSearchScope,
    ConversationSort,
    ConversationStatusFilter,
    InboxTagMatch,
    InboxView,
} from '@/types';

/**
 * Filters of the inbox list as the screen edits them; InboxList turns them
 * into the query string (the URL stays the source of truth). Empty strings
 * mean "every option".
 */
export type InboxFilterState = {
    view: InboxView;
    status: ConversationStatusFilter;
    department: string;
    agent: string;
    connection: string;
    channel: string;
    tags: number[];
    tagMatch: InboxTagMatch;
    sort: ConversationSort;
    searchIn: ConversationSearchScope;
    unread: boolean;
    /** The customer wrote last and nobody answered yet (`awaiting`). */
    awaiting: boolean;
    /** Request category id, as a string (`category`). */
    category: string;
    /** `ready`, `needs_info` or empty for both (`readiness`). */
    readiness: string;
};

/** Tags one filter may combine (ConversationInboxQuery::MAX_TAGS). */
export const MAX_FILTER_TAGS = 10;

/** The closed statuses of the filter: every closed one, or the completed ones. */
export const isClosedStatus = (status: ConversationStatusFilter): boolean =>
    status === 'closed' || status === 'completed';

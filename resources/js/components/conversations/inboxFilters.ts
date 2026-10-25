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
};

/** Tags one filter may combine (ConversationInboxQuery::MAX_TAGS). */
export const MAX_FILTER_TAGS = 10;

import type { AppColor } from './admin';
import type { ViewerChatAppearance } from './personalization';

/**
 * Internal (employee) chat, independent from customer conversations.
 * Shapes mirror App\Services\TeamChat\TeamChatPresenter and the
 * TeamChat controllers.
 */

export type TeamThreadType = 'direct' | 'group';

export type TeamParticipantRole = 'member' | 'admin';

export type TeamThreadFilter =
    | 'all'
    | 'unread'
    | 'direct'
    | 'groups'
    | 'archived';

/** A person as shown in the chat ("Usuario eliminado" when deleted). */
export type TeamPerson = {
    id: number;
    name: string;
    deleted: boolean;
};

export type TeamMessagePreview = {
    id: number;
    text: string;
    author: string | null;
    deleted: boolean;
    system: boolean;
    created_at: string | null;
};

export type TeamThreadSummary = {
    id: number;
    type: TeamThreadType;
    title: string;
    partner: TeamPerson | null;
    participants_count: number;
    archived: boolean;
    muted: boolean;
    unread: number;
    last_activity_at: string | null;
    last_message: TeamMessagePreview | null;
};

export type TeamThreadList = {
    threads: TeamThreadSummary[];
    has_more: boolean;
};

export type TeamParticipant = TeamPerson & {
    role: TeamParticipantRole;
    is_me: boolean;
    joined_at: string;
    last_read_message_id: number | null;
};

export type TeamThreadDetail = {
    id: number;
    type: TeamThreadType;
    title: string;
    name: string | null;
    description: string | null;
    archived: boolean;
    archived_at: string | null;
    created_at: string | null;
    last_message_id: number | null;
    partner: TeamPerson | null;
    participants: TeamParticipant[];
    me: {
        role: TeamParticipantRole | null;
        muted: boolean;
        last_read_message_id: number | null;
    };
    can: {
        post: boolean;
        manage: boolean;
        archive: boolean;
        unarchive: boolean;
        leave: boolean;
    };
    post_blocked_reason: string | null;
    /** The viewer's own chat colors for this thread. */
    appearance?: ViewerChatAppearance;
};

export type TeamAttachment = {
    id: number;
    name: string;
    mime_type: string;
    kind: 'image' | 'document' | 'audio' | 'video';
    size_bytes: number;
    previewable: boolean;
    download_url: string;
    preview_url: string | null;
};

export type TeamMessage = {
    id: number;
    kind: 'message' | 'system';
    body: string | null;
    system_text: string | null;
    author: { id: number | null; name: string; deleted: boolean } | null;
    is_mine: boolean;
    deleted: boolean;
    edited: boolean;
    created_at: string | null;
    reply_to: {
        id: number;
        author: string;
        excerpt: string;
        deleted: boolean;
    } | null;
    /** Current participants mentioned (@) in a group message. */
    mentions: TeamMention[];
    reactions: TeamReaction[];
    attachments: TeamAttachment[];
    can: { edit: boolean; delete: boolean };
};

export type TeamMention = { id: number; name: string };

/** Reactions of one emoji ("Tú" stands for the viewer in names). */
export type TeamReaction = {
    emoji: string;
    count: number;
    mine: boolean;
    names: string[];
};

export type TeamMessagePage = {
    data: TeamMessage[];
    has_more_before: boolean;
    has_more_after: boolean;
};

export type TeamChatLimits = {
    messageMaxLength: number;
    maxFiles: number;
    maxKilobytes: number;
    accept: string;
    groupMaxParticipants: number;
    /** Most chats the list loads at once (then: search or filters). */
    maxThreads: number;
    maxMentions: number;
    /** Allowed reactions. */
    reactions: string[];
    /** This server's PHP upload limits (null: no limit), with their explanation. */
    server?: {
        max_file_bytes: number | null;
        max_request_bytes: number | null;
        upload_max_filesize: string;
        post_max_size: string;
        message: string;
    };
};

export type TeamChatFilters = {
    search: string;
    filter: TeamThreadFilter;
    limit: number;
};

/** Someone the viewer may start a chat with or add to a group. */
export type TeamContact = {
    id: number;
    name: string;
    departments: { id: number; name: string; color: AppColor }[];
    already_participant: boolean;
};

export type TeamSearchResult = {
    thread_id: number;
    thread_title: string;
    thread_type: TeamThreadType;
    message_id: number;
    author: string;
    excerpt: string;
    created_at: string | null;
};

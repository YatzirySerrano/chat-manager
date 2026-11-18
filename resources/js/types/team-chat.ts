import type { FileSaveOptions, VideoSaveOptions } from '@/lib/videoExports';
import type { AppColor } from './admin';
import type { ViewerChatAppearance } from './personalization';

/**
 * Internal (employee) chat, independent from customer conversations.
 * Shapes mirror App\Services\TeamChat\TeamChatPresenter and the
 * TeamChat controllers.
 */

export type TeamThreadType = 'direct' | 'group' | 'desk';

/** State of a «Mesa de control» request («new»: nobody wrote yet). */
export type TeamDeskStatus = 'new' | 'pending' | 'open' | 'closed';

/** Request of a desk thread, as the viewer may see it (TeamChatPresenter::desk()). */
export type TeamDeskInfo = {
    status: TeamDeskStatus;
    status_label: string;
    /** The viewer is the requester (she never sees the auditors' names here). */
    is_requester: boolean;
    requester: TeamPerson | null;
    assignee: TeamPerson | null;
    /** The viewer attends this open request. */
    is_mine: boolean;
    requested_at: string | null;
    accepted_at: string | null;
    completed_at: string | null;
    completed_by: string | null;
};

export type TeamDeskTab = 'pending' | 'mine' | 'completed';

/** Who the viewer is for the desk: null when the desk does not concern them. */
export type TeamDeskRole = {
    role: 'requester' | 'auditor';
    /** The requester's own desk thread. */
    thread_id: number | null;
    /** The auditor's open desk section tab (null: the regular chats). */
    tab: TeamDeskTab | null;
} | null;

export type TeamDeskRow = {
    id: number;
    title: string;
    unread: number;
    last_activity_at: string | null;
    last_message: TeamMessagePreview | null;
    desk: TeamDeskInfo;
};

/** The auditor's «Mesa de control» section (DeskInboxQuery). */
export type TeamDeskInbox = {
    tab: TeamDeskTab;
    threads: TeamDeskRow[];
    has_more: boolean;
    counts: { pending: number; mine: number; completed: number };
    capacity: { limit: number | null; load: number };
};

export type TeamParticipantRole = 'member' | 'admin';

export type TeamThreadFilter =
    | 'all'
    | 'unread'
    | 'mentions'
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
    desk?: TeamDeskInfo | null;
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
        /** «Aceptar solicitud» on a pending desk request. */
        acceptDesk?: boolean;
        /** «Solicitud completa» on the viewer's open desk request. */
        completeDesk?: boolean;
    };
    post_blocked_reason: string | null;
    desk?: TeamDeskInfo | null;
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
    /** Small preview of an image for the bubble (the original when none can be made). */
    thumb_url?: string | null;
    /** Audio and video played in place (served with byte ranges). */
    stream_url?: string | null;
    /** «Guardar archivo» (videos.save-to-nas): absent or null when the viewer may not save it. */
    file_save?: FileSaveOptions | null;
    /** The original «Guardar video» options (videos only; same shape as file_save). */
    video_save?: VideoSaveOptions | null;
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
    /** Scope of the list; the legacy 'unread' and 'mentions' arrive as toggles. */
    filter: TeamThreadFilter;
    /** Only threads with messages unread for the viewer. */
    unread: boolean;
    /** Only threads with unread messages that mention the viewer. */
    mentions: boolean;
    limit: number;
};

/** Numbers of the quick filters of the thread list (non archived threads). */
export type TeamThreadCounts = {
    /** Threads with messages unread for the viewer. */
    unread: number;
    /** Threads with unread messages that mention the viewer. */
    mentions: number;
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

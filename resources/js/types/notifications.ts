export type NotificationLevel = 'urgent' | 'informative';

export type NotificationKindValue =
    | 'conversation.pending'
    | 'conversation.assigned'
    | 'conversation.transferred'
    | 'conversation.message'
    | 'team-chat.mention'
    | 'team-chat.message'
    | 'export.finished'
    | 'import.finished'
    | 'connection.problem'
    | 'account.access-changed';

/** How the user wants to receive one kind of notification. */
export type NotificationPreference = {
    in_app: boolean;
    browser: boolean;
    sound: boolean;
};

/**
 * A notification as the server renders it for the viewer: its text is built
 * at read time after checking access again (`accessible` false means the
 * viewer can no longer open the record and no detail is given).
 */
export type AppNotification = {
    id: string;
    kind: NotificationKindValue;
    level: NotificationLevel;
    title: string;
    body: string | null;
    url: string | null;
    accessible: boolean;
    occurrences: number;
    read_at: string | null;
    created_at: string | null;
    updated_at: string | null;
    conversation_id: number | null;
};

export type NotificationUnread = { urgent: number; total: number };

export type NotificationListResponse = {
    data: AppNotification[];
    unread: NotificationUnread;
    preferences: Record<NotificationKindValue, NotificationPreference>;
    next_before: string | null;
    next_before_id: string | null;
};

export type NotificationKindOption = {
    value: NotificationKindValue;
    label: string;
    description: string;
    level: NotificationLevel;
    level_label: string;
    preferences: NotificationPreference;
};

/** Read-only operating context shown on a department page. */
export type DepartmentAutomation = {
    hours: {
        mode: 'department' | 'platform' | 'continuous';
        is_open: boolean;
        next_opening: string | null;
        holiday: string | null;
        timezone: string;
    };
    holidays: { date: string; label: string; recurring: boolean }[];
    load_limit: number | null;
    /** The department's own per agent limit; takes precedence over load_limit. */
    department_limit: number | null;
    settings_url: string | null;
};

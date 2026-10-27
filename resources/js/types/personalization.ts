/** One set of personal chat colors (#RRGGBB). */
export type ChatColorSet = {
    sent_bg: string;
    sent_text: string;
    received_bg: string;
    received_text: string;
    chat_bg: string;
};

export type ChatColorKey = keyof ChatColorSet;

/** Light and dark sets: the chat follows the current light/dark mode. */
export type ChatColors = { light: ChatColorSet; dark: ChatColorSet };

export type ChatAppearancePresetOption = {
    value: string;
    label: string;
    colors: ChatColors;
};

/** One stored choice of the viewer (their default or one conversation's). */
export type ChatAppearanceChoice = {
    id: number;
    preset: string | null;
    preset_label: string | null;
    colors: ChatColors | null;
    updated_at: string | null;
};

/**
 * The chat whose colors are edited: a customer conversation or an internal
 * (team chat) thread. The user's default is shared by both kinds.
 */
export type ChatAppearanceTarget = {
    type: 'conversation' | 'team_thread';
    id: number;
};

/** The viewer's own chat colors for the open chat (conversation or thread). */
export type ViewerChatAppearance = {
    /**
     * Where the effective colors come from: "conversation" = the open
     * chat's own choice (also for a thread), "system" = platform theme.
     */
    source: 'conversation' | 'default' | 'system';
    preset: string | null;
    colors: ChatColors | null;
    default: ChatAppearanceChoice | null;
    /** The open chat's own choice, whatever its kind. */
    conversation: ChatAppearanceChoice | null;
    presets: ChatAppearancePresetOption[];
};

export type ChatAppearanceResponse = {
    appearance: ViewerChatAppearance;
    /** Set when a text color was adjusted to stay readable. */
    notice: string | null;
};

export type ContactAliasResponse = {
    contact_id: number;
    alias: string | null;
    display_name: string;
    name: string;
};

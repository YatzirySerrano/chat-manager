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

/** The viewer's own chat colors for the open conversation. */
export type ViewerChatAppearance = {
    /** Where the effective colors come from; "system" = platform theme. */
    source: 'conversation' | 'default' | 'system';
    preset: string | null;
    colors: ChatColors | null;
    default: ChatAppearanceChoice | null;
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

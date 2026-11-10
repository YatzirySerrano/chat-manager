import type { ViewerChatAppearance } from '@/types';

/** What a preview shows of a file, never the file itself. */
export type ChatPreviewAttachment = {
    kind: 'image' | 'sticker' | 'audio' | 'video' | 'document' | 'other';
    label: string;
};

/**
 * One message of a chat preview, already reduced to safe text: the
 * conversations and the internal chat map their own messages to it.
 */
export type ChatPreviewItem = {
    id: number;
    /** Who wrote it, as the viewer sees them. */
    author: string;
    /** Written by the viewer's side (the team, or the viewer in the internal chat). */
    mine: boolean;
    text: string | null;
    attachments: ChatPreviewAttachment[];
    at: string | null;
    /** An automatic message, a system line or a deleted message. */
    note?: string | null;
    /** A deleted message keeps the theme's look, as in the chat. */
    deleted?: boolean;
};

/**
 * The viewer's effective personal colors for the previewed chat (the same
 * ones the chat shows them), without the editor's choices and presets.
 */
export type ChatPreviewAppearance = Pick<
    ViewerChatAppearance,
    'source' | 'colors'
>;

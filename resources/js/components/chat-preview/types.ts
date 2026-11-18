import type { ViewerChatAppearance } from '@/types';

/**
 * What a preview shows of a file: its name and, for images, video and
 * audio the viewer may already open in the chat, the file itself.
 */
export type ChatPreviewAttachment = {
    kind: 'image' | 'sticker' | 'audio' | 'video' | 'document' | 'other';
    label: string;
    /** Where the image (its thumbnail), video or audio loads from; absent: only the name is shown. */
    src?: string | null;
    /** The full file, opened in a new tab from an image. */
    href?: string | null;
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
    /** WhatsApp formatting (*bold*, _italic_…) applies to the text. */
    formatted?: boolean;
    /** A shared location, drawn as a place with «Abrir en Google Maps». */
    location?: ChatPreviewLocation | null;
    /** The first link of the text, with the endpoint of its preview card. */
    link?: { url: string; endpoint: string | null } | null;
};

/** A place shared in the chat (see LocationCard). */
export type ChatPreviewLocation = {
    name: string | null;
    address: string | null;
    latitude: number | null;
    longitude: number | null;
    live?: boolean;
    website?: string | null;
    comment?: string | null;
};

/**
 * The viewer's effective personal colors for the previewed chat (the same
 * ones the chat shows them), without the editor's choices and presets.
 */
export type ChatPreviewAppearance = Pick<
    ViewerChatAppearance,
    'source' | 'colors'
>;

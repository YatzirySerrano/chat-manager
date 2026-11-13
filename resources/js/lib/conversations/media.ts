import type { InjectionKey } from 'vue';
import type { ConversationMessage, MessageAttachment } from '@/types';

/**
 * How the files of a conversation are shown. Pure helpers (no Vue state)
 * shared by the bubble, the media viewer, the audio player and the draft of
 * the composer, so every place agrees on what is a picture, what plays and
 * what is only a document.
 */

export type MediaKind = 'image' | 'audio' | 'video' | 'pdf' | 'document';

/*
 * Types the attachment route serves inline (MessageAttachmentController::
 * INLINE); any other file is always a download, so it is a document card.
 */
const INLINE_IMAGES = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];
const INLINE_AUDIO = [
    'audio/aac',
    'audio/mp4',
    'audio/mpeg',
    'audio/amr',
    'audio/ogg',
    'audio/opus',
    'audio/wav',
    'audio/webm',
    // WhatsApp voice notes (Ogg Opus) may be detected under these names.
    'video/ogg',
    'application/ogg',
];
const INLINE_VIDEO = [
    'video/mp4',
    'video/3gpp',
    'video/webm',
    'video/quicktime',
];

/** The content type without parameters ("audio/ogg; codecs=opus" → "audio/ogg"). */
export function baseMime(mime: string | null | undefined): string {
    return (mime ?? '').split(';')[0].trim().toLowerCase();
}

export function mediaKind(mime: string | null | undefined): MediaKind {
    const type = baseMime(mime);

    if (INLINE_IMAGES.includes(type)) {
        return 'image';
    }

    if (INLINE_AUDIO.includes(type)) {
        return 'audio';
    }

    if (INLINE_VIDEO.includes(type)) {
        return 'video';
    }

    return type === 'application/pdf' ? 'pdf' : 'document';
}

/** "1:05" (or "1:02:05") for a number of seconds; "–:––" when unknown. */
export function formatClock(seconds: number | null | undefined): string {
    if (
        seconds === null ||
        seconds === undefined ||
        !Number.isFinite(seconds)
    ) {
        return '–:––';
    }

    const total = Math.max(0, Math.round(seconds));
    const hours = Math.floor(total / 3600);
    const minutes = Math.floor((total % 3600) / 60);
    const rest = String(total % 60).padStart(2, '0');

    return hours > 0
        ? `${hours}:${String(minutes).padStart(2, '0')}:${rest}`
        : `${minutes}:${rest}`;
}

/**
 * Heights (0.18–1) of the bars drawn for an audio. The real waveform is not
 * known without downloading and decoding the file, so the shape is derived
 * from a seed (the attachment id): the same audio always draws the same
 * bars, and they only serve as a progress track.
 */
export function waveformBars(seed: number, count = 36): number[] {
    let state = (Math.abs(Math.trunc(seed)) % 2147483646) + 1;
    const bars: number[] = [];

    for (let index = 0; index < count; index++) {
        state = (state * 48271) % 2147483647;
        const noise = state / 2147483647;
        // A soft envelope so it reads as speech, not as static.
        const envelope =
            0.55 + 0.45 * Math.sin((index / count) * Math.PI * 3 + (seed % 7));

        bars.push(Math.min(1, Math.max(0.18, noise * 0.75 * envelope + 0.22)));
    }

    return bars;
}

export type FileBadge = {
    /** Short label drawn on the icon ("PDF", "XLS"…). */
    label: string;
    /** Readable kind ("Hoja de cálculo"). */
    kind: string;
    tone: 'red' | 'blue' | 'green' | 'amber' | 'violet' | 'neutral';
};

const EXTENSION_BADGES: Record<string, FileBadge> = {
    pdf: { label: 'PDF', kind: 'Documento PDF', tone: 'red' },
    doc: { label: 'DOC', kind: 'Documento de Word', tone: 'blue' },
    docx: { label: 'DOC', kind: 'Documento de Word', tone: 'blue' },
    odt: { label: 'ODT', kind: 'Documento de texto', tone: 'blue' },
    rtf: { label: 'RTF', kind: 'Documento de texto', tone: 'blue' },
    txt: { label: 'TXT', kind: 'Texto', tone: 'neutral' },
    xls: { label: 'XLS', kind: 'Hoja de cálculo', tone: 'green' },
    xlsx: { label: 'XLS', kind: 'Hoja de cálculo', tone: 'green' },
    ods: { label: 'ODS', kind: 'Hoja de cálculo', tone: 'green' },
    csv: { label: 'CSV', kind: 'Datos CSV', tone: 'green' },
    ppt: { label: 'PPT', kind: 'Presentación', tone: 'amber' },
    pptx: { label: 'PPT', kind: 'Presentación', tone: 'amber' },
    zip: { label: 'ZIP', kind: 'Archivo comprimido', tone: 'violet' },
    rar: { label: 'RAR', kind: 'Archivo comprimido', tone: 'violet' },
    '7z': { label: '7Z', kind: 'Archivo comprimido', tone: 'violet' },
    mp3: { label: 'MP3', kind: 'Audio', tone: 'violet' },
    mp4: { label: 'MP4', kind: 'Video', tone: 'violet' },
};

const MIME_BADGES: [RegExp, FileBadge][] = [
    [/pdf$/u, EXTENSION_BADGES.pdf],
    [/(msword|wordprocessingml)/u, EXTENSION_BADGES.docx],
    [/(ms-excel|spreadsheetml)/u, EXTENSION_BADGES.xlsx],
    [/(ms-powerpoint|presentationml)/u, EXTENSION_BADGES.pptx],
    [/csv$/u, EXTENSION_BADGES.csv],
    [/^text\//u, EXTENSION_BADGES.txt],
    [/(zip|compressed|x-rar|x-7z)/u, EXTENSION_BADGES.zip],
    [/^audio\//u, { label: 'AUD', kind: 'Audio', tone: 'violet' }],
    [/^video\//u, { label: 'VID', kind: 'Video', tone: 'violet' }],
    [/^image\//u, { label: 'IMG', kind: 'Imagen', tone: 'blue' }],
];

/** The badge of a file card, from its name and, failing that, its type. */
export function fileBadge(
    name: string | null | undefined,
    mime: string | null | undefined,
): FileBadge {
    const extension = /\.([a-z0-9]{1,5})$/iu
        .exec(name ?? '')?.[1]
        ?.toLowerCase();

    if (extension && EXTENSION_BADGES[extension]) {
        return EXTENSION_BADGES[extension];
    }

    const type = baseMime(mime);
    const byMime = MIME_BADGES.find(([pattern]) => pattern.test(type))?.[1];

    if (byMime) {
        return byMime;
    }

    return {
        label: extension ? extension.slice(0, 4).toUpperCase() : 'DOC',
        kind: 'Archivo',
        tone: 'neutral',
    };
}

/** Classes of the icon tile of a file card, per tone (theme tokens only). */
export const FILE_TONE_CLASSES: Record<FileBadge['tone'], string> = {
    red: 'bg-destructive-soft text-destructive-soft-foreground',
    blue: 'bg-info-soft text-info-soft-foreground',
    green: 'bg-success-soft text-success-soft-foreground',
    amber: 'bg-warning-soft text-warning-soft-foreground',
    violet: 'bg-primary-soft text-primary-soft-foreground',
    neutral: 'bg-muted text-muted-foreground',
};

/**
 * Official Google Maps link of a pair of coordinates (Maps URLs, "search"
 * action): it opens the app on a phone and the site on a computer, with a
 * pin on the exact point.
 */
export function googleMapsUrl(latitude: number, longitude: number): string {
    return `https://www.google.com/maps/search/?api=1&query=${encodeURIComponent(`${latitude},${longitude}`)}`;
}

/** One file the media viewer can show, with the message it came in. */
export type ViewerItem = {
    id: number;
    kind: 'image' | 'video' | 'pdf';
    src: string;
    downloadUrl: string | null;
    name: string;
    /** Caption of the message, if any. */
    caption: string | null;
    /** Who sent it and when, for the viewer's bar. */
    author: string | null;
    at: string | null;
};

/** Whether a file of a message can be opened in the media viewer. */
export function viewerKind(
    attachment: MessageAttachment,
    message?: Pick<ConversationMessage, 'type'> | null,
): ViewerItem['kind'] | null {
    if (!attachment.available || message?.type === 'sticker') {
        return null;
    }

    const kind = mediaKind(attachment.mime_type);

    return kind === 'image' || kind === 'video' || kind === 'pdf' ? kind : null;
}

/**
 * The viewer of the chat the bubble lives in. A chat history provides it
 * with every picture, video and PDF it has loaded, so the viewer can move
 * between them; without one (a preview dialog) a file opens alone.
 */
export type MediaGallery = {
    open: (attachmentId: number) => boolean;
};

export const MEDIA_GALLERY: InjectionKey<MediaGallery> =
    Symbol('media-gallery');

/* Only one audio or video of the page sounds at a time. */
let playing: HTMLMediaElement | null = null;

export function claimPlayback(element: HTMLMediaElement): void {
    if (playing && playing !== element && !playing.paused) {
        playing.pause();
    }

    playing = element;
}

export function releasePlayback(element: HTMLMediaElement): void {
    if (playing === element) {
        playing = null;
    }
}

/** A readable name for a picture pasted from the clipboard ("image.png"). */
export function pastedFileName(file: File, at = new Date()): string {
    const generic =
        /^(image|imagen|blob|clipboard|pasted[ _-]?image)(\.[a-z0-9]+)?$/iu;

    if (file.name && !generic.test(file.name)) {
        return file.name;
    }

    const pad = (value: number): string => String(value).padStart(2, '0');
    const stamp = `${at.getFullYear()}-${pad(at.getMonth() + 1)}-${pad(at.getDate())} ${pad(at.getHours())}.${pad(at.getMinutes())}.${pad(at.getSeconds())}`;
    const extension =
        {
            'image/png': 'png',
            'image/jpeg': 'jpg',
            'image/webp': 'webp',
            'image/gif': 'gif',
        }[baseMime(file.type)] ?? 'png';

    return `Captura ${stamp}.${extension}`;
}

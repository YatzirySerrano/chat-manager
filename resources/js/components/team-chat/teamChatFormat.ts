import {
    File as FileIcon,
    FileArchive,
    FileAudio,
    FileSpreadsheet,
    FileText,
    FileVideo,
    Image as ImageIcon,
    Presentation,
} from '@lucide/vue';
import type { Component } from 'vue';
import { formatDateTime, formatFileSize, platformTimezone } from '@/lib/format';
import { serverLimitNotice } from '@/lib/uploads';
import type {
    TeamAttachment,
    TeamChatLimits,
    TeamMention,
    TeamMessage,
} from '@/types/team-chat';

/**
 * Pure helpers of the internal chat: dates of the history, text segments
 * (mentions and links, always rendered as text nodes), grouping of
 * consecutive messages, and what a file is before and after it is sent.
 * Nothing here touches the network.
 */

/* ------------------------------------------------------------------ dates */

/** Calendar day ("2026-11-13") of an instant in the platform time zone. */
export function dayKey(iso: string | null | undefined): string {
    if (!iso) {
        return '';
    }

    const date = new Date(iso);

    if (Number.isNaN(date.getTime())) {
        return '';
    }

    const format = (timeZone: string | undefined): string =>
        new Intl.DateTimeFormat('en-CA', {
            timeZone,
            year: 'numeric',
            month: '2-digit',
            day: '2-digit',
        }).format(date);

    try {
        return format(platformTimezone());
    } catch {
        return format(undefined);
    }
}

function shiftDay(key: string, days: number): string {
    const [year, month, day] = key.split('-').map(Number);
    const date = new Date(Date.UTC(year, month - 1, day + days));

    return date.toISOString().slice(0, 10);
}

/** «Hoy», «Ayer», «lunes 9 de noviembre» or the date with its year. */
export function dayLabel(iso: string | null | undefined): string {
    const key = dayKey(iso);

    if (key === '') {
        return '';
    }

    const today = dayKey(new Date().toISOString());

    if (key === today) {
        return 'Hoy';
    }

    if (key === shiftDay(today, -1)) {
        return 'Ayer';
    }

    const sameYear = key.slice(0, 4) === today.slice(0, 4);

    return formatDateTime(
        iso,
        sameYear
            ? { weekday: 'long', day: 'numeric', month: 'long' }
            : { day: 'numeric', month: 'long', year: 'numeric' },
    );
}

/** Hour of a message ("9:05 a.m."). */
export function clockTime(iso: string | null | undefined): string {
    return iso ? formatDateTime(iso, { timeStyle: 'short' }) : '';
}

/** Time of a list row: the hour today, «Ayer», the weekday or a short date. */
export function listTime(iso: string | null | undefined): string {
    const key = dayKey(iso);

    if (!iso || key === '') {
        return '';
    }

    const today = dayKey(new Date().toISOString());

    if (key === today) {
        return clockTime(iso);
    }

    if (key === shiftDay(today, -1)) {
        return 'Ayer';
    }

    if (key > shiftDay(today, -7)) {
        return formatDateTime(iso, { weekday: 'short' });
    }

    return formatDateTime(iso, {
        day: '2-digit',
        month: 'short',
        ...(key.slice(0, 4) === today.slice(0, 4) ? {} : { year: '2-digit' }),
    });
}

/** "1:05" / "1:02:07" for players. */
export function mediaClock(seconds: number): string {
    if (!Number.isFinite(seconds) || seconds < 0) {
        return '0:00';
    }

    const total = Math.floor(seconds);
    const hours = Math.floor(total / 3600);
    const minutes = Math.floor((total % 3600) / 60);
    const rest = String(total % 60).padStart(2, '0');

    return hours > 0
        ? `${hours}:${String(minutes).padStart(2, '0')}:${rest}`
        : `${minutes}:${rest}`;
}

/* --------------------------------------------------------------- grouping */

/** Messages of the same person this close together read as one block. */
const GROUP_WINDOW_MS = 5 * 60_000;

/** Whether `message` continues the block of `previous` (same author, same day, close in time). */
export function continuesBlock(
    previous: TeamMessage | undefined,
    message: TeamMessage,
): boolean {
    if (
        !previous ||
        previous.kind === 'system' ||
        message.kind === 'system' ||
        previous.author?.id !== message.author?.id ||
        previous.is_mine !== message.is_mine ||
        dayKey(previous.created_at) !== dayKey(message.created_at)
    ) {
        return false;
    }

    const gap =
        new Date(message.created_at ?? 0).getTime() -
        new Date(previous.created_at ?? 0).getTime();

    return gap >= 0 && gap <= GROUP_WINDOW_MS;
}

/* ------------------------------------------------------------------- text */

export type TextSegment =
    | { kind: 'text'; text: string }
    | { kind: 'mention'; text: string }
    | { kind: 'link'; text: string; href: string };

const URL_PATTERN = /(?:https?:\/\/|www\.)[^\s<>"«»]+/giu;
const TRAILING = '.,;:!?\'"”’)]}';

function linkSegments(text: string): TextSegment[] {
    const parts: TextSegment[] = [];
    let cursor = 0;

    for (const match of text.matchAll(URL_PATTERN)) {
        let url = match[0];
        const start = match.index ?? 0;

        // A link is not followed by a letter glued to "www." of another word.
        if (start > 0 && /[\p{L}\p{N}@]/u.test(text[start - 1])) {
            continue;
        }

        // Punctuation that ends the sentence is not part of the address,
        // except a closing parenthesis that the address itself opened.
        while (url !== '' && TRAILING.includes(url[url.length - 1])) {
            if (
                url.endsWith(')') &&
                url.split('(').length >= url.split(')').length
            ) {
                break;
            }

            url = url.slice(0, -1);
        }

        if (url.replace(/^(?:https?:\/\/|www\.)/iu, '').length < 3) {
            continue;
        }

        const href = /^https?:\/\//iu.test(url) ? url : `https://${url}`;

        try {
            // Only real web addresses become links.
            if (!['http:', 'https:'].includes(new URL(href).protocol)) {
                continue;
            }
        } catch {
            continue;
        }

        if (start > cursor) {
            parts.push({ kind: 'text', text: text.slice(cursor, start) });
        }

        parts.push({ kind: 'link', text: url, href });
        cursor = start + url.length;
    }

    if (cursor < text.length) {
        parts.push({ kind: 'text', text: text.slice(cursor) });
    }

    return parts;
}

/**
 * A message's text split into plain text, "@Name" of the people mentioned
 * and web links. Every piece is rendered as text: nothing is ever HTML.
 */
export function textSegments(
    body: string | null | undefined,
    mentions: TeamMention[] = [],
): TextSegment[] {
    const text = body ?? '';
    const tokens = mentions
        .map((mention) => `@${mention.name}`)
        .sort((first, second) => second.length - first.length);
    const parts: TextSegment[] = [];
    let rest = text;

    while (tokens.length > 0 && rest !== '') {
        const next = tokens
            .map((token) => ({ token, at: rest.indexOf(token) }))
            .filter((found) => found.at >= 0)
            .sort((first, second) => first.at - second.at)[0];

        if (!next) {
            break;
        }

        if (next.at > 0) {
            parts.push(...linkSegments(rest.slice(0, next.at)));
        }

        parts.push({ kind: 'mention', text: next.token });
        rest = rest.slice(next.at + next.token.length);
    }

    if (rest !== '') {
        parts.push(...linkSegments(rest));
    }

    return parts;
}

/** Text that is only emoji (up to three) is shown larger, without a bubble fill change. */
export function isEmojiOnly(body: string | null | undefined): boolean {
    const text = (body ?? '').trim();

    if (text === '' || text.length > 24) {
        return false;
    }

    try {
        const clusters = [
            ...new Intl.Segmenter('es', { granularity: 'grapheme' }).segment(
                text.replace(/\s+/gu, ''),
            ),
        ];

        return (
            clusters.length > 0 &&
            clusters.length <= 3 &&
            clusters.every((cluster) =>
                /\p{Extended_Pictographic}/u.test(cluster.segment),
            )
        );
    } catch {
        return false;
    }
}

/* ------------------------------------------------------------------ files */

export type FileKind = 'image' | 'video' | 'audio' | 'pdf' | 'document';

const EXTENSION_KINDS: Record<string, FileKind> = {
    jpg: 'image',
    jpeg: 'image',
    png: 'image',
    gif: 'image',
    webp: 'image',
    heic: 'image',
    heif: 'image',
    mp4: 'video',
    m4v: 'video',
    mov: 'video',
    webm: 'video',
    '3gp': 'video',
    mp3: 'audio',
    ogg: 'audio',
    oga: 'audio',
    opus: 'audio',
    m4a: 'audio',
    aac: 'audio',
    wav: 'audio',
    amr: 'audio',
    pdf: 'pdf',
};

export function extensionOf(name: string): string {
    const dot = name.lastIndexOf('.');

    return dot > 0 ? name.slice(dot + 1).toLowerCase() : '';
}

/** What a file is, from its type and its name (before it is sent). */
export function draftKind(file: File): FileKind {
    const type = file.type.toLowerCase();

    if (type === 'application/pdf') {
        return 'pdf';
    }

    if (type.startsWith('image/')) {
        return 'image';
    }

    if (type.startsWith('video/')) {
        return 'video';
    }

    if (type.startsWith('audio/')) {
        return 'audio';
    }

    return EXTENSION_KINDS[extensionOf(file.name)] ?? 'document';
}

/** Images a browser can draw from the file itself (HEIC is not one). */
export function drawableImage(file: File): boolean {
    return (
        draftKind(file) === 'image' &&
        !['heic', 'heif'].includes(extensionOf(file.name)) &&
        !/hei[cf]/iu.test(file.type)
    );
}

type FileLook = { icon: Component; label: string; tone: string };

const TONES = {
    red: 'bg-destructive-soft text-destructive-soft-foreground',
    green: 'bg-success-soft text-success-soft-foreground',
    blue: 'bg-info-soft text-info-soft-foreground',
    amber: 'bg-warning-soft text-warning-soft-foreground',
    plain: 'bg-muted text-muted-foreground',
    brand: 'bg-primary-soft text-primary-soft-foreground',
} as const;

/** Icon, short label and tone of a file by its name ("PDF", "Excel"…). */
export function fileLook(name: string, kind?: string): FileLook {
    const extension = extensionOf(name);

    switch (extension) {
        case 'pdf':
            return { icon: FileText, label: 'PDF', tone: TONES.red };
        case 'doc':
        case 'docx':
        case 'odt':
            return { icon: FileText, label: 'Word', tone: TONES.blue };
        case 'xls':
        case 'xlsx':
        case 'ods':
            return { icon: FileSpreadsheet, label: 'Excel', tone: TONES.green };
        case 'csv':
            return { icon: FileSpreadsheet, label: 'CSV', tone: TONES.green };
        case 'ppt':
        case 'pptx':
            return {
                icon: Presentation,
                label: 'PowerPoint',
                tone: TONES.amber,
            };
        case 'txt':
        case 'log':
            return { icon: FileText, label: 'Texto', tone: TONES.plain };
        case 'zip':
        case 'rar':
        case '7z':
            return { icon: FileArchive, label: 'Archivo', tone: TONES.plain };
        default:
            break;
    }

    const resolved = kind ?? EXTENSION_KINDS[extension];

    if (resolved === 'image') {
        return { icon: ImageIcon, label: 'Imagen', tone: TONES.brand };
    }

    if (resolved === 'video') {
        return { icon: FileVideo, label: 'Video', tone: TONES.brand };
    }

    if (resolved === 'audio') {
        return { icon: FileAudio, label: 'Audio', tone: TONES.brand };
    }

    return {
        icon: FileIcon,
        label: extension === '' ? 'Archivo' : extension.toUpperCase(),
        tone: TONES.plain,
    };
}

/** How each sent attachment is shown in the bubble. */
export type AttachmentGroups = {
    images: TeamAttachment[];
    videos: TeamAttachment[];
    audios: TeamAttachment[];
    files: TeamAttachment[];
};

export function groupAttachments(
    attachments: TeamAttachment[],
): AttachmentGroups {
    const groups: AttachmentGroups = {
        images: [],
        videos: [],
        audios: [],
        files: [],
    };

    for (const attachment of attachments) {
        if (attachment.kind === 'image' && attachment.preview_url) {
            groups.images.push(attachment);
        } else if (attachment.kind === 'video' && attachment.stream_url) {
            groups.videos.push(attachment);
        } else if (attachment.kind === 'audio' && attachment.stream_url) {
            groups.audios.push(attachment);
        } else {
            groups.files.push(attachment);
        }
    }

    return groups;
}

export function isPdf(attachment: TeamAttachment): boolean {
    return (
        attachment.mime_type === 'application/pdf' &&
        Boolean(attachment.preview_url)
    );
}

/** What the viewer can open in place: images, videos and PDF. */
export function viewable(attachment: TeamAttachment): boolean {
    return (
        (attachment.kind === 'image' && Boolean(attachment.preview_url)) ||
        (attachment.kind === 'video' && Boolean(attachment.stream_url)) ||
        isPdf(attachment)
    );
}

/** One line for a quoted or replied message without text. */
export function attachmentSummary(attachments: TeamAttachment[]): string {
    if (attachments.length === 0) {
        return '';
    }

    if (attachments.length > 1) {
        return `${attachments.length} archivos`;
    }

    const [only] = attachments;

    return {
        image: 'Foto',
        video: 'Video',
        audio: 'Audio',
        document: only.name,
    }[only.kind];
}

/* ------------------------------------------------------- draft validation */

export type ScreenedFiles = { files: File[]; problems: string[] };

/**
 * Adds `incoming` to the files already attached, refusing (each with its
 * reason, before anything is uploaded) what the chat or this server would
 * refuse anyway: types that are not allowed, empty files, files over the
 * chat's or the server's limit, more files than a message carries, and
 * files that together exceed what one request may weigh.
 */
export function screenFiles(
    current: File[],
    incoming: File[],
    limits: TeamChatLimits,
): ScreenedFiles {
    const problems: string[] = [];
    const allowed = new Set([
        ...limits.accept
            .split(',')
            .map((extension) => extension.trim().replace(/^\./u, ''))
            .filter((extension) => extension !== ''),
        'heic',
        'heif',
    ]);
    const maxBytes = limits.maxKilobytes * 1024;
    const serverMax = limits.server?.max_file_bytes ?? null;
    const accepted: File[] = [];
    let duplicates = 0;

    for (const file of incoming) {
        const label = `«${file.name}»`;

        if (!allowed.has(extensionOf(file.name))) {
            problems.push(
                `${label} no es de un tipo permitido. Puedes enviar imágenes, PDF, documentos de Office, texto, audio y video.`,
            );
        } else if (file.size === 0) {
            problems.push(`${label} está vacío y no se puede enviar.`);
        } else if (file.size > maxBytes) {
            problems.push(
                `${label} supera el máximo de ${formatFileSize(maxBytes)} por archivo.`,
            );
        } else if (serverMax !== null && file.size > serverMax) {
            problems.push(serverLimitNotice(file.size, serverMax, label));
        } else if (
            [...current, ...accepted].some(
                (known) =>
                    known.name === file.name &&
                    known.size === file.size &&
                    known.lastModified === file.lastModified,
            )
        ) {
            duplicates += 1;
        } else {
            accepted.push(file);
        }
    }

    if (duplicates > 0) {
        problems.push(
            duplicates === 1
                ? 'Ese archivo ya estaba adjunto.'
                : 'Algunos archivos ya estaban adjuntos.',
        );
    }

    let merged = [...current, ...accepted];

    if (merged.length > limits.maxFiles) {
        problems.push(
            `Puedes adjuntar como máximo ${limits.maxFiles} archivos por mensaje. Envía los demás en otro mensaje.`,
        );
        merged = merged.slice(0, limits.maxFiles);
    }

    /* Every file travels in one request, capped by post_max_size. */
    const requestMax = limits.server?.max_request_bytes ?? null;
    let trimmed = false;

    while (
        requestMax !== null &&
        merged.length > current.length &&
        merged.reduce((total, file) => total + file.size, 0) > requestMax
    ) {
        merged = merged.slice(0, -1);
        trimmed = true;
    }

    if (trimmed && requestMax !== null) {
        problems.push(
            `Los archivos juntos pesan más de lo que el servidor de Chat Manager acepta en un mensaje (${formatFileSize(requestMax)}): envíalos en varios mensajes; un administrador puede ampliar el límite.`,
        );
    }

    return { files: merged, problems };
}

/**
 * A clipboard image has no useful name («image.png»): it gets one with the
 * moment it was pasted, so several captures do not look the same file.
 */
export function namedCapture(file: File, now = new Date()): File {
    if (!/^image\.(png|jpe?g|gif|webp)$/iu.test(file.name)) {
        return file;
    }

    const pad = (value: number): string => String(value).padStart(2, '0');
    const stamp = `${now.getFullYear()}-${pad(now.getMonth() + 1)}-${pad(now.getDate())} ${pad(now.getHours())}.${pad(now.getMinutes())}.${pad(now.getSeconds())}`;
    const extension = extensionOf(file.name);

    return new File([file], `Captura ${stamp}.${extension}`, {
        type: file.type,
        lastModified: now.getTime(),
    });
}

/** A fresh idempotency key for one send attempt. */
export function newClientReference(): string {
    const source: Crypto = globalThis.crypto;

    // randomUUID only exists in secure contexts (HTTPS or localhost).
    if (typeof source.randomUUID === 'function') {
        return source.randomUUID();
    }

    const bytes = source.getRandomValues(new Uint8Array(16));
    bytes[6] = (bytes[6] & 0x0f) | 0x40;
    bytes[8] = (bytes[8] & 0x3f) | 0x80;
    const hex = [...bytes].map((byte) => byte.toString(16).padStart(2, '0'));

    return `${hex.slice(0, 4).join('')}-${hex.slice(4, 6).join('')}-${hex.slice(6, 8).join('')}-${hex.slice(8, 10).join('')}-${hex.slice(10).join('')}`;
}

/* ---------------------------------------------------------------- avatars */

/** Tints for people (literal classes, the same swatches of departments and tags). */
export const PERSON_TINTS = [
    {
        avatar: 'bg-blue-500/15 text-blue-700 dark:text-blue-300',
        name: 'text-blue-700 dark:text-blue-300',
    },
    {
        avatar: 'bg-emerald-500/15 text-emerald-700 dark:text-emerald-300',
        name: 'text-emerald-700 dark:text-emerald-300',
    },
    {
        avatar: 'bg-violet-500/15 text-violet-700 dark:text-violet-300',
        name: 'text-violet-700 dark:text-violet-300',
    },
    {
        avatar: 'bg-amber-500/15 text-amber-700 dark:text-amber-300',
        name: 'text-amber-700 dark:text-amber-300',
    },
    {
        avatar: 'bg-rose-500/15 text-rose-700 dark:text-rose-300',
        name: 'text-rose-700 dark:text-rose-300',
    },
    {
        avatar: 'bg-teal-500/15 text-teal-700 dark:text-teal-300',
        name: 'text-teal-700 dark:text-teal-300',
    },
    {
        avatar: 'bg-orange-500/15 text-orange-700 dark:text-orange-300',
        name: 'text-orange-700 dark:text-orange-300',
    },
] as const;

/** The same person always gets the same tint. */
export function personTint(
    seed: string | number | null | undefined,
): (typeof PERSON_TINTS)[number] {
    const text = String(seed ?? '');
    let hash = 0;

    for (let index = 0; index < text.length; index += 1) {
        hash = (hash * 31 + text.charCodeAt(index)) >>> 0;
    }

    return PERSON_TINTS[hash % PERSON_TINTS.length];
}

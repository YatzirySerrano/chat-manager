/**
 * «Guardar archivo» (born as «Guardar video»): the JSON requests of the
 * save button of every chat attachment (videos, images, PDFs, documents
 * and audios, in customer conversations and the internal chat). The server
 * sends `file_save` with each file the viewer may save (videos.save-to-nas);
 * without it the button does not exist. Videos also keep the original
 * `video_save` (same shape).
 */

import { File, FileText, Image, Music, Video } from '@lucide/vue';
import type { Component } from 'vue';

export type VideoExportState = {
    id: number;
    status: 'queued' | 'saving' | 'saved' | 'failed';
    label: string;
    /** Chosen folder, relative to the root of saved files ("" is the root). */
    folder: string;
    /** Name typed for the file, without extension. */
    file_name: string;
    /** Final path of the copy on the NAS, once saved. */
    path: string | null;
    error: string | null;
    saved_at: string | null;
    status_url: string;
    retry_url: string | null;
};

/** Kind of file, for the icon and the label of the button. */
export type FileSaveKind = 'video' | 'image' | 'pdf' | 'document' | 'audio';

/** What a bubble receives for a file the viewer may save. */
export type FileSaveOptions = {
    /** Where to send the request. */
    url: string;
    /** Absent in payloads from before every file could be saved: a video. */
    kind?: FileSaveKind;
    /** Extension the copy keeps (the original's), without the dot. */
    extension?: string;
    /** Name the file arrived with, if any. */
    name?: string | null;
    size_bytes?: number | null;
    /** The latest copy of this file, if any. */
    export: VideoExportState | null;
};

/** The original name of the options of a video. */
export type VideoSaveOptions = FileSaveOptions;

/** One folder of the NAS browser and its sub folders. */
export type VideoFolderListing = {
    /** Name of the root folder (setting «Carpeta de archivos en Synology»). */
    root: string;
    /** Folder relative to the root ("" is the root). */
    path: string;
    segments: string[];
    folders: string[];
    /** Files already in the folder, newest first. */
    files: VideoFolderFile[];
};

export type VideoFolderFile = {
    name: string;
    size: number | null;
    modified_at: string | null;
};

type KindMeta = {
    /** Label of the button: «Guardar imagen». */
    action: string;
    /** The noun, for short messages: «la imagen». */
    noun: string;
    icon: Component;
    /** Name proposed when nothing better is known. */
    fallbackName: string;
};

export const FILE_KINDS: Record<FileSaveKind, KindMeta> = {
    video: {
        action: 'Guardar video',
        noun: 'el video',
        icon: Video,
        fallbackName: 'Video',
    },
    image: {
        action: 'Guardar imagen',
        noun: 'la imagen',
        icon: Image,
        fallbackName: 'Imagen',
    },
    pdf: {
        action: 'Guardar PDF',
        noun: 'el PDF',
        icon: FileText,
        fallbackName: 'Documento',
    },
    document: {
        action: 'Guardar documento',
        noun: 'el documento',
        icon: File,
        fallbackName: 'Documento',
    },
    audio: {
        action: 'Guardar audio',
        noun: 'el audio',
        icon: Music,
        fallbackName: 'Audio',
    },
};

export function fileKind(options: FileSaveOptions): FileSaveKind {
    return options.kind ?? 'video';
}

/** The original name without its extension (the copy keeps the original's). */
export function stemOf(name: string | null | undefined): string {
    const trimmed = (name ?? '').trim();
    const dot = trimmed.lastIndexOf('.');

    return dot > 0 ? trimmed.slice(0, dot) : trimmed;
}

export const VIDEO_FOLDERS_URL = '/video-exports/folders';

/** URL of the listing of a folder; without a path, where the user saved last. */
export function videoFoldersUrl(path?: string | null): string {
    return path === undefined || path === null
        ? VIDEO_FOLDERS_URL
        : `${VIDEO_FOLDERS_URL}?path=${encodeURIComponent(path)}`;
}

/** A refused request: the message the server wrote and the field errors. */
export class VideoExportRequestError extends Error {
    constructor(
        public readonly status: number,
        message: string,
        public readonly errors: Record<string, string> = {},
    ) {
        super(message);
        this.name = 'VideoExportRequestError';
    }
}

function xsrfToken(): string {
    const match = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]+)/);

    return match ? decodeURIComponent(match[1]) : '';
}

const FALLBACK: Record<number, string> = {
    0: 'No hay conexión con el servidor. Revisa tu red e inténtalo de nuevo.',
    403: 'No tienes permiso para guardar archivos en Synology.',
    404: 'Este archivo ya no está disponible.',
    409: 'Este archivo ya se está guardando en Synology.',
    419: 'Tu sesión caducó. Recarga la página e inténtalo de nuevo.',
    429: 'Demasiados intentos seguidos. Espera un momento e inténtalo de nuevo.',
};

export async function videoExportRequest<T>(
    url: string,
    method: 'GET' | 'POST' = 'GET',
    body?: Record<string, unknown>,
): Promise<T> {
    let response: Response;

    try {
        response = await fetch(url, {
            method,
            credentials: 'same-origin',
            headers: {
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-XSRF-TOKEN': xsrfToken(),
                ...(body ? { 'Content-Type': 'application/json' } : {}),
            },
            ...(body ? { body: JSON.stringify(body) } : {}),
        });
    } catch {
        throw new VideoExportRequestError(0, FALLBACK[0]);
    }

    const data = (await response.json().catch(() => null)) as {
        message?: string;
        errors?: Record<string, string[] | string>;
    } | null;

    if (!response.ok) {
        const errors: Record<string, string> = {};

        for (const [key, value] of Object.entries(data?.errors ?? {})) {
            const first = Array.isArray(value) ? value[0] : value;

            if (typeof first === 'string') {
                errors[key] = first;
            }
        }

        const message =
            response.status === 422 || !FALLBACK[response.status]
                ? (Object.values(errors)[0] ??
                  data?.message ??
                  'No se pudo guardar el archivo. Inténtalo de nuevo.')
                : data?.message && response.status === 409
                  ? data.message
                  : FALLBACK[response.status];

        throw new VideoExportRequestError(response.status, message, errors);
    }

    return data as T;
}

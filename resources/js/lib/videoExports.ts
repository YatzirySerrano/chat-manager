/**
 * «Guardar video»: the JSON requests of the button of video bubbles
 * (customer conversations and internal chat). The server sends
 * `video_save` with each video the viewer may save (videos.save-to-nas);
 * without it the button does not exist.
 */

export type VideoExportState = {
    id: number;
    status: 'queued' | 'saving' | 'saved' | 'failed';
    label: string;
    /** Chosen folder, relative to the root of saved videos ("" is the root). */
    folder: string;
    /** Name typed for the video, without extension. */
    file_name: string;
    /** Final path of the copy on the NAS, once saved. */
    path: string | null;
    error: string | null;
    saved_at: string | null;
    status_url: string;
    retry_url: string | null;
};

/** What a video bubble receives when the viewer may save the video. */
export type VideoSaveOptions = {
    /** Where to send the request. */
    url: string;
    /** The latest copy of this video, if any. */
    export: VideoExportState | null;
};

/** One folder of the NAS browser and its sub folders. */
export type VideoFolderListing = {
    /** Name of the root folder (setting «Carpeta de videos de solicitudes»). */
    root: string;
    /** Folder relative to the root ("" is the root). */
    path: string;
    segments: string[];
    folders: string[];
};

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
    403: 'No tienes permiso para guardar videos en Synology.',
    404: 'Este video ya no está disponible.',
    409: 'Este video ya se está guardando en Synology.',
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
                  'No se pudo guardar el video. Inténtalo de nuevo.')
                : data?.message && response.status === 409
                  ? data.message
                  : FALLBACK[response.status];

        throw new VideoExportRequestError(response.status, message, errors);
    }

    return data as T;
}

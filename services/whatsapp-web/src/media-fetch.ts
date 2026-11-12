import { randomBytes } from 'node:crypto';
import { createWriteStream } from 'node:fs';
import { rm } from 'node:fs/promises';
import { join } from 'node:path';
import { Readable } from 'node:stream';
import { pipeline } from 'node:stream/promises';
import { ApiError } from './types.js';
import { ensurePrivateDir } from './vault.js';

/**
 * Downloads the file of an outgoing message from Laravel's signed internal
 * URL (§6) into a private temporary file.
 *
 * Only the path and query of the given URL are used: the request always
 * goes to the configured Laravel origin, so a forged URL can never make the
 * service fetch anything else.
 */
export type FetchedMedia = {
    path: string;
    mime: string | null;
    size: number;
    cleanup: () => Promise<void>;
};

export type MediaFetcher = (url: string) => Promise<FetchedMedia>;

export const MEDIA_PATH_MARKER = '/internal/whatsapp-web/media/';

export function createMediaFetcher(options: {
    laravelUrl: string;
    temporaryDirectory: string;
    maxBytes: number;
    timeoutMs?: number;
    fetch?: typeof fetch;
}): MediaFetcher {
    const origin = new URL(options.laravelUrl).origin;
    const request = options.fetch ?? fetch;

    return async (url: string): Promise<FetchedMedia> => {
        let target: URL;

        try {
            const given = new URL(url, origin);

            if (!given.pathname.includes(MEDIA_PATH_MARKER)) {
                throw new Error('unexpected path');
            }

            target = new URL(given.pathname + given.search, origin);
        } catch {
            throw new ApiError(422, 'media_unavailable');
        }

        let response: Response;

        try {
            response = await request(target, {
                redirect: 'error',
                signal: AbortSignal.timeout(options.timeoutMs ?? 60_000),
            });
        } catch {
            throw new ApiError(422, 'media_unavailable');
        }

        if (!response.ok || response.body === null) {
            await response.body?.cancel().catch(() => undefined);

            throw new ApiError(422, 'media_unavailable');
        }

        const declared = Number(response.headers.get('content-length') ?? '0');

        if (declared > options.maxBytes) {
            await response.body.cancel().catch(() => undefined);

            throw new ApiError(422, 'media_too_large');
        }

        await ensurePrivateDir(options.temporaryDirectory);

        const path = join(
            options.temporaryDirectory,
            `out-${randomBytes(8).toString('hex')}`,
        );
        const cleanup = (): Promise<void> => rm(path, { force: true });
        let size = 0;
        let tooLarge = false;

        try {
            await pipeline(
                Readable.fromWeb(
                    response.body as import('node:stream/web').ReadableStream<Uint8Array>,
                ),
                async function* (source: AsyncIterable<Buffer>) {
                    for await (const chunk of source) {
                        size += chunk.length;

                        if (size > options.maxBytes) {
                            tooLarge = true;

                            throw new Error('too large');
                        }

                        yield chunk;
                    }
                },
                createWriteStream(path, { mode: 0o600 }),
            );
        } catch {
            await cleanup();

            throw new ApiError(
                422,
                tooLarge ? 'media_too_large' : 'media_unavailable',
            );
        }

        const mime =
            response.headers.get('content-type')?.split(';')[0]?.trim() || null;

        return { path, mime, size, cleanup };
    };
}

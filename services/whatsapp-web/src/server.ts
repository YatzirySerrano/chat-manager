import {
    createServer,
    type IncomingMessage,
    type Server,
    type ServerResponse,
} from 'node:http';
import { safeErrorMessage, type Logger } from './logger.js';
import type { SessionManager } from './manager.js';
import { NonceCache, verify } from './signing.js';
import {
    ApiError,
    type MediaDescriptor,
    type MediaKind,
    type OutboundMessage,
} from './types.js';
import type { MediaDownloader } from './wa.js';

/**
 * Control API for Laravel (§4). It listens on the loopback interface only
 * and refuses every request that is not signed with the shared secret,
 * except the liveness probe, which reveals nothing.
 */
export type ServerOptions = {
    secret: string;
    manager: SessionManager;
    downloadMedia: MediaDownloader;
    maxMediaBytes: number;
    logger: Logger;
    status: () => Record<string, unknown>;
    now?: () => number;
};

const MAX_BODY_BYTES = 256 * 1024;
const MEDIA_KINDS: readonly MediaKind[] = [
    'image',
    'video',
    'audio',
    'document',
    'sticker',
];

function readBody(request: IncomingMessage): Promise<Buffer> {
    return new Promise((resolve, reject) => {
        const chunks: Buffer[] = [];
        let size = 0;

        request.on('data', (chunk: Buffer) => {
            size += chunk.length;

            if (size > MAX_BODY_BYTES) {
                reject(new ApiError(413, 'body_too_large'));
                request.destroy();

                return;
            }

            chunks.push(chunk);
        });
        request.on('end', () => resolve(Buffer.concat(chunks)));
        request.on('error', reject);
    });
}

function json(
    response: ServerResponse,
    status: number,
    payload: unknown,
): void {
    const body = JSON.stringify(payload);

    response.writeHead(status, {
        'Content-Type': 'application/json; charset=utf-8',
        'Content-Length': Buffer.byteLength(body),
        'Cache-Control': 'no-store',
    });
    response.end(body);
}

function parseJson(body: Buffer): Record<string, unknown> {
    if (body.length === 0) {
        return {};
    }

    try {
        const parsed: unknown = JSON.parse(body.toString('utf8'));

        if (
            typeof parsed === 'object' &&
            parsed !== null &&
            !Array.isArray(parsed)
        ) {
            return parsed as Record<string, unknown>;
        }
    } catch {
        // Falls through.
    }

    throw new ApiError(422, 'invalid_request');
}

function outboundMessage(payload: Record<string, unknown>): OutboundMessage {
    const key = payload.idempotency_key;
    const to = payload.to;
    const type = payload.type;

    if (
        typeof key !== 'string' ||
        key === '' ||
        key.length > 200 ||
        typeof to !== 'string' ||
        typeof type !== 'string'
    ) {
        throw new ApiError(422, 'invalid_request');
    }

    return payload as unknown as OutboundMessage;
}

function mediaDescriptor(payload: Record<string, unknown>): MediaDescriptor {
    const descriptor = payload.descriptor as
        | Partial<MediaDescriptor>
        | undefined;

    if (
        typeof descriptor !== 'object' ||
        descriptor === null ||
        !MEDIA_KINDS.includes(descriptor.kind as MediaKind) ||
        typeof descriptor.media_key !== 'string' ||
        (typeof descriptor.direct_path !== 'string' &&
            typeof descriptor.url !== 'string')
    ) {
        throw new ApiError(422, 'invalid_request');
    }

    return descriptor as MediaDescriptor;
}

export function createControlServer(options: ServerOptions): Server {
    const nonces = new NonceCache();

    const route = async (
        request: IncomingMessage,
        response: ServerResponse,
    ): Promise<void> => {
        const method = (request.method ?? 'GET').toUpperCase();
        const url = request.url ?? '/';
        const path = url.split('?')[0] ?? '/';

        if (method === 'GET' && path === '/health') {
            json(response, 200, { status: 'ok' });

            return;
        }

        const body = await readBody(request);
        const header = (name: string): string | undefined => {
            const value = request.headers[name];

            return Array.isArray(value) ? value[0] : value;
        };
        const failure = verify({
            secret: options.secret,
            method,
            path: url,
            body,
            timestamp: header('x-cm-timestamp'),
            nonce: header('x-cm-nonce'),
            signature: header('x-cm-signature'),
            nonces,
            now: options.now?.(),
        });

        if (failure !== null) {
            options.logger.warn(
                { method, path, reason: failure },
                'Unauthenticated request refused',
            );
            json(response, 401, { error: 'unauthorized' });

            return;
        }

        if (method === 'GET' && path === '/v1/status') {
            json(response, 200, {
                ...options.status(),
                sessions: await options.manager.all(),
            });

            return;
        }

        const match =
            /^\/v1\/sessions\/(\d{1,12})(?:\/(start|stop|logout|messages|media))?$/.exec(
                path,
            );

        if (!match) {
            json(response, 404, { error: 'not_found' });

            return;
        }

        const connectionId = Number(match[1]);
        const action = match[2] ?? null;
        const { manager } = options;

        if (action === null && method === 'GET') {
            json(response, 200, await manager.snapshot(connectionId));
        } else if (action === null && method === 'DELETE') {
            await manager.destroy(connectionId);
            json(response, 200, { deleted: true });
        } else if (action === 'start' && method === 'POST') {
            const mode = parseJson(body).mode;

            if (mode !== 'link' && mode !== 'restore') {
                throw new ApiError(422, 'invalid_request');
            }

            json(response, 200, await manager.start(connectionId, mode));
        } else if (action === 'stop' && method === 'POST') {
            json(response, 200, await manager.stop(connectionId));
        } else if (action === 'logout' && method === 'POST') {
            json(response, 200, await manager.logout(connectionId));
        } else if (action === 'messages' && method === 'POST') {
            json(
                response,
                200,
                await manager.send(
                    connectionId,
                    outboundMessage(parseJson(body)),
                ),
            );
        } else if (action === 'media' && method === 'POST') {
            await streamMedia(
                options,
                connectionId,
                mediaDescriptor(parseJson(body)),
                response,
            );
        } else {
            json(response, 405, { error: 'method_not_allowed' });
        }
    };

    return createServer((request, response) => {
        route(request, response).catch((error: unknown) => {
            if (response.headersSent) {
                response.destroy();

                return;
            }

            if (error instanceof ApiError) {
                json(response, error.status, {
                    error: error.code,
                    ...error.extra,
                });

                return;
            }

            options.logger.error(
                { err: safeErrorMessage(error) },
                'Unexpected error in the control API',
            );
            json(response, 500, { error: 'internal' });
        });
    });
}

/** Streams a received file, decrypted, never beyond the size limit. */
async function streamMedia(
    options: ServerOptions,
    connectionId: number,
    descriptor: MediaDescriptor,
    response: ServerResponse,
): Promise<void> {
    if (!(await options.manager.exists(connectionId))) {
        throw new ApiError(404, 'unknown_session');
    }

    if (
        descriptor.size !== null &&
        descriptor.size !== undefined &&
        descriptor.size > options.maxMediaBytes
    ) {
        throw new ApiError(422, 'media_too_large');
    }

    let stream;

    try {
        stream = await options.downloadMedia(descriptor);
    } catch (error) {
        options.logger.warn(
            {
                connection_id: connectionId,
                kind: descriptor.kind,
                err: safeErrorMessage(error),
            },
            'A received file could not be downloaded',
        );

        throw new ApiError(422, 'media_unavailable');
    }

    await new Promise<void>((resolve, reject) => {
        let sent = 0;
        let started = false;

        const begin = (): void => {
            if (started) {
                return;
            }

            started = true;
            response.writeHead(200, {
                'Content-Type': descriptor.mime ?? 'application/octet-stream',
                'Cache-Control': 'no-store',
                ...(typeof descriptor.size === 'number'
                    ? { 'Content-Length': descriptor.size }
                    : {}),
            });
        };

        stream.on('data', (chunk: Buffer) => {
            sent += chunk.length;

            if (sent > options.maxMediaBytes) {
                stream.destroy();

                if (started) {
                    response.destroy();
                    resolve();
                } else {
                    reject(new ApiError(422, 'media_too_large'));
                }

                return;
            }

            begin();

            if (!response.write(chunk)) {
                stream.pause();
                response.once('drain', () => stream.resume());
            }
        });
        stream.on('end', () => {
            begin();
            response.end();
            resolve();
        });
        stream.on('error', (error: unknown) => {
            options.logger.warn(
                {
                    connection_id: connectionId,
                    kind: descriptor.kind,
                    err: safeErrorMessage(error),
                },
                'A received file could not be downloaded',
            );

            if (started) {
                response.destroy();
                resolve();
            } else {
                reject(new ApiError(422, 'media_unavailable'));
            }
        });
        response.on('close', () => stream.destroy());
    });
}

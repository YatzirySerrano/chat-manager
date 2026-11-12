import assert from 'node:assert/strict';
import { readdir, readFile } from 'node:fs/promises';
import { Readable } from 'node:stream';
import { describe, test } from 'node:test';
import { createMediaFetcher } from '../src/media-fetch.js';
import { ApiError, type MediaDescriptor } from '../src/types.js';
import { linkSession, startHarness, temporaryDirectory } from './helpers.js';

const descriptor = (
    overrides: Partial<MediaDescriptor> = {},
): MediaDescriptor => ({
    kind: 'image',
    media_key: Buffer.alloc(32, 1).toString('base64'),
    direct_path: '/v/t62/abc',
    url: null,
    mime: 'image/jpeg',
    size: 10,
    ...overrides,
});

describe('archivos entrantes: POST /v1/sessions/{id}/media (§4, §6)', () => {
    test('entrega el archivo descifrado en streaming con su tipo', async () => {
        const seen: MediaDescriptor[] = [];
        const harness = await startHarness({
            dependencies: {
                downloadMedia: async (given) => {
                    seen.push(given);

                    return Readable.from([
                        Buffer.from('file-'),
                        Buffer.from('bytes'),
                    ]);
                },
            },
        });

        try {
            await linkSession(harness, 1);

            const response = await harness.call(
                'POST',
                '/v1/sessions/1/media',
                { descriptor: descriptor() },
            );

            assert.equal(response.status, 200);
            assert.equal(response.headers.get('content-type'), 'image/jpeg');
            assert.equal(response.headers.get('content-length'), '10');
            assert.equal(response.raw.toString(), 'file-bytes');
            assert.deepEqual(seen, [descriptor()]);

            // También con la sesión detenida: descargar no necesita el socket.
            await harness.call('POST', '/v1/sessions/1/stop');
            assert.equal(
                (
                    await harness.call('POST', '/v1/sessions/1/media', {
                        descriptor: descriptor(),
                    })
                ).status,
                200,
            );
        } finally {
            await harness.close();
            await harness.removeData();
        }
    });

    test('rechaza sesiones desconocidas, descriptores inválidos, archivos enormes y descargas fallidas', async () => {
        const harness = await startHarness({
            config: { maxMediaBytes: 1024 },
            dependencies: {
                downloadMedia: async (given) => {
                    if (given.direct_path === '/expired') {
                        throw new Error('media url expired');
                    }

                    return Readable.from([
                        Buffer.alloc(800),
                        Buffer.alloc(800),
                    ]);
                },
            },
        });

        try {
            await linkSession(harness, 1);

            const call = (id: number, body: unknown) =>
                harness.call('POST', `/v1/sessions/${id}/media`, body);

            assert.deepEqual(
                (await call(42, { descriptor: descriptor() })).json,
                { error: 'unknown_session' },
            );
            assert.equal((await call(1, {})).status, 422);
            assert.equal(
                (
                    await call(1, {
                        descriptor: {
                            kind: 'exe',
                            media_key: 'x',
                            direct_path: '/a',
                        },
                    })
                ).json.error,
                'invalid_request',
            );
            assert.equal(
                (
                    await call(1, {
                        descriptor: { kind: 'image', media_key: 'x' },
                    })
                ).json.error,
                'invalid_request',
            );
            assert.deepEqual(
                (await call(1, { descriptor: descriptor({ size: 5000 }) }))
                    .json,
                { error: 'media_too_large' },
            );
            assert.deepEqual(
                (
                    await call(1, {
                        descriptor: descriptor({ direct_path: '/expired' }),
                    })
                ).json,
                { error: 'media_unavailable' },
            );

            // Un archivo que resulta mayor de lo declarado se corta: nunca se entrega entero.
            await assert.rejects(
                call(1, { descriptor: descriptor({ size: null }) }),
            );
        } finally {
            await harness.close();
            await harness.removeData();
        }
    });
});

describe('archivos salientes: descarga desde Laravel (§6)', () => {
    const fetcherWith = async (
        handler: (url: URL) => Response,
        maxBytes = 1024,
    ) => {
        const temporary = await temporaryDirectory();
        const requested: string[] = [];

        return {
            temporary,
            requested,
            fetchMedia: createMediaFetcher({
                laravelUrl: 'http://127.0.0.1:8080',
                temporaryDirectory: temporary.path,
                maxBytes,
                fetch: (async (url: URL) => {
                    requested.push(String(url));

                    return handler(url);
                }) as unknown as typeof fetch,
            }),
        };
    };

    const isApiError = (code: string) => (error: unknown) =>
        error instanceof ApiError &&
        error.code === code &&
        error.status === 422;

    test('descarga a un archivo temporal privado y lo borra al terminar', async () => {
        const { temporary, fetchMedia } = await fetcherWith(
            () =>
                new Response('contenido', {
                    status: 200,
                    headers: { 'content-type': 'image/png; charset=binary' },
                }),
        );

        try {
            const media = await fetchMedia(
                'http://127.0.0.1:8080/internal/whatsapp-web/media/7?ts=1&nonce=n&sig=s',
            );

            assert.equal((await readFile(media.path)).toString(), 'contenido');
            assert.equal(media.mime, 'image/png');
            assert.equal(media.size, 9);
            await media.cleanup();
            assert.deepEqual(await readdir(temporary.path), []);
        } finally {
            await temporary.remove();
        }
    });

    test('siempre pide al Laravel configurado, sea cual sea el host de la URL recibida', async () => {
        const { temporary, requested, fetchMedia } = await fetcherWith(
            () => new Response('ok'),
        );

        try {
            const media = await fetchMedia(
                'https://atacante.example/internal/whatsapp-web/media/7?ts=1&nonce=n&sig=s',
            );

            await media.cleanup();
            assert.deepEqual(requested, [
                'http://127.0.0.1:8080/internal/whatsapp-web/media/7?ts=1&nonce=n&sig=s',
            ]);

            await assert.rejects(
                fetchMedia('http://127.0.0.1:8080/admin/users'),
                isApiError('media_unavailable'),
            );
            await assert.rejects(
                fetchMedia('file:///etc/passwd'),
                isApiError('media_unavailable'),
            );
            await assert.rejects(
                fetchMedia('http://169.254.169.254/latest/meta-data'),
                isApiError('media_unavailable'),
            );
            assert.equal(
                requested.length,
                1,
                'las URL ajenas ni siquiera se piden',
            );
        } finally {
            await temporary.remove();
        }
    });

    test('respeta el tope de tamaño, lo declare o no el servidor', async () => {
        const declared = await fetcherWith(
            () => new Response('x', { headers: { 'content-length': '5000' } }),
        );
        const undeclared = await fetcherWith(
            () => new Response(new Blob([Buffer.alloc(3000)]).stream()),
        );

        try {
            await assert.rejects(
                declared.fetchMedia('/internal/whatsapp-web/media/1'),
                isApiError('media_too_large'),
            );
            await assert.rejects(
                undeclared.fetchMedia('/internal/whatsapp-web/media/1'),
                isApiError('media_too_large'),
            );
            assert.deepEqual(
                await readdir(undeclared.temporary.path),
                [],
                'no queda el archivo a medias',
            );
        } finally {
            await declared.temporary.remove();
            await undeclared.temporary.remove();
        }
    });

    test('un error de Laravel deja el archivo como no disponible', async () => {
        const missing = await fetcherWith(
            () => new Response('', { status: 404 }),
        );
        const failing = await fetcherWith(() => {
            throw new Error('ECONNREFUSED');
        });

        try {
            await assert.rejects(
                missing.fetchMedia('/internal/whatsapp-web/media/1'),
                isApiError('media_unavailable'),
            );
            await assert.rejects(
                failing.fetchMedia('/internal/whatsapp-web/media/1'),
                isApiError('media_unavailable'),
            );
        } finally {
            await missing.temporary.remove();
            await failing.temporary.remove();
        }
    });
});

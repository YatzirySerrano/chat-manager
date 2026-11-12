import assert from 'node:assert/strict';
import { randomBytes } from 'node:crypto';
import { readdir, readFile } from 'node:fs/promises';
import { join } from 'node:path';
import { describe, test } from 'node:test';
import { createEventDelivery } from '../src/laravel.js';
import { Outbox, type DeliveryResult } from '../src/outbox.js';
import { NonceCache, verify } from '../src/signing.js';
import type { EventEnvelope, ServiceEvent } from '../src/types.js';
import { Vault } from '../src/vault.js';
import {
    flush,
    linkSession,
    SECRET,
    silentLogger,
    startHarness,
    temporaryDirectory,
    textMessage,
    until,
} from './helpers.js';

const inbound = (id: string): ServiceEvent => ({
    type: 'message.inbound',
    data: {
        provider_message_id: id,
        remote_jid: '5215511112222@s.whatsapp.net',
        phone: '5215511112222',
        push_name: 'María',
        from_me: false,
        timestamp: 1_700_000_000,
        type: 'text',
        text: 'contenido privado del mensaje',
        quoted: null,
    },
});

function makeOutbox(
    directory: string,
    vault: Vault,
    answers: { value: DeliveryResult },
    delivered: EventEnvelope[],
    gone: number[] = [],
): Outbox {
    return new Outbox({
        directory,
        vault,
        deliver: async (envelope) => {
            if (answers.value === 'ok') {
                delivered.push(envelope);
            }

            return answers.value;
        },
        onGone: (connectionId) => gone.push(connectionId),
        logger: silentLogger,
        retryDelay: () => 1,
        sleep: () => new Promise((resolve) => setTimeout(resolve, 1)),
    });
}

describe('cola de eventos hacia Laravel (§5)', () => {
    test('con Laravel caído los eventos esperan en disco, cifrados, y se entregan en orden al volver', async () => {
        const temporary = await temporaryDirectory();
        const vault = new Vault(randomBytes(32));
        const answers = { value: 'retry' as DeliveryResult };
        const delivered: EventEnvelope[] = [];
        const outbox = makeOutbox(temporary.path, vault, answers, delivered);

        try {
            await outbox.load();
            outbox.enqueue(1, inbound('A'));
            outbox.enqueue(1, inbound('B'));
            outbox.enqueue(2, inbound('C'));
            await outbox.persisted();
            await new Promise((resolve) => setTimeout(resolve, 30));

            assert.equal(delivered.length, 0);
            assert.equal(outbox.pending(), 3);

            const files = (await readdir(temporary.path)).filter((file) =>
                file.endsWith('.evt'),
            );

            assert.equal(files.length, 3);

            for (const file of files) {
                const raw = await readFile(join(temporary.path, file));

                assert.equal(
                    raw.includes('contenido privado'),
                    false,
                    'el texto del mensaje no está en claro',
                );
                assert.equal(
                    raw.includes('5215511112222'),
                    false,
                    'el número no está en claro',
                );
            }

            answers.value = 'ok';
            await outbox.idle();

            assert.deepEqual(
                delivered
                    .filter((event) => event.connection_id === 1)
                    .map(
                        (event) =>
                            (event.data as { provider_message_id: string })
                                .provider_message_id,
                    ),
                ['A', 'B'],
            );
            assert.equal(delivered.length, 3);
            assert.equal(
                (await readdir(temporary.path)).filter((file) =>
                    file.endsWith('.evt'),
                ).length,
                0,
            );
            assert.deepEqual(outbox.stats(), {
                pending: 0,
                delivered: 3,
                dropped: 0,
            });
        } finally {
            await outbox.stop();
            await temporary.remove();
        }
    });

    test('lo que quedó pendiente se entrega después de reiniciar el servicio', async () => {
        const temporary = await temporaryDirectory();
        const vault = new Vault(randomBytes(32));
        const first = makeOutbox(temporary.path, vault, { value: 'retry' }, []);

        await first.load();
        first.enqueue(5, inbound('X1'));
        first.enqueue(5, inbound('X2'));
        await new Promise((resolve) => setTimeout(resolve, 20));
        await first.stop();

        const delivered: EventEnvelope[] = [];
        const second = makeOutbox(
            temporary.path,
            vault,
            { value: 'ok' },
            delivered,
        );

        try {
            await second.load();
            await second.idle();

            assert.deepEqual(
                delivered.map(
                    (event) =>
                        (event.data as { provider_message_id: string })
                            .provider_message_id,
                ),
                ['X1', 'X2'],
            );
        } finally {
            await second.stop();
            await temporary.remove();
        }
    });

    test('una conexión bloqueada no detiene a las demás', async () => {
        const temporary = await temporaryDirectory();
        const delivered: EventEnvelope[] = [];
        const outbox = new Outbox({
            directory: temporary.path,
            vault: new Vault(randomBytes(32)),
            deliver: async (envelope) => {
                if (envelope.connection_id === 1) {
                    return 'retry';
                }

                delivered.push(envelope);

                return 'ok';
            },
            onGone: () => undefined,
            logger: silentLogger,
            retryDelay: () => 1,
            sleep: () => new Promise((resolve) => setTimeout(resolve, 1)),
        });

        try {
            await outbox.load();
            outbox.enqueue(1, inbound('bloqueado'));
            outbox.enqueue(2, inbound('libre'));
            await until(() => delivered.length === 1);
            await new Promise((resolve) => setTimeout(resolve, 20));

            assert.equal(delivered.length, 1);
            assert.equal(outbox.pending(1), 1);
            assert.equal(outbox.pending(2), 0);
        } finally {
            await outbox.stop();
            await temporary.remove();
        }
    });

    test('solo se conserva el último estado pendiente de una sesión (un QR viejo nunca se entrega tarde)', async () => {
        const temporary = await temporaryDirectory();
        const answers = { value: 'retry' as DeliveryResult };
        const delivered: EventEnvelope[] = [];
        const outbox = makeOutbox(
            temporary.path,
            new Vault(randomBytes(32)),
            answers,
            delivered,
        );
        const state = (value: string): ServiceEvent => ({
            type: 'session.state',
            data: {
                connection_id: 1,
                state: 'qr_pending',
                phone: null,
                push_name: null,
                qr: { value, expires_at: 0 },
                connected_since: null,
                last_event_at: null,
                last_inbound_at: null,
                last_outbound_at: null,
                reconnects: 0,
                last_error: null,
            },
        });

        try {
            await outbox.load();
            outbox.enqueue(1, inbound('antes'));
            outbox.enqueue(1, state('qr-1'));
            outbox.enqueue(1, state('qr-2'));
            outbox.enqueue(1, state('qr-3'));
            await new Promise((resolve) => setTimeout(resolve, 20));
            answers.value = 'ok';
            await outbox.idle();

            assert.deepEqual(
                delivered.map((event) =>
                    event.type === 'session.state'
                        ? event.data.qr?.value
                        : 'mensaje',
                ),
                ['mensaje', 'qr-3'],
            );
        } finally {
            await outbox.stop();
            await temporary.remove();
        }
    });

    test('un evento que Laravel declara inválido se descarta y la cola sigue', async () => {
        const temporary = await temporaryDirectory();
        const delivered: EventEnvelope[] = [];
        let calls = 0;
        const outbox = new Outbox({
            directory: temporary.path,
            vault: new Vault(randomBytes(32)),
            deliver: async (envelope) => {
                calls += 1;

                if (calls === 1) {
                    return 'drop';
                }

                delivered.push(envelope);

                return 'ok';
            },
            onGone: () => undefined,
            logger: silentLogger,
        });

        try {
            await outbox.load();
            outbox.enqueue(1, inbound('malo'));
            outbox.enqueue(1, inbound('bueno'));
            await outbox.idle();

            assert.equal(delivered.length, 1);
            assert.deepEqual(outbox.stats(), {
                pending: 0,
                delivered: 1,
                dropped: 1,
            });
        } finally {
            await outbox.stop();
            await temporary.remove();
        }
    });
});

describe('Laravel no conoce la conexión (404)', () => {
    test('se descartan sus eventos y la sesión se detiene sin cerrar sesión en el teléfono', async () => {
        const harness = await startHarness();

        try {
            const socket = await linkSession(harness, 4);

            harness.laravel.answer = 'gone';
            socket.emit({
                type: 'messages',
                upsert: 'notify',
                messages: [
                    textMessage('Z1', '5215511112222@s.whatsapp.net', 'Hola'),
                ],
            });
            await until(() => socket.closed);
            await flush();

            assert.equal(harness.service.outbox.pending(4), 0);
            assert.equal(socket.closed, true);
            assert.equal(socket.loggedOut, false);
            assert.equal(
                (await harness.call('GET', '/v1/sessions/4')).json.state,
                'disconnected',
            );
        } finally {
            await harness.close();
            await harness.removeData();
        }
    });
});

describe('entrega HTTP a Laravel', () => {
    const envelope = {
        ...inbound('E1'),
        event_id: 'e-1',
        connection_id: 3,
        occurred_at: 1_700_000_000,
    } as EventEnvelope;

    test('firma la petición con el secreto compartido y la ruta que ve Laravel', async () => {
        let captured: { url: string; init: RequestInit } | null = null;
        const deliver = createEventDelivery({
            laravelUrl: 'https://mensajes.example.test/',
            secret: SECRET,
            timeoutMs: 1000,
            fetch: (async (url: URL, init: RequestInit) => {
                captured = { url: String(url), init };

                return new Response('{"status":"ok"}', { status: 200 });
            }) as unknown as typeof fetch,
        });

        assert.equal(await deliver(envelope), 'ok');

        const { url, init } = captured as unknown as {
            url: string;
            init: RequestInit;
        };
        const headers = init.headers as Record<string, string>;

        assert.equal(
            url,
            'https://mensajes.example.test/internal/whatsapp-web/events',
        );
        assert.equal(init.method, 'POST');
        assert.equal(
            verify({
                secret: SECRET,
                method: 'POST',
                path: '/internal/whatsapp-web/events',
                body: Buffer.from(init.body as string),
                timestamp: headers['X-CM-Timestamp'],
                nonce: headers['X-CM-Nonce'],
                signature: headers['X-CM-Signature'],
                nonces: new NonceCache(),
            }),
            null,
        );
        assert.deepEqual(JSON.parse(init.body as string), envelope);
    });

    test('traduce la respuesta de Laravel', async () => {
        const answer = (status: number) =>
            createEventDelivery({
                laravelUrl: 'http://127.0.0.1',
                secret: SECRET,
                timeoutMs: 1000,
                fetch: (async () =>
                    new Response(null, { status })) as unknown as typeof fetch,
            })(envelope);

        assert.equal(await answer(200), 'ok');
        assert.equal(await answer(204), 'ok');
        assert.equal(await answer(404), 'gone');
        assert.equal(await answer(422), 'drop');
        assert.equal(await answer(401), 'retry');
        assert.equal(await answer(429), 'retry');
        assert.equal(await answer(500), 'retry');
        assert.equal(await answer(503), 'retry');
    });
});

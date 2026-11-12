import assert from 'node:assert/strict';
import { afterEach, beforeEach, describe, test } from 'node:test';
import type { EventEnvelope } from '../src/types.js';
import {
    flush,
    linkSession,
    startHarness,
    textMessage,
    type FakeSocket,
} from './helpers.js';

type Harness = Awaited<ReturnType<typeof startHarness>>;

const CUSTOMER = '5215511112222@s.whatsapp.net';

describe('recepción de mensajes (§5)', () => {
    let harness: Harness;
    let socket: FakeSocket;

    beforeEach(async () => {
        harness = await startHarness();
        socket = await linkSession(harness, 1);
        harness.events.length = 0;
    });

    afterEach(async () => {
        await harness.close();
        await harness.removeData();
    });

    const ofType = (type: string): EventEnvelope[] =>
        harness.events.filter((event) => event.type === type);

    test('un mensaje nuevo llega a Laravel con su identificador real', async () => {
        socket.emit({
            type: 'messages',
            upsert: 'notify',
            messages: [textMessage('ABC123', CUSTOMER, 'Hola, buen día')],
        });
        await harness.settle();

        const [event] = ofType('message.inbound');

        assert.ok(event);
        assert.equal(event.connection_id, 1);
        assert.match(event.event_id, /^[0-9a-f-]{36}$/);
        assert.deepEqual(event.data, {
            provider_message_id: 'ABC123',
            remote_jid: CUSTOMER,
            phone: '5215511112222',
            push_name: 'María',
            from_me: false,
            timestamp: 1_700_000_050,
            type: 'text',
            text: 'Hola, buen día',
            quoted: null,
        });
        assert.equal(
            (await harness.call('GET', '/v1/sessions/1')).json.last_inbound_at,
            1_700_000_000,
        );
    });

    test('el mismo mensaje entregado dos veces por WhatsApp se notifica una vez', async () => {
        const message = textMessage('DUP1', CUSTOMER, 'Hola');

        socket.emit({
            type: 'messages',
            upsert: 'notify',
            messages: [message, message],
        });
        socket.emit({
            type: 'messages',
            upsert: 'notify',
            messages: [message],
        });
        await harness.settle();

        assert.equal(ofType('message.inbound').length, 1);
    });

    test('historial, mensajes anteriores a la vinculación, grupos y estados no se notifican', async () => {
        socket.emit({
            type: 'messages',
            upsert: 'append',
            messages: [textMessage('H1', CUSTOMER, 'del historial')],
        });
        socket.emit({
            type: 'messages',
            upsert: 'notify',
            messages: [
                textMessage('OLD', CUSTOMER, 'antes de vincular', {
                    timestamp: 1_699_990_000,
                }),
                textMessage('G1', '120363025246125486@g.us', 'en un grupo'),
                textMessage('S1', 'status@broadcast', 'un estado'),
                textMessage('N1', '120363025246125486@newsletter', 'un canal'),
                {
                    key: { id: 'P1', remoteJid: CUSTOMER, fromMe: false },
                    message: { protocolMessage: { type: 0 } },
                    messageTimestamp: 1_700_000_050,
                },
            ],
        });
        await harness.settle();

        assert.equal(harness.events.length, 0);
    });

    test('lo escrito desde el propio teléfono llega como eco; lo enviado por el servicio no', async () => {
        socket.directory.set('5215511112222', CUSTOMER);

        const sent = await harness.call('POST', '/v1/sessions/1/messages', {
            idempotency_key: 'cm-msg-1',
            to: '5215511112222',
            type: 'text',
            text: 'Respuesta',
        });

        socket.emit({
            type: 'messages',
            upsert: 'notify',
            messages: [
                textMessage(
                    sent.json.provider_message_id,
                    CUSTOMER,
                    'Respuesta',
                    { fromMe: true },
                ),
                textMessage('FROMPHONE', CUSTOMER, 'Escrito en el teléfono', {
                    fromMe: true,
                }),
            ],
        });
        await harness.settle();

        assert.equal(ofType('message.inbound').length, 0);

        const echoes = ofType('message.outbound_echo');

        assert.equal(echoes.length, 1);
        assert.equal(
            (echoes[0]?.data as { provider_message_id: string })
                .provider_message_id,
            'FROMPHONE',
        );
        assert.equal((echoes[0]?.data as { from_me: boolean }).from_me, true);
        assert.equal(
            (echoes[0]?.data as { push_name: string | null }).push_name,
            null,
        );
    });

    test('los estados de entrega de lo enviado se traducen a sent, delivered, read y failed', async () => {
        socket.emit({
            type: 'statuses',
            updates: [
                { id: 'M1', remoteJid: CUSTOMER, fromMe: true, status: 2 },
                { id: 'M1', remoteJid: CUSTOMER, fromMe: true, status: 3 },
                { id: 'M1', remoteJid: CUSTOMER, fromMe: true, status: 4 },
                { id: 'M2', remoteJid: CUSTOMER, fromMe: true, status: 0 },
                { id: 'M3', remoteJid: CUSTOMER, fromMe: true, status: 1 },
                { id: 'IN', remoteJid: CUSTOMER, fromMe: false, status: 4 },
                {
                    id: 'GR',
                    remoteJid: '120363025246125486@g.us',
                    fromMe: true,
                    status: 3,
                },
            ],
        });
        await harness.settle();

        assert.deepEqual(
            ofType('message.status').map((event) => [
                (event.data as { provider_message_id: string })
                    .provider_message_id,
                (event.data as { status: string }).status,
            ]),
            [
                ['M1', 'sent'],
                ['M1', 'delivered'],
                ['M1', 'read'],
                ['M2', 'failed'],
            ],
        );
        assert.equal(
            (ofType('message.status')[3]?.data as { error: { code: string } })
                .error.code,
            'send_failed',
        );
    });
});

describe('envío de mensajes (§4)', () => {
    let harness: Harness;
    let socket: FakeSocket;

    beforeEach(async () => {
        harness = await startHarness();
        socket = await linkSession(harness, 1);
        socket.directory.set('525511112222', CUSTOMER);
    });

    afterEach(async () => {
        await harness.close();
        await harness.removeData();
    });

    const send = (body: Record<string, unknown>, connectionId = 1) =>
        harness.call('POST', `/v1/sessions/${connectionId}/messages`, body);

    test('envía texto al JID que WhatsApp registró para el número', async () => {
        const response = await send({
            idempotency_key: 'cm-msg-10',
            to: '+525511112222',
            type: 'text',
            text: 'Hola',
        });

        assert.equal(response.status, 200);
        assert.match(response.json.provider_message_id, /^3EB0[0-9A-F]{28}$/);
        assert.equal(response.json.timestamp, 1_700_000_000);
        assert.equal(socket.sentMessages.length, 1);
        assert.equal(
            socket.sentMessages[0]?.jid,
            CUSTOMER,
            'el número mexicano sin «1» se resuelve al JID real',
        );
        assert.equal(socket.sentMessages[0]?.content.text, 'Hola');
        assert.equal(
            (await harness.call('GET', '/v1/sessions/1')).json.last_outbound_at,
            1_700_000_000,
        );
    });

    test('repetir la clave de idempotencia no reenvía: devuelve el resultado anterior', async () => {
        const first = await send({
            idempotency_key: 'cm-msg-11',
            to: '525511112222',
            type: 'text',
            text: 'Una vez',
        });
        const second = await send({
            idempotency_key: 'cm-msg-11',
            to: '525511112222',
            type: 'text',
            text: 'Una vez',
        });

        assert.deepEqual(second.json, first.json);
        assert.equal(socket.sentMessages.length, 1);

        const [parallelA, parallelB] = await Promise.all([
            send({
                idempotency_key: 'cm-msg-12',
                to: '525511112222',
                type: 'text',
                text: 'A la vez',
            }),
            send({
                idempotency_key: 'cm-msg-12',
                to: '525511112222',
                type: 'text',
                text: 'A la vez',
            }),
        ]);

        assert.deepEqual(parallelA.json, parallelB.json);
        assert.equal(socket.sentMessages.length, 2);
    });

    test('la idempotencia sobrevive a un reinicio del servicio', async () => {
        const first = await send({
            idempotency_key: 'cm-msg-13',
            to: '525511112222',
            type: 'text',
            text: 'Antes del reinicio',
        });

        await harness.close();

        const restarted = await startHarness({
            dataDir: harness.dataDir,
            sessionKey: harness.config.sessionKey,
        });

        try {
            await flush();
            restarted.whatsapp.last.emit({
                type: 'open',
                me: { jid: '5215512345678:7@s.whatsapp.net', name: 'Central' },
            });
            await restarted.settle();

            const again = await restarted.call(
                'POST',
                '/v1/sessions/1/messages',
                {
                    idempotency_key: 'cm-msg-13',
                    to: '525511112222',
                    type: 'text',
                    text: 'Antes del reinicio',
                },
            );

            assert.deepEqual(again.json, first.json);
            assert.equal(restarted.whatsapp.last.sentMessages.length, 0);

            // Y sigue reconociendo como propio el mensaje enviado antes de reiniciar.
            restarted.events.length = 0;
            restarted.whatsapp.last.emit({
                type: 'messages',
                upsert: 'notify',
                messages: [
                    textMessage(
                        first.json.provider_message_id,
                        CUSTOMER,
                        'Antes del reinicio',
                        { fromMe: true },
                    ),
                ],
            });
            await restarted.settle();
            assert.equal(restarted.events.length, 0);
        } finally {
            await restarted.close();
            harness = restarted;
        }
    });

    test('sin sesión conectada responde 409 con el estado real y no envía', async () => {
        await harness.call('POST', '/v1/sessions/1/stop');

        const response = await send({
            idempotency_key: 'cm-msg-14',
            to: '525511112222',
            type: 'text',
            text: 'Hola',
        });

        assert.equal(response.status, 409);
        assert.deepEqual(response.json, {
            error: 'not_connected',
            state: 'disconnected',
        });
        assert.equal(socket.sentMessages.length, 0);
        assert.equal(
            (
                await send(
                    {
                        idempotency_key: 'x',
                        to: '525511112222',
                        type: 'text',
                        text: 'Hola',
                    },
                    99,
                )
            ).status,
            404,
        );
    });

    test('rechaza destinatarios inválidos, números sin WhatsApp, grupos y tipos no soportados', async () => {
        const cases: [Record<string, unknown>, number, string][] = [
            [
                { idempotency_key: 'a', to: 'abc', type: 'text', text: 'x' },
                422,
                'invalid_recipient',
            ],
            [
                { idempotency_key: 'b', to: '5599', type: 'text', text: 'x' },
                422,
                'invalid_recipient',
            ],
            [
                {
                    idempotency_key: 'c',
                    to: '529990000000',
                    type: 'text',
                    text: 'x',
                },
                422,
                'invalid_recipient',
            ],
            [
                {
                    idempotency_key: 'd',
                    to: '120363025246125486@g.us',
                    type: 'text',
                    text: 'x',
                },
                422,
                'invalid_recipient',
            ],
            [
                {
                    idempotency_key: 'e',
                    to: 'status@broadcast',
                    type: 'text',
                    text: 'x',
                },
                422,
                'invalid_recipient',
            ],
            [
                {
                    idempotency_key: 'f',
                    to: '525511112222',
                    type: 'poll',
                    text: 'x',
                },
                422,
                'unsupported_type',
            ],
            [
                { idempotency_key: 'g', to: '525511112222', type: 'text' },
                422,
                'invalid_request',
            ],
            [
                { idempotency_key: 'h', to: '525511112222', type: 'image' },
                422,
                'invalid_request',
            ],
            [
                { to: '525511112222', type: 'text', text: 'x' },
                422,
                'invalid_request',
            ],
        ];

        for (const [body, status, error] of cases) {
            const response = await send(body);

            assert.equal(response.status, status, JSON.stringify(body));
            assert.equal(response.json.error, error, JSON.stringify(body));
        }

        assert.equal(socket.sentMessages.length, 0);
    });

    test('un envío sin respuesta es ambiguo (504) y el reintento usa el mismo identificador', async () => {
        socket.sendBehaviour = () => new Promise(() => undefined);

        const timedOut = await send({
            idempotency_key: 'cm-msg-15',
            to: '525511112222',
            type: 'text',
            text: 'Sin confirmar',
        });

        assert.equal(timedOut.status, 504);
        assert.deepEqual(timedOut.json, { error: 'send_timeout' });

        socket.sendBehaviour = async (messageId) => ({
            id: messageId,
            timestamp: 1_700_000_100,
        });

        const retried = await send({
            idempotency_key: 'cm-msg-15',
            to: '525511112222',
            type: 'text',
            text: 'Sin confirmar',
        });

        assert.equal(retried.status, 200);
        assert.equal(socket.sentMessages.length, 2);
        assert.equal(
            socket.sentMessages[0]?.messageId,
            socket.sentMessages[1]?.messageId,
            'WhatsApp lo trata como el mismo mensaje',
        );
        assert.equal(
            retried.json.provider_message_id,
            socket.sentMessages[0]?.messageId,
        );
    });

    test('un fallo de la biblioteca responde 500 sin tumbar la sesión', async () => {
        socket.sendBehaviour = async () => {
            throw new Error('boom 5215511112222');
        };

        const failed = await send({
            idempotency_key: 'cm-msg-16',
            to: '525511112222',
            type: 'text',
            text: 'Hola',
        });

        assert.equal(failed.status, 500);
        assert.deepEqual(failed.json, { error: 'internal' });
        assert.equal(
            (await harness.call('GET', '/v1/sessions/1')).json.state,
            'connected',
        );
    });

    test('envía archivos, notas de voz y respuestas citadas', async () => {
        const media = {
            url: 'http://127.0.0.1/internal/whatsapp-web/media/7?ts=1&nonce=n&sig=s',
            mime: 'image/jpeg',
            file_name: 'foto.jpg',
        };

        await send({
            idempotency_key: 'm1',
            to: '525511112222',
            type: 'image',
            text: 'Pie de foto',
            media,
        });
        await send({
            idempotency_key: 'm2',
            to: '525511112222',
            type: 'audio',
            media: { ...media, mime: 'audio/ogg; codecs=opus', voice: true },
        });
        await send({
            idempotency_key: 'm3',
            to: '525511112222',
            type: 'document',
            media: {
                ...media,
                mime: 'application/pdf',
                file_name: 'contrato.pdf',
            },
        });
        await send({
            idempotency_key: 'm4',
            to: CUSTOMER,
            type: 'text',
            text: 'Te respondo',
            quoted: {
                provider_message_id: 'ABC123',
                from_me: false,
                remote_jid: CUSTOMER,
                text: 'Hola',
            },
        });

        const [image, audio, document, reply] = socket.sentMessages;

        assert.equal(image?.content.kind, 'image');
        assert.equal(image?.content.text, 'Pie de foto');
        assert.ok(image?.content.filePath?.endsWith('outgoing-file'));
        assert.equal(audio?.content.voice, true);
        assert.equal(audio?.content.mime, 'audio/ogg; codecs=opus');
        assert.equal(document?.content.fileName, 'contrato.pdf');
        assert.equal(reply?.jid, CUSTOMER, 'un JID completo se usa tal cual');
        assert.deepEqual(reply?.quoted, {
            id: 'ABC123',
            remoteJid: CUSTOMER,
            fromMe: false,
            text: 'Hola',
        });
    });

    test('una conexión nunca envía por la sesión de otra', async () => {
        const other = await linkSession(harness, 2, '5215500000002');

        other.directory.set('525511112222', CUSTOMER);
        await send(
            {
                idempotency_key: 'cm-msg-20',
                to: '525511112222',
                type: 'text',
                text: 'Por la 2',
            },
            2,
        );

        assert.equal(other.sentMessages.length, 1);
        assert.equal(socket.sentMessages.length, 0);

        // La misma clave en otra conexión es otro mensaje.
        const onFirst = await send(
            {
                idempotency_key: 'cm-msg-20',
                to: '525511112222',
                type: 'text',
                text: 'Por la 1',
            },
            1,
        );

        assert.equal(socket.sentMessages.length, 1);
        assert.notEqual(
            onFirst.json.provider_message_id,
            other.sentMessages[0]?.messageId,
        );
    });
});

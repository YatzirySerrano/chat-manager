import assert from 'node:assert/strict';
import { afterEach, beforeEach, describe, test } from 'node:test';
import { linkSession, startHarness, type FakeSocket } from './helpers.js';

type Harness = Awaited<ReturnType<typeof startHarness>>;

const CUSTOMER = '5215511112222@s.whatsapp.net';

describe('perfil de un contacto (§4)', () => {
    let harness: Harness;
    let socket: FakeSocket;

    beforeEach(async () => {
        harness = await startHarness();
        socket = await linkSession(harness, 1);
        socket.directory.set('5215511112222', CUSTOMER);
    });

    afterEach(async () => {
        await harness.close();
        await harness.removeData();
    });

    test('devuelve la foto, la info y el perfil de empresa', async () => {
        socket.profileBehaviour = async () => ({
            picture_url: 'https://pps.whatsapp.net/v/t61/foto.jpg?oe=1',
            about: 'Disponible',
            about_set_at: 1_690_000_000,
            business: {
                category: 'Tienda',
                description: 'Abarrotes',
                email: null,
                website: ['https://ejemplo.mx'],
                address: null,
                hours: null,
            },
        });

        const response = await harness.call('POST', '/v1/sessions/1/profile', {
            to: '5215511112222',
        });

        assert.equal(response.status, 200);
        assert.equal(response.headers.get('cache-control'), 'no-store');
        assert.deepEqual(response.json, {
            picture_url: 'https://pps.whatsapp.net/v/t61/foto.jpg?oe=1',
            about: 'Disponible',
            about_set_at: 1_690_000_000,
            business: {
                category: 'Tienda',
                description: 'Abarrotes',
                email: null,
                website: ['https://ejemplo.mx'],
                address: null,
                hours: null,
            },
        });
        assert.deepEqual(socket.profileQueries, [CUSTOMER]);
    });

    test('un perfil privado llega con todo en null', async () => {
        const response = await harness.call('POST', '/v1/sessions/1/profile', {
            to: CUSTOMER,
        });

        assert.equal(response.status, 200);
        assert.deepEqual(response.json, {
            picture_url: null,
            about: null,
            about_set_at: null,
            business: null,
        });
    });

    test('el mismo contacto pedido varias veces seguidas se consulta a WhatsApp una vez', async () => {
        await Promise.all([
            harness.call('POST', '/v1/sessions/1/profile', { to: CUSTOMER }),
            harness.call('POST', '/v1/sessions/1/profile', { to: CUSTOMER }),
        ]);
        await harness.call('POST', '/v1/sessions/1/profile', {
            to: '5215511112222',
        });

        assert.equal(socket.profileQueries.length, 1);
    });

    test('exige firma, una sesión conectada y un destinatario válido', async () => {
        assert.equal(
            (
                await harness.call(
                    'POST',
                    '/v1/sessions/1/profile',
                    { to: CUSTOMER },
                    { sign: false },
                )
            ).status,
            401,
        );
        assert.equal(
            (await harness.call('POST', '/v1/sessions/1/profile', {})).status,
            422,
        );
        assert.equal(
            (
                await harness.call('POST', '/v1/sessions/1/profile', {
                    to: '123-abc',
                })
            ).json.error,
            'invalid_recipient',
        );
        assert.equal(
            (
                await harness.call('POST', '/v1/sessions/9/profile', {
                    to: CUSTOMER,
                })
            ).status,
            404,
        );

        await harness.call('POST', '/v1/sessions/1/stop');

        const stopped = await harness.call('POST', '/v1/sessions/1/profile', {
            to: CUSTOMER,
        });

        assert.equal(stopped.status, 409);
        assert.equal(stopped.json.error, 'not_connected');
    });
});

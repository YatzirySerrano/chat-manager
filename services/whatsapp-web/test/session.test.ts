import assert from 'node:assert/strict';
import { existsSync } from 'node:fs';
import { join } from 'node:path';
import { afterEach, beforeEach, describe, test } from 'node:test';
import { flush, linkSession, startHarness } from './helpers.js';

type Harness = Awaited<ReturnType<typeof startHarness>>;

describe('máquina de estados de la sesión (§3)', () => {
    let harness: Harness;

    beforeEach(async () => {
        harness = await startHarness();
    });

    afterEach(async () => {
        await harness.close();
        await harness.removeData();
    });

    const states = (connectionId: number): string[] =>
        harness.events
            .filter(
                (event) =>
                    event.type === 'session.state' &&
                    event.connection_id === connectionId,
            )
            .map((event) => (event.data as { state: string }).state);

    const state = async (connectionId: number): Promise<any> =>
        (await harness.call('GET', `/v1/sessions/${connectionId}`)).json;

    test('una conexión desconocida está sin configurar y no se puede restaurar', async () => {
        assert.equal((await state(9)).state, 'unconfigured');

        const restore = await harness.call('POST', '/v1/sessions/9/start', {
            mode: 'restore',
        });

        assert.equal(restore.status, 409);
        assert.deepEqual(restore.json, { error: 'not_linked' });
        assert.equal(
            (await harness.call('POST', '/v1/sessions/9/stop')).status,
            404,
        );
        assert.equal(harness.whatsapp.sockets.length, 0);
    });

    test('vinculación completa: starting, qr_pending, pairing, connected', async () => {
        const started = await harness.call('POST', '/v1/sessions/1/start', {
            mode: 'link',
        });

        assert.equal(started.json.state, 'starting');

        harness.whatsapp.last.emit({
            type: 'qr',
            value: 'qr-uno',
            ttlMs: 60_000,
        });
        await flush();

        const pending = await state(1);

        assert.equal(pending.state, 'qr_pending');
        assert.deepEqual(pending.qr, {
            value: 'qr-uno',
            expires_at: 1_700_000_060,
        });

        harness.whatsapp.last.emit({ type: 'pairing' });
        await flush();
        assert.equal((await state(1)).state, 'pairing');
        assert.equal((await state(1)).qr, null);

        // WhatsApp always closes with "restart required" after pairing: a new socket, no new QR.
        harness.whatsapp.last.emit({
            type: 'close',
            statusCode: 515,
            detail: 'restart required',
        });
        await flush();
        assert.equal(harness.whatsapp.sockets.length, 2);
        assert.equal((await state(1)).state, 'pairing');

        harness.whatsapp.last.emit({
            type: 'open',
            me: { jid: '5215512345678:7@s.whatsapp.net', name: 'Central' },
        });
        await harness.settle();

        const connected = await state(1);

        assert.equal(connected.state, 'connected');
        assert.equal(connected.phone, '5215512345678');
        assert.equal(connected.push_name, 'Central');
        assert.equal(connected.connected_since, 1_700_000_000);
        assert.equal(connected.qr, null);
        assert.equal(states(1).at(-1), 'connected');
        assert.ok(states(1).includes('starting'));
    });

    test('pedir vincular una sesión activa o ya vinculada no la reinicia', async () => {
        await linkSession(harness, 1);

        const again = await harness.call('POST', '/v1/sessions/1/start', {
            mode: 'link',
        });

        assert.equal(again.json.state, 'connected');
        assert.equal(harness.whatsapp.sockets.length, 2);

        await harness.call('POST', '/v1/sessions/1/stop');

        const stopped = await harness.call('POST', '/v1/sessions/1/start', {
            mode: 'link',
        });

        assert.equal(
            stopped.json.state,
            'disconnected',
            'las credenciales vigentes no se descartan por pedir un QR',
        );
        assert.equal(harness.whatsapp.sockets.length, 2);
    });

    test('el QR se renueva y, agotadas las renovaciones, la sesión vuelve a sin configurar', async () => {
        await harness.call('POST', '/v1/sessions/1/start', { mode: 'link' });

        const socket = harness.whatsapp.last;

        socket.emit({ type: 'qr', value: 'qr-1', ttlMs: 60_000 });
        socket.emit({ type: 'qr', value: 'qr-2', ttlMs: 20_000 });
        await flush();
        assert.deepEqual((await state(1)).qr, {
            value: 'qr-2',
            expires_at: 1_700_000_020,
        });

        socket.emit({ type: 'qr', value: 'qr-3', ttlMs: 20_000 });
        await flush();
        assert.equal((await state(1)).qr.value, 'qr-3');

        // WAWEB_QR_MAX_REFRESH = 2: el cuarto código ya no se ofrece.
        socket.emit({ type: 'qr', value: 'qr-4', ttlMs: 20_000 });
        await harness.settle();

        const ended = await state(1);

        assert.equal(ended.state, 'unconfigured');
        assert.equal(ended.qr, null);
        assert.equal(ended.last_error.code, 'qr_timeout');
        assert.equal(socket.closed, true);
        assert.equal(
            existsSync(join(harness.dataDir, 'sessions', '1', 'auth')),
            false,
        );

        const last = harness.events
            .filter((event) => event.type === 'session.state')
            .at(-1);

        assert.equal((last?.data as { reason?: string }).reason, 'qr_timeout');
        assert.equal(
            harness.timers.pending,
            0,
            'no se reintenta por su cuenta',
        );
    });

    test('si la biblioteca agota sus códigos (408) también termina en qr_timeout', async () => {
        await harness.call('POST', '/v1/sessions/1/start', { mode: 'link' });
        harness.whatsapp.last.emit({
            type: 'qr',
            value: 'qr-1',
            ttlMs: 60_000,
        });
        harness.whatsapp.last.emit({
            type: 'close',
            statusCode: 408,
            detail: 'QR refs attempts ended',
        });
        await harness.settle();

        assert.equal((await state(1)).state, 'unconfigured');
        assert.equal((await state(1)).last_error.code, 'qr_timeout');
        assert.equal(harness.timers.pending, 0);
    });

    test('reconexión con backoff exponencial y jitter, y vuelta a conectada', async () => {
        await linkSession(harness, 1);

        harness.whatsapp.last.emit({
            type: 'close',
            statusCode: 428,
            detail: 'connection closed',
        });
        await flush();
        assert.equal((await state(1)).state, 'reconnecting');
        assert.equal((await state(1)).connected_since, null);
        assert.equal((await state(1)).reconnects, 1);
        assert.deepEqual(harness.timers.delays, [2000]);

        harness.timers.runNext();
        await flush();
        assert.equal(harness.whatsapp.sockets.length, 3);

        harness.whatsapp.last.emit({
            type: 'close',
            statusCode: 503,
            detail: 'unavailable',
        });
        await flush();
        assert.deepEqual(
            harness.timers.delays,
            [4000],
            'el segundo intento espera el doble',
        );

        harness.timers.runNext();
        await flush();
        harness.whatsapp.last.emit({
            type: 'open',
            me: { jid: '5215512345678:7@s.whatsapp.net', name: 'Central' },
        });
        await harness.settle();

        const back = await state(1);

        assert.equal(back.state, 'connected');
        assert.equal(back.reconnects, 2);
        assert.equal(back.last_error, null);

        // El contador de intentos se reinició: la siguiente caída vuelve a empezar en 2 s.
        harness.whatsapp.last.emit({
            type: 'close',
            statusCode: 428,
            detail: 'connection closed',
        });
        await flush();
        assert.deepEqual(harness.timers.delays, [2000]);
    });

    test('el jitter mueve la espera dentro de ±25 %', async () => {
        await harness.close();
        await harness.removeData();

        const random = { value: 0 };
        const delays: number[] = [];

        harness = await startHarness({
            dependencies: {
                session: {
                    schedule: (_task, ms) => {
                        delays.push(ms);

                        return () => undefined;
                    },
                    random: () => random.value,
                    now: () => 1_700_000_000_000,
                },
            },
        });
        await linkSession(harness, 1);

        harness.whatsapp.last.emit({
            type: 'close',
            statusCode: 428,
            detail: 'closed',
        });
        await flush();
        assert.deepEqual(delays, [1500], 'el mínimo es el 75 % de 2 s');

        random.value = 0.999;
        await harness.call('POST', '/v1/sessions/1/stop');
        await harness.call('POST', '/v1/sessions/1/start', { mode: 'restore' });
        harness.whatsapp.last.emit({
            type: 'close',
            statusCode: 428,
            detail: 'closed',
        });
        await flush();
        assert.ok(
            (delays[1] as number) > 2400 && (delays[1] as number) <= 2500,
            'el máximo es el 125 % de 2 s',
        );
    });

    test('agotados los reintentos queda en error y ya no insiste', async () => {
        await linkSession(harness, 1);

        // WAWEB_RECONNECT_MAX_ATTEMPTS = 3 en las pruebas.
        for (let attempt = 0; attempt < 3; attempt++) {
            harness.whatsapp.last.emit({
                type: 'close',
                statusCode: 408,
                detail: 'timed out',
            });
            await flush();
            assert.equal((await state(1)).state, 'reconnecting');
            harness.timers.runNext();
            await flush();
        }

        harness.whatsapp.last.emit({
            type: 'close',
            statusCode: 408,
            detail: 'timed out',
        });
        await harness.settle();

        const failed = await state(1);

        assert.equal(failed.state, 'error');
        assert.equal(failed.last_error.code, 'network');
        assert.equal(harness.timers.pending, 0);

        // «Reconectar» sigue disponible: las credenciales no se perdieron.
        const retried = await harness.call('POST', '/v1/sessions/1/start', {
            mode: 'restore',
        });

        assert.equal(retried.json.state, 'starting');
    });

    test('si no se puede abrir el socket se trata como una caída de red', async () => {
        await linkSession(harness, 1);
        await harness.call('POST', '/v1/sessions/1/stop');

        harness.whatsapp.failNextOpen = new Error(
            'getaddrinfo ENOTFOUND web.whatsapp.com',
        );

        const restored = await harness.call('POST', '/v1/sessions/1/start', {
            mode: 'restore',
        });

        assert.equal(restored.json.state, 'reconnecting');
        assert.equal(harness.timers.pending, 1);
    });

    test('sesión revocada desde el teléfono: logged_out, sin credenciales y sin regenerarse sola', async () => {
        await linkSession(harness, 1);

        harness.whatsapp.last.emit({
            type: 'close',
            statusCode: 401,
            detail: 'Connection Failure',
        });
        await harness.settle();

        const revoked = await state(1);

        assert.equal(revoked.state, 'logged_out');
        assert.equal(revoked.last_error.code, 'logged_out_from_phone');
        assert.equal(revoked.phone, null);
        assert.equal(
            existsSync(join(harness.dataDir, 'sessions', '1', 'auth')),
            false,
        );
        assert.equal(harness.timers.pending, 0);
        assert.equal(
            harness.whatsapp.sockets.length,
            2,
            'no se abrió ningún socket nuevo',
        );

        const reason = harness.events
            .filter((event) => event.type === 'session.state')
            .at(-1);

        assert.equal(
            (reason?.data as { reason?: string }).reason,
            'logged_out_from_phone',
        );
        assert.deepEqual(
            (
                await harness.call('POST', '/v1/sessions/1/start', {
                    mode: 'restore',
                })
            ).json,
            { error: 'not_linked' },
        );

        // Solo una vinculación explícita genera un QR nuevo.
        assert.equal(
            (
                await harness.call('POST', '/v1/sessions/1/start', {
                    mode: 'link',
                })
            ).json.state,
            'starting',
        );
    });

    test('un número rechazado por WhatsApp (403) también exige vincular de nuevo', async () => {
        await linkSession(harness, 1);
        harness.whatsapp.last.emit({
            type: 'close',
            statusCode: 403,
            detail: 'forbidden',
        });
        await harness.settle();

        assert.equal((await state(1)).state, 'logged_out');
        assert.equal((await state(1)).last_error.code, 'banned');
        assert.equal(harness.timers.pending, 0);
    });

    test('sesión reemplazada por otra instancia: error, sin pelear por la conexión', async () => {
        await linkSession(harness, 1);
        harness.whatsapp.last.emit({
            type: 'close',
            statusCode: 440,
            detail: 'conflict',
        });
        await harness.settle();

        assert.equal((await state(1)).state, 'error');
        assert.equal((await state(1)).last_error.code, 'replaced');
        assert.equal(harness.timers.pending, 0);
        assert.equal(
            existsSync(join(harness.dataDir, 'sessions', '1', 'auth')),
            true,
            'las credenciales se conservan',
        );
    });

    test('desconectar temporalmente conserva las credenciales y reconectar no pide QR', async () => {
        const socket = await linkSession(harness, 1);
        const stopped = await harness.call('POST', '/v1/sessions/1/stop');

        assert.equal(stopped.json.state, 'disconnected');
        assert.equal(socket.closed, true);
        assert.equal(socket.loggedOut, false);
        assert.equal(
            existsSync(join(harness.dataDir, 'sessions', '1', 'auth')),
            true,
        );

        // Un cierre tardío del socket anterior no cambia nada.
        socket.emit({ type: 'close', statusCode: 428, detail: 'closed' });
        await flush();
        assert.equal((await state(1)).state, 'disconnected');
        assert.equal(harness.timers.pending, 0);

        const restored = await harness.call('POST', '/v1/sessions/1/start', {
            mode: 'restore',
        });

        assert.equal(restored.json.state, 'starting');
        harness.whatsapp.last.emit({
            type: 'open',
            me: { jid: '5215512345678:7@s.whatsapp.net', name: 'Central' },
        });
        await harness.settle();
        assert.equal((await state(1)).state, 'connected');
        assert.ok(
            !states(1)
                .slice(states(1).lastIndexOf('disconnected'))
                .includes('qr_pending'),
        );
    });

    test('cancelar una vinculación a medias no deja nada', async () => {
        await harness.call('POST', '/v1/sessions/1/start', { mode: 'link' });
        harness.whatsapp.last.emit({
            type: 'qr',
            value: 'qr-1',
            ttlMs: 60_000,
        });
        await flush();

        const cancelled = await harness.call('POST', '/v1/sessions/1/stop');

        assert.equal(cancelled.json.state, 'unconfigured');
        assert.equal(harness.whatsapp.last.closed, true);
        assert.equal(
            existsSync(join(harness.dataDir, 'sessions', '1', 'auth')),
            false,
        );
    });

    test('cerrar sesión avisa a WhatsApp, borra las credenciales y exige vincular de nuevo', async () => {
        const socket = await linkSession(harness, 1);
        const response = await harness.call('POST', '/v1/sessions/1/logout');

        assert.equal(response.json.state, 'logged_out');
        assert.equal(socket.loggedOut, true);
        assert.equal(socket.closed, true);
        assert.equal(
            existsSync(join(harness.dataDir, 'sessions', '1', 'auth')),
            false,
        );
        assert.deepEqual(
            (
                await harness.call('POST', '/v1/sessions/1/start', {
                    mode: 'restore',
                })
            ).json,
            { error: 'not_linked' },
        );
    });

    test('eliminar borra todo lo de la conexión y solo lo de ella', async () => {
        await linkSession(harness, 1);
        await linkSession(harness, 2, '5215587654321');

        const deleted = await harness.call('DELETE', '/v1/sessions/1');

        assert.deepEqual(deleted.json, { deleted: true });
        assert.equal(existsSync(join(harness.dataDir, 'sessions', '1')), false);
        assert.equal(existsSync(join(harness.dataDir, 'sessions', '2')), true);
        assert.equal((await state(1)).state, 'unconfigured');
        assert.equal((await state(2)).state, 'connected');
        assert.equal((await state(2)).phone, '5215587654321');
    });

    test('cada conexión tiene su socket, su estado y sus eventos', async () => {
        const first = await linkSession(harness, 1);
        const second = await linkSession(harness, 2, '5215587654321');

        first.emit({ type: 'close', statusCode: 401, detail: 'logged out' });
        await harness.settle();

        assert.equal((await state(1)).state, 'logged_out');
        assert.equal((await state(2)).state, 'connected');
        assert.equal(second.closed, false);
        assert.deepEqual(
            [
                ...new Set(
                    harness.events
                        .filter(
                            (event) =>
                                (event.data as { state?: string }).state ===
                                'logged_out',
                        )
                        .map((event) => event.connection_id),
                ),
            ],
            [1],
        );

        const status = await harness.call('GET', '/v1/status');

        assert.deepEqual(
            status.json.sessions.map(
                (session: { connection_id: number; state: string }) => [
                    session.connection_id,
                    session.state,
                ],
            ),
            [
                [1, 'logged_out'],
                [2, 'connected'],
            ],
        );
    });
});

describe('reinicio del servicio', () => {
    test('restaura las sesiones que estaban en marcha, no las detenidas ni las revocadas', async () => {
        const first = await startHarness();

        await linkSession(first, 1);
        await linkSession(first, 2, '5215500000002');
        await linkSession(first, 3, '5215500000003');
        await first.call('POST', '/v1/sessions/2/stop');
        first.whatsapp.sockets[5]?.emit({
            type: 'close',
            statusCode: 401,
            detail: 'logged out',
        });
        await first.settle();
        await first.close();

        // Apagar el servicio cierra los sockets sin cerrar sesión en ningún dispositivo.
        assert.equal(
            first.whatsapp.sockets.some((socket) => socket.loggedOut),
            false,
        );

        const second = await startHarness({
            dataDir: first.dataDir,
            sessionKey: first.config.sessionKey,
        });

        await flush();

        try {
            assert.equal(
                second.whatsapp.sockets.length,
                1,
                'solo la conexión 1 se reabre',
            );
            assert.equal(
                (await second.call('GET', '/v1/sessions/1')).json.state,
                'starting',
            );
            assert.equal(
                (await second.call('GET', '/v1/sessions/1')).json.phone,
                '5215512345678',
            );
            assert.equal(
                (await second.call('GET', '/v1/sessions/2')).json.state,
                'disconnected',
            );
            assert.equal(
                (await second.call('GET', '/v1/sessions/3')).json.state,
                'logged_out',
            );

            second.whatsapp.last.emit({
                type: 'open',
                me: { jid: '5215512345678:7@s.whatsapp.net', name: 'Central' },
            });
            await second.settle();
            assert.equal(
                (await second.call('GET', '/v1/sessions/1')).json.state,
                'connected',
            );
        } finally {
            await second.close();
            await second.removeData();
        }
    });
});

import assert from 'node:assert/strict';
import { createHash, createHmac } from 'node:crypto';
import { after, before, describe, test } from 'node:test';
import {
    NonceCache,
    signature,
    signedHeaders,
    verify,
} from '../src/signing.js';
import { SECRET, startHarness } from './helpers.js';

describe('firma interna (§2)', () => {
    const base = {
        secret: SECRET,
        method: 'POST',
        path: '/v1/sessions/1/start',
        body: Buffer.from('{"mode":"link"}'),
    };
    const now = 1_700_000_000_000;
    const timestamp = '1700000000';
    const nonce = '7b1f6f1e-0b7c-4d0a-9d2e-2a1f1c3b4d5e';

    test('la firma es el HMAC-SHA256 del mensaje descrito en el contrato', () => {
        const message = `${timestamp}.${nonce}.POST.${base.path}.${createHash('sha256').update(base.body).digest('hex')}`;

        assert.equal(
            signature(SECRET, timestamp, nonce, 'post', base.path, base.body),
            createHmac('sha256', SECRET).update(message).digest('hex'),
        );
    });

    test('acepta una petición auténtica y rechaza cada alteración', () => {
        const valid = signature(
            SECRET,
            timestamp,
            nonce,
            base.method,
            base.path,
            base.body,
        );
        const check = (overrides: Record<string, unknown>) =>
            verify({
                ...base,
                timestamp,
                nonce,
                signature: valid,
                nonces: new NonceCache(),
                now,
                ...overrides,
            });

        assert.equal(check({}), null);
        assert.equal(check({ secret: '' }), 'disabled');
        assert.equal(check({ signature: undefined }), 'missing');
        assert.equal(check({ nonce: undefined }), 'missing');
        assert.equal(check({ timestamp: undefined }), 'missing');
        assert.equal(
            check({ body: Buffer.from('{"mode":"restore"}') }),
            'mismatch',
        );
        assert.equal(check({ path: '/v1/sessions/2/start' }), 'mismatch');
        assert.equal(check({ method: 'DELETE' }), 'mismatch');
        assert.equal(check({ secret: SECRET + 'x' }), 'mismatch');
        assert.equal(check({ signature: 'zz' }), 'mismatch');
        assert.equal(check({ now: now + 61_000 }), 'stale');
        assert.equal(check({ now: now - 61_000 }), 'stale');
        assert.equal(check({ now: now + 59_000 }), null);
    });

    test('la misma petición no se puede reproducir dentro de la ventana', () => {
        const nonces = new NonceCache();
        const valid = signature(
            SECRET,
            timestamp,
            nonce,
            base.method,
            base.path,
            base.body,
        );
        const input = {
            ...base,
            timestamp,
            nonce,
            signature: valid,
            nonces,
            now,
        };

        assert.equal(verify(input), null);
        assert.equal(verify(input), 'replayed');
    });

    test('una firma falsa no consume el nonce de la petición legítima', () => {
        const nonces = new NonceCache();
        const valid = signature(
            SECRET,
            timestamp,
            nonce,
            base.method,
            base.path,
            base.body,
        );

        assert.equal(
            verify({
                ...base,
                timestamp,
                nonce,
                signature: 'a'.repeat(64),
                nonces,
                now,
            }),
            'mismatch',
        );
        assert.equal(
            verify({
                ...base,
                timestamp,
                nonce,
                signature: valid,
                nonces,
                now,
            }),
            null,
        );
    });

    test('los nonces caducan a los cinco minutos', () => {
        const nonces = new NonceCache(300);

        assert.equal(nonces.claim('a', 1000), true);
        assert.equal(nonces.claim('a', 1299), false);
        assert.equal(nonces.claim('a', 1301), true);
        assert.equal(nonces.size, 1);
    });
});

describe('API de control: autenticación', () => {
    let harness: Awaited<ReturnType<typeof startHarness>>;

    before(async () => {
        harness = await startHarness();
    });

    after(async () => {
        await harness.close();
        await harness.removeData();
    });

    test('/health responde sin firma y sin datos', async () => {
        const response = await harness.call('GET', '/health', undefined, {
            sign: false,
        });

        assert.equal(response.status, 200);
        assert.deepEqual(response.json, { status: 'ok' });
    });

    test('todo lo demás exige firma', async () => {
        for (const [method, path] of [
            ['GET', '/v1/status'],
            ['GET', '/v1/sessions/1'],
            ['POST', '/v1/sessions/1/start'],
            ['DELETE', '/v1/sessions/1'],
        ] as const) {
            const response = await harness.call(
                method,
                path,
                method === 'POST' ? { mode: 'link' } : undefined,
                { sign: false },
            );

            assert.equal(response.status, 401, `${method} ${path}`);
            assert.deepEqual(response.json, { error: 'unauthorized' });
        }

        assert.equal(harness.whatsapp.sockets.length, 0);
    });

    test('una petición capturada no se puede repetir ni reutilizar para otra ruta', async () => {
        const headers = signedHeaders(SECRET, 'GET', '/v1/status', '');

        assert.equal(
            (
                await harness.call('GET', '/v1/status', undefined, {
                    sign: false,
                    headers,
                })
            ).status,
            200,
        );
        assert.equal(
            (
                await harness.call('GET', '/v1/status', undefined, {
                    sign: false,
                    headers,
                })
            ).status,
            401,
        );
        assert.equal(
            (
                await harness.call('GET', '/v1/sessions/1', undefined, {
                    sign: false,
                    headers: signedHeaders(SECRET, 'GET', '/v1/status', ''),
                })
            ).status,
            401,
        );
    });

    test('el estado del servicio describe la versión y las sesiones', async () => {
        const response = await harness.call('GET', '/v1/status');

        assert.equal(response.status, 200);
        assert.equal(response.json.service, 'chatmanager-whatsapp-web');
        assert.match(response.json.library, /^baileys \d/);
        assert.ok(Array.isArray(response.json.sessions));
    });
});

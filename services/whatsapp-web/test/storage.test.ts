import assert from 'node:assert/strict';
import { randomBytes } from 'node:crypto';
import { existsSync, readFileSync, writeFileSync } from 'node:fs';
import { readdir, readFile } from 'node:fs/promises';
import { join } from 'node:path';
import { describe, test } from 'node:test';
import { createService } from '../src/app.js';
import {
    clearAuthState,
    hasStoredCredentials,
    keyFileName,
    useEncryptedAuthState,
} from '../src/auth-state.js';
import { loadConfig, loadEnvFile } from '../src/config.js';
import { acquireLock, LockError } from '../src/lock.js';
import { maskJid, maskPhone, safeErrorMessage } from '../src/logger.js';
import { Vault, VaultError } from '../src/vault.js';
import {
    FakeWhatsApp,
    linkSession,
    silentLogger,
    startHarness,
    temporaryDirectory,
    testConfig,
} from './helpers.js';

describe('cifrado en reposo (§7)', () => {
    test('lo sellado solo abre con la misma clave y la misma etiqueta, y detecta alteraciones', () => {
        const vault = new Vault(randomBytes(32));
        const sealed = vault.seal(Buffer.from('secreto'), 'creds');

        assert.equal(vault.open(sealed, 'creds').toString(), 'secreto');
        assert.equal(sealed.includes('secreto'), false);
        assert.notDeepEqual(
            vault.seal(Buffer.from('secreto'), 'creds'),
            sealed,
            'cada sellado usa un IV nuevo',
        );
        assert.throws(() => vault.open(sealed, 'otra-etiqueta'), VaultError);
        assert.throws(
            () => new Vault(randomBytes(32)).open(sealed, 'creds'),
            VaultError,
        );

        const tampered = Buffer.from(sealed);

        tampered[tampered.length - 1] =
            (tampered[tampered.length - 1] as number) ^ 1;
        assert.throws(() => vault.open(tampered, 'creds'), VaultError);
        assert.throws(
            () => vault.open(Buffer.from('{"plain":"json"}'), 'creds'),
            VaultError,
        );
        assert.throws(() => new Vault(randomBytes(16)), VaultError);
    });

    test('las credenciales de Baileys no quedan legibles en disco y se recuperan intactas', async () => {
        const temporary = await temporaryDirectory();
        const key = randomBytes(32);

        try {
            const first = await useEncryptedAuthState(
                temporary.path,
                new Vault(key),
            );
            const noiseKey = Buffer.from(first.state.creds.noiseKey.private);

            await first.saveCreds();
            await first.state.keys.set({
                'pre-key': {
                    '12': {
                        public: new Uint8Array([1, 2, 3]),
                        private: new Uint8Array([4, 5, 6]),
                    },
                },
                session: { '5215511112222.0': new Uint8Array([9, 9, 9]) },
                'lid-mapping': { '5215511112222': '99887766@lid' },
            });

            const files = await readdir(temporary.path);

            assert.equal(files.length, 4);
            assert.ok(files.every((file) => file.endsWith('.bin')));

            for (const file of files) {
                const raw = await readFile(join(temporary.path, file));

                assert.equal(raw.subarray(0, 4).toString(), 'CMW1');
                assert.equal(raw.includes('noiseKey'), false);
                assert.equal(raw.includes('private'), false);
                assert.equal(raw.includes('99887766'), false);
                assert.throws(() => JSON.parse(raw.toString('utf8')));
            }

            const second = await useEncryptedAuthState(
                temporary.path,
                new Vault(key),
            );

            assert.deepEqual(
                Buffer.from(second.state.creds.noiseKey.private),
                noiseKey,
            );
            assert.equal(
                second.state.creds.registrationId,
                first.state.creds.registrationId,
            );

            const stored = await second.state.keys.get('pre-key', ['12', '99']);

            assert.deepEqual(
                Buffer.from(stored['12']?.private as Uint8Array),
                Buffer.from([4, 5, 6]),
            );
            assert.equal(stored['99'], undefined);
            assert.deepEqual(
                await second.state.keys.get('lid-mapping', ['5215511112222']),
                { '5215511112222': '99887766@lid' },
            );

            // Un valor nulo borra la clave.
            await second.state.keys.set({
                session: { '5215511112222.0': null },
            });
            assert.deepEqual(
                await second.state.keys.get('session', ['5215511112222.0']),
                {},
            );

            // Con otra clave las credenciales no abren: no se sustituyen en silencio por unas nuevas.
            await assert.rejects(
                useEncryptedAuthState(
                    temporary.path,
                    new Vault(randomBytes(32)),
                ),
                VaultError,
            );

            assert.equal(await hasStoredCredentials(temporary.path), true);
            await clearAuthState(temporary.path);
            assert.equal(await hasStoredCredentials(temporary.path), false);
        } finally {
            await temporary.remove();
        }
    });

    test('los nombres de archivo de las claves solo usan caracteres seguros', () => {
        assert.equal(keyFileName('creds'), 'creds.bin');
        assert.equal(
            keyFileName('session-5215511112222.0'),
            'session-5215511112222.0.bin',
        );
        assert.match(
            keyFileName('sender-key-123@g.us::5215511112222:3'),
            /^[A-Za-z0-9._@~-]+\.bin$/,
        );
        assert.match(
            keyFileName('../../etc/passwd'),
            /^[A-Za-z0-9._@~-]+\.bin$/,
        );
        assert.equal(keyFileName('a/b').includes('/'), false);
        assert.notEqual(keyFileName('a/b'), keyFileName('a:b'));
    });

    test('una sesión vinculada no deja en claro ni credenciales ni su número', async () => {
        const harness = await startHarness();

        try {
            await linkSession(harness, 1, '5215512345678');

            const directory = join(harness.dataDir, 'sessions', '1');
            const files = [
                join(directory, 'meta.bin'),
                ...(await readdir(join(directory, 'auth'))).map((file) =>
                    join(directory, 'auth', file),
                ),
            ];

            assert.ok(files.length >= 2);

            for (const file of files) {
                const raw = readFileSync(file);

                assert.equal(raw.subarray(0, 4).toString(), 'CMW1', file);
                assert.equal(raw.includes('5215512345678'), false);
                assert.equal(raw.includes('linked'), false);
            }
        } finally {
            await harness.close();
            await harness.removeData();
        }
    });
});

describe('instancia única', () => {
    test('un segundo proceso sobre los mismos datos no arranca', async () => {
        const temporary = await temporaryDirectory();
        const path = join(temporary.path, 'service.lock');

        try {
            const release = acquireLock(path, process.pid);

            // Otro proceso vivo (se usa el PID del padre de la prueba) es dueño del bloqueo.
            writeFileSync(path, String(process.ppid));
            assert.throws(() => acquireLock(path, process.pid), LockError);

            // Un bloqueo de un proceso que ya no existe se toma.
            writeFileSync(path, '2147483646');

            const second = acquireLock(path, process.pid);

            assert.equal(readFileSync(path, 'utf8'), String(process.pid));
            second();
            assert.equal(existsSync(path), false);
            release();
        } finally {
            await temporary.remove();
        }
    });

    test('el servicio toma el bloqueo al arrancar y lo suelta al cerrar', async () => {
        const temporary = await temporaryDirectory();
        const dependencies = {
            config: testConfig(temporary.path),
            logger: silentLogger,
            factory: new FakeWhatsApp().factory,
            downloadMedia: async () => {
                throw new Error('unused');
            },
        };

        try {
            const service = await createService(dependencies);
            const lock = join(temporary.path, 'service.lock');

            assert.equal(readFileSync(lock, 'utf8'), String(process.pid));

            // El mismo directorio en manos de otro proceso vivo impide un segundo arranque.
            writeFileSync(lock, String(process.ppid));
            await assert.rejects(createService(dependencies), LockError);
            writeFileSync(lock, String(process.pid));

            await service.listen();
            await service.close();
            assert.equal(existsSync(lock), false);
        } finally {
            await temporary.remove();
        }
    });
});

describe('configuración y registros', () => {
    const valid = {
        WAWEB_SECRET: 'x'.repeat(32),
        WAWEB_LARAVEL_URL: 'https://mensajes.example.test/',
        WAWEB_DATA_DIR: '/var/lib/chatmanager/whatsapp-web',
        WAWEB_SESSION_KEY: randomBytes(32).toString('base64'),
    };

    test('exige secreto, URL de Laravel, directorio y clave de 32 bytes', () => {
        const config = loadConfig({ ...valid });

        assert.equal(config.host, '127.0.0.1');
        assert.equal(config.port, 3410);
        assert.equal(config.laravelUrl, 'https://mensajes.example.test');
        assert.equal(config.qrMaxRefresh, 5);
        assert.equal(config.sessionKey.length, 32);

        assert.throws(
            () => loadConfig({ ...valid, WAWEB_SECRET: 'corto' }),
            /WAWEB_SECRET/,
        );
        assert.throws(
            () => loadConfig({ ...valid, WAWEB_SECRET: undefined }),
            /WAWEB_SECRET/,
        );
        assert.throws(
            () =>
                loadConfig({
                    ...valid,
                    WAWEB_LARAVEL_URL: 'mensajes.example.test',
                }),
            /WAWEB_LARAVEL_URL/,
        );
        assert.throws(
            () => loadConfig({ ...valid, WAWEB_DATA_DIR: '' }),
            /WAWEB_DATA_DIR/,
        );
        assert.throws(
            () => loadConfig({ ...valid, WAWEB_SESSION_KEY: 'YWJj' }),
            /WAWEB_SESSION_KEY/,
        );
        assert.throws(
            () => loadConfig({ ...valid, WAWEB_PORT: '70000' }),
            /WAWEB_PORT/,
        );
    });

    test('el archivo .env no pisa las variables del gestor de procesos', async () => {
        const temporary = await temporaryDirectory();

        try {
            const path = join(temporary.path, '.env');

            writeFileSync(
                path,
                '# comentario\nWAWEB_PORT=4000\nWAWEB_HOST="127.0.0.1"\nWAWEB_LOG_LEVEL=debug\n\nlinea sin formato\n',
            );

            const env: NodeJS.ProcessEnv = { WAWEB_PORT: '3999' };

            loadEnvFile(path, env);
            assert.deepEqual(env, {
                WAWEB_PORT: '3999',
                WAWEB_HOST: '127.0.0.1',
                WAWEB_LOG_LEVEL: 'debug',
            });
            loadEnvFile(join(temporary.path, 'no-existe'), env);
        } finally {
            await temporary.remove();
        }
    });

    test('los identificadores se enmascaran antes de registrarse', () => {
        assert.equal(maskPhone('5215512345678'), '*********5678');
        assert.equal(maskPhone('+52 55 1234 5678'), '********5678');
        assert.equal(maskPhone(null), null);
        assert.equal(
            maskJid('5215512345678:12@s.whatsapp.net'),
            '*********5678@s.whatsapp.net',
        );
        assert.equal(maskJid('99887766554433@lid'), '**********4433@lid');
        assert.equal(
            safeErrorMessage(
                new Error('no session for 5215512345678@s.whatsapp.net\nstack'),
            ),
            'no session for *********5678@s.whatsapp.net',
        );
    });
});

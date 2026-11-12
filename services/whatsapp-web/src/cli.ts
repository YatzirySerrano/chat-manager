import { resolve } from 'node:path';
import { loadEnvFile } from './config.js';
import { signedHeaders } from './signing.js';

/**
 * Diagnostic client of the control API, for the operator of the server:
 *
 *   node dist/cli.js GET /v1/status
 *   node dist/cli.js POST /v1/sessions/12/stop
 *
 * It signs the request with WAWEB_SECRET from the environment or the .env
 * file. The QR of a session is replaced by a note: linking is done from
 * ChatManager, not from a terminal.
 */
async function main(): Promise<void> {
    loadEnvFile(resolve(process.env.WAWEB_ENV_FILE ?? '.env'));

    const [method = 'GET', path = '/v1/status', body = ''] =
        process.argv.slice(2);
    const secret = (process.env.WAWEB_SECRET ?? '').trim();
    const base = `http://${process.env.WAWEB_HOST ?? '127.0.0.1'}:${process.env.WAWEB_PORT ?? '3410'}`;

    if (secret === '') {
        process.stderr.write('WAWEB_SECRET is not set.\n');
        process.exit(78);
    }

    const response = await fetch(base + path, {
        method: method.toUpperCase(),
        headers: {
            'Content-Type': 'application/json',
            ...signedHeaders(secret, method, path, body),
        },
        ...(body === '' ? {} : { body }),
    });
    const text = await response.text();
    let output = text;

    try {
        output = JSON.stringify(
            JSON.parse(text),
            (key, value: unknown) =>
                key === 'qr' && value !== null && !process.env.WAWEB_CLI_SHOW_QR
                    ? '[hidden]'
                    : value,
            2,
        );
    } catch {
        // Not JSON (a media stream or an empty answer).
    }

    process.stdout.write(`${response.status}\n${output}\n`);
    process.exit(response.ok ? 0 : 1);
}

void main();

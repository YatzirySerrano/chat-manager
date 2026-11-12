import { existsSync, readFileSync } from 'node:fs';
import { resolve } from 'node:path';

/**
 * Configuration of the service, read once from the environment (see
 * docs/whatsapp-web-qr-contrato.md §9). Secrets are never logged.
 */
export type Config = {
    host: string;
    port: number;
    /** Shared secret of the internal HMAC signature. */
    secret: string;
    laravelUrl: string;
    dataDir: string;
    /** 32 bytes used to encrypt sessions, the outbox and the sent store at rest. */
    sessionKey: Buffer;
    logLevel: string;
    libraryLogLevel: string;
    qrMaxRefresh: number;
    maxMediaBytes: number;
    reconnectMaxAttempts: number;
    sendTimeoutMs: number;
    eventTimeoutMs: number;
};

export class ConfigError extends Error {}

/**
 * Loads KEY=VALUE lines of an env file into the environment without
 * replacing variables that are already set (the process manager wins).
 */
export function loadEnvFile(
    path: string,
    env: NodeJS.ProcessEnv = process.env,
): void {
    if (!existsSync(path)) {
        return;
    }

    for (const line of readFileSync(path, 'utf8').split(/\r?\n/)) {
        if (line.trimStart().startsWith('#')) {
            continue;
        }

        const match = /^\s*([A-Za-z_][A-Za-z0-9_]*)\s*=\s*(.*)\s*$/.exec(line);

        if (!match) {
            continue;
        }

        const key = match[1] as string;
        let value = (match[2] ?? '').trim();
        const quoted =
            (value.startsWith('"') && value.endsWith('"')) ||
            (value.startsWith("'") && value.endsWith("'"));

        if (quoted && value.length >= 2) {
            value = value.slice(1, -1);
        }

        if (env[key] === undefined) {
            env[key] = value;
        }
    }
}

function integer(
    env: NodeJS.ProcessEnv,
    key: string,
    fallback: number,
    min: number,
    max: number,
): number {
    const raw = env[key];

    if (raw === undefined || raw.trim() === '') {
        return fallback;
    }

    const value = Number(raw);

    if (!Number.isInteger(value) || value < min || value > max) {
        throw new ConfigError(
            `${key} must be an integer between ${min} and ${max}.`,
        );
    }

    return value;
}

export function loadConfig(env: NodeJS.ProcessEnv = process.env): Config {
    const host = (env.WAWEB_HOST ?? '127.0.0.1').trim() || '127.0.0.1';
    const secret = (env.WAWEB_SECRET ?? '').trim();
    const laravelUrl = (env.WAWEB_LARAVEL_URL ?? '').trim().replace(/\/+$/, '');
    const dataDir = (env.WAWEB_DATA_DIR ?? '').trim();
    const rawKey = (env.WAWEB_SESSION_KEY ?? '').trim();

    if (secret.length < 32) {
        throw new ConfigError(
            'WAWEB_SECRET must have at least 32 characters (the same value as WHATSAPP_WEB_SECRET in Laravel).',
        );
    }

    if (!/^https?:\/\/[^/]+/.test(laravelUrl)) {
        throw new ConfigError(
            'WAWEB_LARAVEL_URL must be the base URL of Laravel (http://127.0.0.1 or https://domain).',
        );
    }

    if (dataDir === '') {
        throw new ConfigError(
            'WAWEB_DATA_DIR is required (a private directory outside the releases).',
        );
    }

    const sessionKey = Buffer.from(rawKey, 'base64');

    if (sessionKey.length !== 32) {
        throw new ConfigError(
            'WAWEB_SESSION_KEY must be 32 bytes in base64 (openssl rand -base64 32).',
        );
    }

    return {
        host,
        port: integer(env, 'WAWEB_PORT', 3410, 1, 65535),
        secret,
        laravelUrl,
        dataDir: resolve(dataDir),
        sessionKey,
        logLevel: (env.WAWEB_LOG_LEVEL ?? 'info').trim() || 'info',
        libraryLogLevel:
            (env.WAWEB_LIBRARY_LOG_LEVEL ?? 'silent').trim() || 'silent',
        qrMaxRefresh: integer(env, 'WAWEB_QR_MAX_REFRESH', 5, 0, 50),
        maxMediaBytes: integer(
            env,
            'WAWEB_MAX_MEDIA_BYTES',
            100 * 1024 * 1024,
            1024,
            2 * 1024 * 1024 * 1024,
        ),
        reconnectMaxAttempts: integer(
            env,
            'WAWEB_RECONNECT_MAX_ATTEMPTS',
            12,
            1,
            100,
        ),
        sendTimeoutMs: integer(
            env,
            'WAWEB_SEND_TIMEOUT_MS',
            30_000,
            1000,
            300_000,
        ),
        eventTimeoutMs: integer(
            env,
            'WAWEB_EVENT_TIMEOUT_MS',
            15_000,
            1000,
            120_000,
        ),
    };
}

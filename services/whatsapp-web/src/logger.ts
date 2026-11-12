import { pino, type Logger } from 'pino';

export type { Logger };

/**
 * Structured logs. Nothing here may carry credentials, QR values, message
 * bodies or complete phone numbers: callers pass masked identifiers
 * (maskPhone / maskJid) and the redaction list is a second barrier for
 * objects logged by mistake.
 */
export function createLogger(level: string): Logger {
    return pino({
        level,
        base: { service: 'chatmanager-whatsapp-web' },
        timestamp: pino.stdTimeFunctions.isoTime,
        redact: {
            paths: [
                'qr',
                '*.qr',
                'secret',
                '*.secret',
                'creds',
                '*.creds',
                'keys',
                '*.keys',
                'descriptor',
                '*.descriptor',
                'mediaKey',
                '*.mediaKey',
                'text',
                '*.text',
                'body',
                '*.body',
                'headers',
                '*.headers',
                'signature',
                '*.signature',
            ],
            censor: '[redacted]',
        },
    });
}

/** "5215512345678" -> "*********5678". */
export function maskPhone(value: string | null | undefined): string | null {
    if (!value) {
        return null;
    }

    const digits = value.replace(/\D/g, '');

    return digits.length <= 4
        ? '****'
        : '*'.repeat(digits.length - 4) + digits.slice(-4);
}

/** "5215512345678@s.whatsapp.net" -> "*********5678@s.whatsapp.net". */
export function maskJid(jid: string | null | undefined): string | null {
    if (!jid) {
        return null;
    }

    const at = jid.indexOf('@');
    const user = at === -1 ? jid : jid.slice(0, at);
    const server = at === -1 ? '' : jid.slice(at);
    const bare = user.split(':')[0] ?? user;

    return (
        (bare.length <= 4
            ? '****'
            : '*'.repeat(bare.length - 4) + bare.slice(-4)) + server
    );
}

/** First line of an error, with anything that looks like a number masked. */
export function safeErrorMessage(error: unknown): string {
    const raw = error instanceof Error ? error.message : String(error);

    return (raw.split('\n')[0] ?? '')
        .replace(/[0-9]{7,}/g, (match) => maskPhone(match) ?? '****')
        .slice(0, 200);
}

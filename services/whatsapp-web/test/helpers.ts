import { randomBytes } from 'node:crypto';
import { mkdtemp, rm } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { Readable } from 'node:stream';
import type { WAMessage } from 'baileys';
import { pino } from 'pino';
import {
    createService,
    type Service,
    type ServiceDependencies,
} from '../src/app.js';
import type { Config } from '../src/config.js';
import type { DeliveryResult } from '../src/outbox.js';
import { signedHeaders } from '../src/signing.js';
import type { EventEnvelope } from '../src/types.js';
import type {
    WaConnection,
    WaEvent,
    WaFactory,
    WaOutgoing,
    WaQuoted,
} from '../src/wa.js';

export const SECRET = 'test-secret-0123456789abcdef0123456789';

export const silentLogger = pino({ level: 'silent' });

export async function temporaryDirectory(): Promise<{
    path: string;
    remove: () => Promise<void>;
}> {
    const path = await mkdtemp(join(tmpdir(), 'cm-waweb-'));

    return {
        path,
        remove: () =>
            rm(path, {
                recursive: true,
                force: true,
                maxRetries: 5,
                retryDelay: 20,
            }),
    };
}

export function testConfig(
    dataDir: string,
    overrides: Partial<Config> = {},
): Config {
    return {
        host: '127.0.0.1',
        port: 0,
        secret: SECRET,
        laravelUrl: 'http://127.0.0.1:1',
        dataDir,
        sessionKey: randomBytes(32),
        logLevel: 'silent',
        libraryLogLevel: 'silent',
        qrMaxRefresh: 2,
        maxMediaBytes: 1024 * 1024,
        reconnectMaxAttempts: 3,
        sendTimeoutMs: 200,
        eventTimeoutMs: 1000,
        ...overrides,
    };
}

/** One socket opened by the service: the test plays WhatsApp through it. */
export class FakeSocket {
    closed = false;
    loggedOut = false;
    readonly sentMessages: {
        jid: string;
        content: WaOutgoing;
        messageId: string;
        quoted: WaQuoted | null;
    }[] = [];
    /** Replace to make a send hang or fail. */
    sendBehaviour: (
        messageId: string,
    ) => Promise<{ id: string; timestamp: number }> = async (messageId) => ({
        id: messageId,
        timestamp: 1_700_000_000,
    });
    /** Numbers WhatsApp knows, with the JID it registered for each. */
    readonly directory = new Map<string, string>();

    constructor(
        readonly registered: boolean,
        private readonly emitEvent: (event: WaEvent) => void,
    ) {}

    emit(event: WaEvent): void {
        this.emitEvent(event);
    }

    readonly connection: WaConnection = {
        send: async (jid, content, { messageId, quoted }) => {
            this.sentMessages.push({ jid, content, messageId, quoted });

            return this.sendBehaviour(messageId);
        },
        resolveJid: async (digits) => this.directory.get(digits) ?? null,
        phoneForLid: async () => null,
        logout: async () => {
            this.loggedOut = true;
        },
        close: () => {
            this.closed = true;
        },
    };
}

/** A WhatsApp double: records every socket the service opens. */
export class FakeWhatsApp {
    readonly sockets: FakeSocket[] = [];
    /** Set to make the next socket fail to open. */
    failNextOpen: Error | null = null;

    readonly factory: WaFactory = async ({ auth, onEvent }) => {
        if (this.failNextOpen !== null) {
            const error = this.failNextOpen;

            this.failNextOpen = null;

            throw error;
        }

        const socket = new FakeSocket(auth.isRegistered(), onEvent);

        // The real library writes credentials as soon as it connects.
        await auth.saveCreds();
        this.sockets.push(socket);

        return socket.connection;
    };

    get last(): FakeSocket {
        const socket = this.sockets.at(-1);

        if (!socket) {
            throw new Error('No socket was opened');
        }

        return socket;
    }
}

/** Timers of the sessions, run by hand. */
export class ManualTimers {
    private readonly tasks: {
        task: () => void;
        ms: number;
        cancelled: boolean;
    }[] = [];

    readonly schedule = (task: () => void, ms: number): (() => void) => {
        const entry = { task, ms, cancelled: false };

        this.tasks.push(entry);

        return () => {
            entry.cancelled = true;
        };
    };

    get delays(): number[] {
        return this.tasks.map((entry) => entry.ms);
    }

    get pending(): number {
        return this.tasks.filter((entry) => !entry.cancelled).length;
    }

    /** Runs the oldest pending timer. */
    runNext(): boolean {
        const index = this.tasks.findIndex((entry) => !entry.cancelled);

        if (index === -1) {
            return false;
        }

        const [entry] = this.tasks.splice(index, 1);

        entry?.task();

        return true;
    }
}

export type Harness = {
    service: Service;
    whatsapp: FakeWhatsApp;
    timers: ManualTimers;
    /** Events Laravel acknowledged, in order. */
    events: EventEnvelope[];
    /** What the fake Laravel answers to the next deliveries. */
    laravel: { answer: DeliveryResult | (() => DeliveryResult) };
    config: Config;
    port: number;
    call: (
        method: string,
        path: string,
        body?: unknown,
        options?: { sign?: boolean; headers?: Record<string, string> },
    ) => Promise<{ status: number; json: any; headers: Headers; raw: Buffer }>;
    settle: () => Promise<void>;
    close: () => Promise<void>;
};

/** Polls until the condition holds (I/O finishes when it finishes). */
export async function until(
    condition: () => boolean | Promise<boolean>,
    timeoutMs = 3000,
): Promise<void> {
    const deadline = Date.now() + timeoutMs;

    while (!(await condition())) {
        if (Date.now() > deadline) {
            throw new Error('The expected condition was never met');
        }

        await new Promise((resolve) => setTimeout(resolve, 3));
    }
}

const running = new Set<Service>();

/** Waits until every running service finished processing what it was given. */
export const flush = async (rounds = 3): Promise<void> => {
    for (let round = 0; round < rounds; round++) {
        await new Promise((resolve) => setImmediate(resolve));
        await Promise.all(
            [...running].map((service) => service.manager.idle()),
        );
    }
};

export async function startHarness(
    options: {
        dataDir?: string;
        config?: Partial<Config>;
        dependencies?: Partial<ServiceDependencies>;
        sessionKey?: Buffer;
    } = {},
): Promise<Harness & { dataDir: string; removeData: () => Promise<void> }> {
    const temporary = options.dataDir
        ? {
              path: options.dataDir,
              remove: () =>
                  rm(options.dataDir as string, {
                      recursive: true,
                      force: true,
                  }),
          }
        : await temporaryDirectory();
    const config = testConfig(temporary.path, {
        ...(options.sessionKey ? { sessionKey: options.sessionKey } : {}),
        ...options.config,
    });
    const whatsapp = new FakeWhatsApp();
    const timers = new ManualTimers();
    const events: EventEnvelope[] = [];
    const laravel: Harness['laravel'] = { answer: 'ok' };

    const service = await createService({
        config,
        logger: silentLogger,
        factory: whatsapp.factory,
        downloadMedia: async () => Readable.from([Buffer.from('file-bytes')]),
        deliver: async (envelope) => {
            const answer =
                typeof laravel.answer === 'function'
                    ? laravel.answer()
                    : laravel.answer;

            if (answer === 'ok') {
                events.push(envelope);
            }

            return answer;
        },
        fetchMedia: async () => ({
            path: join(temporary.path, 'outgoing-file'),
            mime: 'image/jpeg',
            size: 10,
            cleanup: async () => undefined,
        }),
        session: {
            schedule: timers.schedule,
            random: () => 0.5,
            now: () => 1_700_000_000_000,
        },
        outbox: {
            retryDelay: () => 1,
            sleep: () => new Promise((resolve) => setTimeout(resolve, 1)),
        },
        ...options.dependencies,
    });
    const port = await service.listen();

    running.add(service);

    const call: Harness['call'] = async (
        method,
        path,
        body,
        callOptions = {},
    ) => {
        const payload = body === undefined ? '' : JSON.stringify(body);
        const response = await fetch(`http://127.0.0.1:${port}${path}`, {
            method,
            headers: {
                'Content-Type': 'application/json',
                ...(callOptions.sign === false
                    ? {}
                    : signedHeaders(SECRET, method, path, payload)),
                ...callOptions.headers,
            },
            ...(payload === '' ? {} : { body: payload }),
        });
        const raw = Buffer.from(await response.arrayBuffer());
        let json: unknown = null;

        try {
            json = JSON.parse(raw.toString('utf8'));
        } catch {
            // Binary answer.
        }

        return {
            status: response.status,
            json,
            headers: response.headers,
            raw,
        };
    };

    return {
        service,
        whatsapp,
        timers,
        events,
        laravel,
        config,
        port,
        call,
        settle: async () => {
            await flush();
            await service.outbox.idle();
            await flush();
        },
        close: async () => {
            running.delete(service);
            await service.close();
        },
        dataDir: temporary.path,
        removeData: temporary.remove,
    };
}

/** Links connection `id` end to end: QR, scan, restart asked by WhatsApp, open. */
export async function linkSession(
    harness: Harness,
    id: number,
    phone = '5215512345678',
): Promise<FakeSocket> {
    await harness.call('POST', `/v1/sessions/${id}/start`, { mode: 'link' });
    harness.whatsapp.last.emit({ type: 'qr', value: 'qr-1', ttlMs: 60_000 });
    harness.whatsapp.last.emit({ type: 'pairing' });
    harness.whatsapp.last.emit({
        type: 'close',
        statusCode: 515,
        detail: 'restart required',
    });
    await flush();
    harness.whatsapp.last.emit({
        type: 'open',
        me: { jid: `${phone}:3@s.whatsapp.net`, name: 'Central' },
    });
    await harness.settle();

    return harness.whatsapp.last;
}

export function textMessage(
    id: string,
    jid: string,
    body: string,
    options: { fromMe?: boolean; timestamp?: number; pushName?: string } = {},
): WAMessage {
    return {
        key: { id, remoteJid: jid, fromMe: options.fromMe ?? false },
        message: { conversation: body },
        messageTimestamp: options.timestamp ?? 1_700_000_050,
        pushName: options.pushName ?? 'María',
    };
}
